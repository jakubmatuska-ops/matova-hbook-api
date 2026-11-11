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
    const PUBKEY_B64 = '6sLtMy3UNuDCt8cCc53icw7rEmnoX7GaTiEPtxz5xaI=';   // set by the release process from the managed signer (see docs/RELEASE_SIGNING.md)
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

    /** This site's host without "www." — the key used by staged rollouts. */
    public static function site_host() {
        $h = function_exists('wp_parse_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : parse_url(home_url(), PHP_URL_HOST);
        return preg_replace('/^www\./', '', strtolower((string) $h));
    }

    /**
     * The release THIS site should run, or null for "nothing for you".
     *
     * Staged rollout: when the manifest has a `rollout` object, a site takes
     * `rollout.by_site[<host>]` if present, else `rollout.default`. A release
     * therefore reaches the test property first and the live properties only
     * when the rollout workflow moves it on. The top-level version/download_url
     * are read only by plugin versions that predate staged rollouts.
     */
    public static function release_for_site($m, $host = null) {
        if (!is_array($m)) { return null; }
        if (isset($m['rollout']) && is_array($m['rollout'])) {
            $host = $host === null ? self::site_host() : (string) $host;
            $r = $m['rollout'];
            $rel = null;
            if ($host !== '' && isset($r['by_site']) && is_array($r['by_site']) && isset($r['by_site'][$host]) && is_array($r['by_site'][$host])) {
                $rel = $r['by_site'][$host];
            } elseif (isset($r['default']) && is_array($r['default'])) {
                $rel = $r['default'];
            }
            if (!is_array($rel) || empty($rel['version']) || empty($rel['download_url'])) { return null; }
            foreach (['requires', 'tested', 'requires_php', 'changelog'] as $k) {
                if (!isset($rel[$k]) && isset($m[$k])) { $rel[$k] = $m[$k]; }
            }
            return $rel;
        }
        if (empty($m['version']) || empty($m['download_url'])) { return null; }
        return $m;
    }

    /** Manifest release for this site (cached manifest). */
    public static function site_release() { return self::release_for_site(self::manifest()); }

    public static function inject($transient) {
        if (!is_object($transient)) { return $transient; }
        $m = self::site_release();
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
        $m = self::site_release();
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
        $m = self::site_release();
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

    /* ------------------------------------------------------------------
     * Remote-triggered, verified self-update (used by the rollout workflow).
     * The caller cannot choose WHAT is installed: only the signed release the
     * manifest assigns to this site, through the same verified download.
     * ------------------------------------------------------------------ */

    const OPT_LAST = 'mha_update_last';
    const LOCK     = 'mha_self_update_lock';

    public static function plugin_dir() { return rtrim(WP_PLUGIN_DIR, '/\\') . '/' . self::SLUG; }
    /** Not under wp-content/upgrade: WordPress empties that folder on every update. */
    public static function backup_root() {
        $root = rtrim(WP_CONTENT_DIR, '/\\') . '/mha-backups';
        if (!is_dir($root) && @mkdir($root, 0755, true)) {
            @file_put_contents($root . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $root;
    }
    public static function backup_dir($version) { return self::backup_root() . '/' . preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $version); }

    public static function copy_tree($from, $to) {
        if (!is_dir($from)) { return false; }
        if (!is_dir($to) && !@mkdir($to, 0755, true)) { return false; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $dest = $to . '/' . $it->getSubPathName();
            if ($item->isDir()) { if (!is_dir($dest) && !@mkdir($dest, 0755, true)) { return false; } }
            elseif (!@copy($item->getPathname(), $dest)) { return false; }
        }
        return true;
    }

    public static function remove_tree($dir) {
        if (!is_dir($dir)) { return true; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        return @rmdir($dir);
    }

    /** Versions that can be restored on this site. */
    public static function backups() {
        $out = [];
        foreach ((array) @glob(self::backup_root() . '/*', GLOB_ONLYDIR) as $d) { $out[] = basename($d); }
        usort($out, 'version_compare');
        return $out;
    }

    /** Version declared by the plugin files currently on disk (not the code in memory). */
    public static function version_on_disk($dir = null) {
        $f = ($dir === null ? self::plugin_dir() : $dir) . '/matova-hbook-api.php';
        $src = is_readable($f) ? (string) @file_get_contents($f, false, null, 0, 8192) : '';
        return preg_match("/define\\('MHA_VERSION',\\s*'([^']+)'\\)/", $src, $m) ? $m[1] : null;
    }

    /** Put a saved version back in place. The broken copy is kept aside, never deleted first. */
    public static function restore($version) {
        $backup = self::backup_dir($version);
        if (!is_dir($backup) || self::version_on_disk($backup) === null) { return false; }
        $live = self::plugin_dir();
        $aside = self::backup_root() . '/.failed-' . gmdate('Ymd-His');
        if (is_dir($live) && !@rename($live, $aside)) { return false; }
        if (!self::copy_tree($backup, $live)) {
            // Could not restore: put the previous files back so the site is not left without the plugin.
            self::remove_tree($live);
            @rename($aside, $live);
            return false;
        }
        self::remove_tree($aside);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        return true;
    }

    /**
     * Does a FRESH request to this site still get a healthy matova/v2 answer?
     * Run by the old code that is still in memory right after the files were
     * replaced, so a release that breaks the site is detected even though its
     * own endpoints are dead.
     */
    public static function probe_health() {
        $url = function_exists('rest_url') ? rest_url('matova/v2/health') : home_url('/wp-json/matova/v2/health');
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'mha_probe=' . time();
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) { sleep(2); }
            $resp = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false, 'headers' => ['Accept' => 'application/json', 'Cache-Control' => 'no-cache']]);
            if (is_wp_error($resp)) { continue; }
            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            if ($code === 200 && is_array($body)) {
                $v = isset($body['data']['version']) ? (string) $body['data']['version'] : (isset($body['version']) ? (string) $body['version'] : null);
                return ['ok' => true, 'version' => $v];
            }
        }
        return ['ok' => false, 'version' => null];
    }

    private static function remember(array $r) {
        $r['at'] = gmdate('c');
        update_option(self::OPT_LAST, $r, false);
        return $r;
    }

    /**
     * Install the signed release assigned to this site, then prove the site
     * still answers; if it does not, put the previous version back.
     * Result `outcome`: up_to_date | updated | rolled_back | failed | busy.
     */
    public static function self_update() {
        $from = self::current();
        if (get_transient(self::LOCK)) { return ['outcome' => 'busy', 'version' => $from]; }
        self::flush();
        $rel = self::site_release();
        if (!$rel || !version_compare((string) $rel['version'], $from, '>')) {
            return ['outcome' => 'up_to_date', 'version' => $from, 'offered' => $rel ? (string) $rel['version'] : null];
        }
        $to = (string) $rel['version'];
        if (!self::pubkeys() || empty($rel['signature'])) {
            return self::remember(['outcome' => 'failed', 'from' => $from, 'to' => $to, 'error' => 'unsigned', 'version' => $from]);
        }
        set_transient(self::LOCK, 1, 600);
        try {
            // 1. Keep the running version so it can be put back.
            $backup = self::backup_dir($from);
            self::remove_tree($backup);
            if (!self::copy_tree(self::plugin_dir(), $backup)) {
                return self::remember(['outcome' => 'failed', 'from' => $from, 'to' => $to, 'error' => 'backup_failed', 'version' => $from]);
            }

            // 2. WordPress' own upgrader; the download goes through verified_download().
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $was_active = function_exists('is_plugin_active') ? is_plugin_active(self::FILE) : true;
            $t = get_site_transient('update_plugins');
            if (!is_object($t)) { $t = new stdClass(); }
            $t = self::inject($t);
            set_site_transient('update_plugins', $t);
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            // bulk_upgrade (what the wp-admin "Update now" link uses) keeps the
            // plugin active; the single-plugin path deactivates it first.
            $all = $upgrader->bulk_upgrade([self::FILE], ['clear_update_cache' => true]);
            $res = (is_array($all) && array_key_exists(self::FILE, $all)) ? $all[self::FILE] : false;
            if (function_exists('opcache_reset')) { @opcache_reset(); }

            $disk = self::version_on_disk();
            $installed = ($res === true || is_array($res));
            if (!$installed || $disk !== $to) {
                $err = is_wp_error($res) ? $res->get_error_code()
                    : ((isset($upgrader->skin->result) && is_wp_error($upgrader->skin->result)) ? $upgrader->skin->result->get_error_code() : 'install_failed');
                // Files may be half-replaced: make sure the previous version is what is on disk.
                $untouched = ($disk === $from);
                $restored = $untouched ? true : self::restore($from);
                return self::remember(['outcome' => (!$untouched && $restored) ? 'rolled_back' : 'failed', 'from' => $from, 'to' => $to, 'error' => $err, 'version' => $restored ? $from : $disk]);
            }
            if ($was_active && function_exists('is_plugin_active') && !is_plugin_active(self::FILE) && function_exists('activate_plugin')) {
                activate_plugin(self::FILE, '', false, true);
            }

            // 3. A fresh request must still be healthy, otherwise the old version goes back.
            sleep(2);
            $probe = self::probe_health();
            if (!$probe['ok']) {
                $restored = self::restore($from);
                return self::remember(['outcome' => $restored ? 'rolled_back' : 'failed', 'from' => $from, 'to' => $to, 'error' => 'health_probe_failed', 'version' => $restored ? $from : $to]);
            }
            return self::remember(['outcome' => 'updated', 'from' => $from, 'to' => $to, 'version' => $to, 'probe_version' => $probe['version']]);
        } catch (\Throwable $e) {
            if (self::version_on_disk() !== $from) { self::restore($from); }
            return self::remember(['outcome' => 'failed', 'from' => $from, 'to' => $to, 'error' => 'exception', 'version' => self::version_on_disk()]);
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /** Put the newest saved older version back (rollout halt / recovery). */
    public static function self_rollback() {
        $from = self::version_on_disk();
        $older = array_values(array_filter(self::backups(), function ($v) use ($from) { return version_compare($v, (string) $from, '<'); }));
        if (!$older) { return ['outcome' => 'failed', 'error' => 'no_backup', 'version' => $from]; }
        $to = end($older);
        $ok = self::restore($to);
        return self::remember(['outcome' => $ok ? 'rolled_back' : 'failed', 'from' => $from, 'to' => $to, 'error' => $ok ? null : 'restore_failed', 'version' => $ok ? $to : $from]);
    }

    /** What the rollout checker needs to know about this site. */
    public static function status() {
        $rel = self::site_release();
        return [
            'site'            => self::site_host(),
            'version'         => self::current(),
            'version_on_disk' => self::version_on_disk(),
            'offered'         => $rel ? (string) $rel['version'] : null,
            'trusted_keys'    => count(self::pubkeys()),
            'can_verify'      => function_exists('sodium_crypto_sign_verify_detached'),
            'backups'         => self::backups(),
            'last'            => get_option(self::OPT_LAST, null),
        ];
    }
}
