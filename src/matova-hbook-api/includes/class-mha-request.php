<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Request parameter validators for matova/v2.
 *
 * Each validator returns the parsed value, or a WP_Error('bad_request', ...).
 * Controllers call these and bail out of wrap() on the first WP_Error.
 */
class MHA_Request {

    private static function err($message, array $details = []) {
        return new WP_Error('bad_request', $message, $details);
    }

    /** Y-m-d date. Returns the normalized string, null when optional+absent, or WP_Error. */
    public static function date_param(WP_REST_Request $req, $name, $required = false) {
        $raw = $req->get_param($name);
        if ($raw === null || $raw === '') {
            if ($required) { return self::err(sprintf('Missing required date "%s".', $name)); }
            return null;
        }
        $raw = (string) $raw;
        $d = DateTime::createFromFormat('!Y-m-d', $raw);
        $ok = $d && $d->format('Y-m-d') === $raw;
        if (!$ok) {
            return self::err(sprintf('Invalid date "%s"; expected YYYY-MM-DD.', $name), ['value' => $raw]);
        }
        return $raw;
    }

    /** Integer with optional bounds. */
    public static function int_param(WP_REST_Request $req, $name, $required = false, $min = null, $max = null) {
        $raw = $req->get_param($name);
        if ($raw === null || $raw === '') {
            if ($required) { return self::err(sprintf('Missing required integer "%s".', $name)); }
            return null;
        }
        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw && !is_int($raw)) {
            // Accept clean integer strings only.
            if (!preg_match('/^-?\d+$/', (string) $raw)) {
                return self::err(sprintf('Invalid integer "%s".', $name), ['value' => $raw]);
            }
        }
        $v = (int) $raw;
        if ($min !== null && $v < $min) {
            return self::err(sprintf('"%s" must be >= %d.', $name, $min), ['value' => $v]);
        }
        if ($max !== null && $v > $max) {
            return self::err(sprintf('"%s" must be <= %d.', $name, $max), ['value' => $v]);
        }
        return $v;
    }

    /** Value constrained to an allow-list. */
    public static function enum_param(WP_REST_Request $req, $name, array $allowed, $required = false, $default = null) {
        $raw = $req->get_param($name);
        if ($raw === null || $raw === '') {
            if ($required) { return self::err(sprintf('Missing required "%s".', $name)); }
            return $default;
        }
        $raw = (string) $raw;
        if (!in_array($raw, $allowed, true)) {
            return self::err(sprintf('Invalid value for "%s".', $name), ['value' => $raw, 'allowed' => $allowed]);
        }
        return $raw;
    }

    /** Free-text string with a length cap; trimmed. */
    public static function string_param(WP_REST_Request $req, $name, $required = false, $max_len = 2000) {
        $raw = $req->get_param($name);
        if ($raw === null || $raw === '') {
            if ($required) { return self::err(sprintf('Missing required "%s".', $name)); }
            return null;
        }
        if (!is_scalar($raw)) {
            return self::err(sprintf('"%s" must be a string.', $name));
        }
        $s = trim((string) $raw);
        if ($s === '' && $required) {
            return self::err(sprintf('"%s" must not be empty.', $name));
        }
        if (strlen($s) > $max_len) {
            return self::err(sprintf('"%s" exceeds %d chars.', $name, $max_len));
        }
        return $s;
    }

    /**
     * Pagination. Returns [limit, offset]; limit capped at 200, default 50; offset >= 0.
     */
    public static function pagination(WP_REST_Request $req, $default_limit = 50) {
        $limit = $req->get_param('limit');
        $offset = $req->get_param('offset');

        $limit = ($limit === null || $limit === '') ? $default_limit : (int) $limit;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 200) { $limit = 200; }

        $offset = ($offset === null || $offset === '') ? 0 : (int) $offset;
        if ($offset < 0) { $offset = 0; }

        return [$limit, $offset];
    }

    /** Idempotency key from the header. Empty string when absent. */
    public static function idempotency_key(WP_REST_Request $req) {
        $k = $req->get_header('idempotency-key');
        if (!$k && isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            $k = $_SERVER['HTTP_IDEMPOTENCY_KEY'];
        }
        return $k ? trim((string) $k) : '';
    }
}
