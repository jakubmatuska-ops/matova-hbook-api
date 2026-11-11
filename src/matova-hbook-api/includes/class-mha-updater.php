<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One-click self-update from a public GitHub repo, so new versions install
 * straight from the wp-admin Plugins page (WordPress' own updater) — no FTP,
 * no FPM-wedge. The repo hosts a tiny manifest and the folder-rooted zip:
 *
 *   https://raw.githubusercontent.com/<REPO>/<BRANCH>/update.json
 *     { "version":"2.3.0",
 *       "download_url":"https://raw.githubusercontent.com/<REPO>/<BRANCH>/matova-hbook-api.zip",
 *       "requires":"5.8", "tested":"6.7", "changelog":"..." }
 *
 * The zip must contain a top-level `matova-hbook-api/` folder (as our build
 * does), so WordPress replaces the plugin in place.
 */
class MHA_Updater {

    // Set once the repo exists. Public repo so no token is needed in the plugin.
    const REPO   = 'jakubmatuska-ops/matova-hbook-api';
    const BRANCH = 'main';
    const SLUG   = 'matova-hbook-api';                      // plugin folder
    const FILE   = 'matova-hbook-api/matova-hbook-api.php'; // plugin basename

    /**
     * Ed25519 PUBLIC key of the Matova release signer (base64, 32 bytes).
     * The matching private key is held offline by the owner and never touches
     * GitHub or any server. A release is installable only if `update.json`
     * carries a detached signature of the exact ZIP bytes made with that key —
     * so write access to the repository alone can no longer ship code to the
     * resort sites. The signature covers plugin slug + version + SHA-256 of the ZIP. Override with the MHA_UPDATE_PUBKEY constant (wp-config).
     */
    const PUBKEY_B64 = '';   // set by the release process from the managed signer (see docs/RELEASE_SIGNING.md)
    /** Optional NEXT key, shipped one release ahead so the signer can rotate
     *  without any site ever being unable to verify an update. */
    const PUBKEY_NEXT_B64 = '';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject']);
        add_filter('plugins_api', [__CLASS__, 'info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'rename_source'], 10, 4);
        add_filter('upgrader_pre_download', [__CLASS__, 'verified_download'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'flush']);
    }

    private static function manifest_url() {
        return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/update.json';
    }

    public static function flush() { delete_transient('mha_update_manifest'); }

    /** Fetch + cache the remote manifest (6h on success, 3h on failure). */
    private static function manifest() {
        $cached = get_transient('mha_update_manifest');
        if (is_array($cached)) { return $cached; }
        if ($cached === 'none') { return null; }

        $resp = wp_remote_get(self::manifest_url(), [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'matova-hbook-api'],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient('mha_update_manifest', 'none', 3 * HOUR_IN_SECONDS);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
            set_transient('mha_update_manifest', 'none', 3 * HOUR_IN_SECONDS);
            return null;
        }
        set_transient('mha_update_manifest', $data, 1 * HOUR_IN_SECONDS);
        return $data;
    }

    private static function current() { return defined('MHA_VERSION') ? MHA_VERSION : '0'; }

    public static function inject($transient) {
        if (!is_object($transient)) { return $transient; }
        $m = self::manifest();
        if (!$m) { return $transient; }

        $cur = self::current();
        if (version_compare((string) $m['version'], $cur, '>')) {
            if (!isset($transient->response)) { $transient->response = []; }
            $transient->response[self::FILE] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => self::FILE,
                'new_version' => (string) $m['version'],
                'package'     => (string) $m['download_url'],
                'url'         => 'https://github.com/' . self::REPO,
                'tested'      => isset($m['tested']) ? (string) $m['tested'] : '',
                'requires'    => isset($m['requires']) ? (string) $m['requires'] : '',
            ];
            if (isset($transient->no_update[self::FILE])) { unset($transient->no_update[self::FILE]); }
        } else {
            if (!isset($transient->no_update)) { $transient->no_update = []; }
            $transient->no_update[self::FILE] = (object) [
                'slug' => self::SLUG, 'plugin' => self::FILE, 'new_version' => $cur,
                'package' => '', 'url' => 'https://github.com/' . self::REPO,
            ];
        }
        return $transient;
    }

    public static function info($res, $action, $args) {
        if ($action !== 'plugin_information') { return $res; }
        if (empty($args->slug) || $args->slug !== self::SLUG) { return $res; }
        $m = self::manifest();
        if (!$m) { return $res; }
        return (object) [
            'name'          => 'Matova HBook API',
            'slug'          => self::SLUG,
            'version'       => (string) $m['version'],
            'author'        => 'Matova Group',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => (string) $m['download_url'],
            'requires'      => isset($m['requires']) ? (string) $m['requires'] : '',
            'tested'        => isset($m['tested']) ? (string) $m['tested'] : '',
            'sections'      => [
                'description' => 'Unified HBook REST API for Matova Group resorts (matova/v2).',
                'changelog'   => isset($m['changelog']) ? (string) $m['changelog'] : '',
            ],
        ];
    }

    /**
     * Our zip is already folder-rooted (matova-hbook-api/…), so this is just a
     * safety net: if a download ever extracts to a suffixed folder, rename it to
     * the plugin slug so WordPress overwrites in place instead of making a copy.
     */
    public static function rename_source($source, $remote_source, $upgrader, $extra = []) {
        if (empty($extra['plugin']) || $extra['plugin'] !== self::FILE) { return $source; }
        if (!function_exists('trailingslashit')) { return $source; }
        $desired = trailingslashit($remote_source) . self::SLUG . '/';
        if (trailingslashit($source) === $desired) { return $source; }
        if (is_dir($source) && @rename($source, $desired)) { return $desired; }
        return $source;
    }

    /** Every key an update may be signed with (current, next, wp-config override). */
    public static function pubkeys() {
        $out = [];
        $cands = [self::PUBKEY_B64, self::PUBKEY_NEXT_B64];
        if (defined('MHA_UPDATE_PUBKEY')) { $cands[] = (string) MHA_UPDATE_PUBKEY; }
        foreach ($cands as $b64) {
            if (!is_string($b64) || $b64 === '') { continue; }
            $raw = base64_decode($b64, true);
            if (is_string($raw) && strlen($raw) === 32) { $out[] = $raw; }
        }
        return $out;
    }

    /** True when $signature_b64 verifies under ANY trusted key. No keys ⇒ false. */
    public static function signature_trusted($message, $signature_b64, array $keys = null) {
        $keys = $keys === null ? self::pubkeys() : $keys;
        foreach ($keys as $k) {
            if (self::signature_valid($message, $signature_b64, $k)) { return true; }
        }
        return false;
    }

    /**
     * Pure check: is $signature_b64 a valid detached Ed25519 signature of
     * $bytes under $pubkey? Fails closed on any malformed input or when
     * libsodium is unavailable.
     */
    public static function signature_valid($bytes, $signature_b64, $pubkey) {
        if (!is_string($bytes) || $bytes === '' || !is_string($signature_b64) || !is_string($pubkey)) { return false; }
        if (!function_exists('sodium_crypto_sign_verify_detached')) { return false; }
        $sig = base64_decode(trim($signature_b64), true);
        if (!is_string($sig) || strlen($sig) !== 64 || strlen($pubkey) !== 32) { return false; }
        try {
            return (bool) sodium_crypto_sign_verify_detached($sig, $bytes, $pubkey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The exact message that is signed: binds plugin, version and ZIP bytes,
     *  so a signed ZIP cannot be replayed under another version number. */
    public static function signed_message($version, $zip_bytes) {
        return self::SLUG . "\n" . (string) $version . "\n" . hash('sha256', (string) $zip_bytes);
    }

    /**
     * `upgrader_pre_download`: take over the download of OUR plugin.
     *
     * "Ours" is decided by WHAT is being upgraded (`$hook_extra['plugin']`),
     * never by the URL alone — so there is no input for which our plugin could
     * be installed without verification. From there everything fails CLOSED:
     * manifest unavailable, package URL not the manifest's, no signature, bad
     * signature, no libsodium, unreadable file ⇒ WP_Error, the installed
     * version stays. Other plugins' downloads are untouched ($reply returned).
     */
    public static function verified_download($reply, $package, $upgrader = null, $hook_extra = []) {
        $m = self::manifest();
        $is_ours = (is_array($hook_extra) && isset($hook_extra['plugin']) && $hook_extra['plugin'] === self::FILE)
            || (is_array($m) && !empty($m['download_url']) && is_string($package) && $package === (string) $m['download_url']);
        if (!$is_ours) { return $reply; }

        if (!is_array($m) || empty($m['download_url']) || empty($m['version'])) {
            return new WP_Error('mha_update_no_manifest', 'Matova HBook API: údaje o aktualizaci nejsou dostupné — instalace zrušena.');
        }
        if (!is_string($package) || $package !== (string) $m['download_url']) {
            return new WP_Error('mha_update_wrong_package', 'Matova HBook API: balíček neodpovídá vydané aktualizaci — instalace zrušena.');
        }
        $keys = self::pubkeys();
        $sig = isset($m['signature']) ? (string) $m['signature'] : '';
        if (!$keys || $sig === '') {
            return new WP_Error('mha_update_unsigned', 'Matova HBook API: aktualizace není podepsaná — instalace zrušena.');
        }
        if (!function_exists('download_url')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $tmp = download_url($package, 60);
        if (is_wp_error($tmp)) { return $tmp; }
        $bytes = @file_get_contents($tmp);
        if (!is_string($bytes) || $bytes === ''
            || !self::signature_trusted(self::signed_message($m['version'], $bytes), $sig, $keys)) {
            @unlink($tmp);
            return new WP_Error('mha_update_bad_signature', 'Matova HBook API: podpis aktualizace nesouhlasí — instalace zrušena.');
        }
        return $tmp;
    }
}
