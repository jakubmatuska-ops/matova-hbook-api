<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Backend-to-backend auth for matova/v2.
 *
 * - Bearer token in the Authorization header ONLY. Query-string keys are rejected.
 * - Keys stored as salted SHA-256 hashes in option 'mha_keys' (raw key never stored).
 * - Per consumer x resort x scope; constant-time compare (hash_equals) over active records.
 * - Scopes: read < ops-write < admin. Revocation = active:false. Rotation = multiple active keys.
 * - Optional WP Application Password (manage_options) maps to 'admin'.
 * - Per-key token-bucket rate limit in a transient.
 *
 * No custom cryptography: opaque random bearer token over TLS, hashed at rest,
 * constant-time comparison. Nothing is invented beyond standard primitives.
 */
class MHA_Auth {

    const OPT_KEYS = 'mha_keys';     // array of records: id,label,consumer,scope,resort,hash,created,last_used,active
    const OPT_SALT = 'mha_key_salt'; // per-site random salt for key hashing

    const SCOPE_RANK = ['read' => 1, 'ops-write' => 2, 'admin' => 3];

    /** The authenticated principal for the current request, set by authorize(). */
    private static $ctx = null;

    public static function context() { return self::$ctx; }

    public static function salt() {
        $salt = get_option(self::OPT_SALT);
        if (!$salt) {
            $salt = bin2hex(random_bytes(32));
            add_option(self::OPT_SALT, $salt, '', 'no');
        }
        return $salt;
    }

    public static function hash_key($raw) {
        return hash('sha256', self::salt() . '|' . $raw);
    }

    /** Extract a bearer token from the Authorization header only. Never from query/body. */
    private static function bearer(WP_REST_Request $req) {
        $h = $req->get_header('authorization');
        if (!$h && isset($_SERVER['HTTP_AUTHORIZATION'])) { $h = $_SERVER['HTTP_AUTHORIZATION']; }
        if (!$h) { return ''; }
        if (stripos($h, 'bearer ') === 0) { return trim(substr($h, 7)); }
        return '';
    }

    /**
     * permission_callback factory. $required_scope ∈ read|ops-write|admin.
     * Returns true on success (and stashes context), or a WP_Error that REST turns into 401/403.
     */
    public static function require_scope($required_scope) {
        return function (WP_REST_Request $req) use ($required_scope) {
            // Reject any attempt to pass a key via the URL — defense in depth.
            if ($req->get_param('api_key') !== null || $req->get_param('key') !== null) {
                return new WP_Error('forbidden', 'API keys must not be sent in the URL; use Authorization: Bearer.', ['status' => 403]);
            }

            $principal = self::resolve($req);
            if (is_wp_error($principal)) { return $principal; }

            if (!self::scope_allows($principal['scope'], $required_scope)) {
                return new WP_Error('forbidden', 'Insufficient scope for this operation.', ['status' => 403]);
            }

            // Rate limit per key id.
            $rl = self::rate_limit_ok($principal['id']);
            if ($rl !== true) {
                return new WP_Error('rate_limited', 'Rate limit exceeded.', ['status' => 429, 'retry_after' => $rl]);
            }

            self::$ctx = $principal;
            return true;
        };
    }

    public static function scope_allows($have, $need) {
        $h = self::SCOPE_RANK[$have] ?? 0;
        $n = self::SCOPE_RANK[$need] ?? 99;
        return $h >= $n;
    }

    /**
     * Identify the caller. Returns principal array {id,consumer,scope,resort} or WP_Error.
     */
    private static function resolve(WP_REST_Request $req) {
        $token = self::bearer($req);

        if ($token !== '') {
            if (strlen($token) < MHA_MIN_KEY_LEN) {
                return new WP_Error('unauthorized', 'Invalid credentials.', ['status' => 401]);
            }
            $presented = self::hash_key($token);
            $records = get_option(self::OPT_KEYS, []);
            if (!is_array($records)) { $records = []; }
            $match = null;
            // Constant-time: compare against every active record, no early break on mismatch.
            foreach ($records as $rec) {
                if (empty($rec['active']) || empty($rec['hash'])) { continue; }
                if (hash_equals((string) $rec['hash'], $presented)) { $match = $rec; }
            }
            if ($match) {
                self::touch_last_used($match['id']);
                return [
                    'id'       => $match['id'],
                    'consumer' => $match['consumer'] ?? $match['id'],
                    'scope'    => $match['scope'] ?? 'read',
                    'resort'   => $match['resort'] ?? MHA_Config::resort_id(),
                ];
            }
            return new WP_Error('unauthorized', 'Invalid credentials.', ['status' => 401]);
        }

        // Fallback: a logged-in admin (e.g. Application Password) maps to admin scope.
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return ['id' => 'wp-admin', 'consumer' => 'wp-admin', 'scope' => 'admin', 'resort' => MHA_Config::resort_id()];
        }

        return new WP_Error('unauthorized', 'Authentication required (Authorization: Bearer <key>).', ['status' => 401]);
    }

    private static function touch_last_used($id) {
        $records = get_option(self::OPT_KEYS, []);
        if (!is_array($records)) { return; }
        foreach ($records as &$rec) {
            if (($rec['id'] ?? null) === $id) { $rec['last_used'] = gmdate('c'); }
        }
        unset($rec);
        update_option(self::OPT_KEYS, $records, false);
    }

    /** Token bucket per key id. Returns true, or an int seconds-to-retry. */
    private static function rate_limit_ok($key_id) {
        $per_min = defined('MHA_RATE_PER_MIN') ? (int) MHA_RATE_PER_MIN : 120;
        if ($per_min <= 0) { return true; }
        $bucket_key = 'mha_rl_' . md5((string) $key_id);
        $now = time();
        $state = get_transient($bucket_key);
        if (!is_array($state)) { $state = ['count' => 0, 'window' => $now]; }
        if ($now - $state['window'] >= 60) { $state = ['count' => 0, 'window' => $now]; }
        $state['count']++;
        set_transient($bucket_key, $state, 120);
        if ($state['count'] > $per_min) { return max(1, 60 - ($now - $state['window'])); }
        return true;
    }

    /**
     * Issue a new key (admin/CLI helper). Stores the hash, returns the raw key ONCE.
     */
    public static function issue_key($consumer, $scope, $resort, $label = '') {
        if (!isset(self::SCOPE_RANK[$scope])) { return new WP_Error('bad_request', 'Unknown scope'); }
        $raw = 'mha_' . bin2hex(random_bytes(24)); // >= 32 chars
        $records = get_option(self::OPT_KEYS, []);
        if (!is_array($records)) { $records = []; }
        $records[] = [
            'id'        => 'k_' . bin2hex(random_bytes(6)),
            'label'     => $label ?: $consumer . ':' . $scope,
            'consumer'  => $consumer,
            'scope'     => $scope,
            'resort'    => $resort,
            'hash'      => self::hash_key($raw),
            'created'   => gmdate('c'),
            'last_used' => null,
            'active'    => true,
        ];
        update_option(self::OPT_KEYS, $records, false);
        return $raw; // caller must display once; it is not recoverable afterwards.
    }

    public static function revoke_key($id) {
        $records = get_option(self::OPT_KEYS, []);
        if (!is_array($records)) { return false; }
        $found = false;
        foreach ($records as &$rec) {
            if (($rec['id'] ?? null) === $id) { $rec['active'] = false; $found = true; }
        }
        unset($rec);
        update_option(self::OPT_KEYS, $records, false);
        return $found;
    }
}
