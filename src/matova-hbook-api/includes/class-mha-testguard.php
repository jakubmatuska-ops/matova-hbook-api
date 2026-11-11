<?php
if (!defined('ABSPATH')) { exit; }

/**
 * MHA_TestGuard — side-effect isolation for controlled write testing (Borný only).
 *
 * TEST/DEV MODULE. Active ONLY when option 'mha_test_isolation' === 'on'.
 * While active it HARD-BLOCKS every outbound side effect that a write could trigger,
 * so disposable-record write tests cannot reach real guests or external systems:
 *   - wp_mail():            short-circuited (no customer/admin email leaves the site).
 *   - outbound HTTP:        any request to a host that is not this site is blocked
 *                           (covers Comgate payments.comgate.cz, Fakturoid app.fakturoid.cz,
 *                            Booking.com, Airbnb iCal, any gateway/webhook). Same-host
 *                            loopback (REST/cron) is allowed so HBook internals still work.
 * Every block is counted and ring-buffered so isolation can be PROVEN via the read endpoint
 * GET /matova/v2/_test/isolation (admin scope). Remove the option (and this module) before
 * production rollout.
 */
class MHA_TestGuard {

    const OPT = 'mha_test_isolation';
    const CNT_MAIL = 'mha_blocked_mail';
    const CNT_HTTP = 'mha_blocked_http';
    const LOG = 'mha_testguard_log';

    public static function is_on() {
        return get_option(self::OPT) === 'on';
    }

    public static function boot() {
        if (!self::is_on()) { return; }

        // 1) Block all email. pre_wp_mail exists since WP 5.7; returning non-null short-circuits.
        add_filter('pre_wp_mail', function ($null, $atts) {
            self::record('mail', is_array($atts) && isset($atts['to']) ? (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']) : '', isset($atts['subject']) ? $atts['subject'] : '');
            return false; // wp_mail() returns false, sends nothing.
        }, PHP_INT_MAX, 2);

        // 2) Block outbound HTTP to any non-local host (payments, invoicing, OTAs, webhooks).
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $self = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $local = ['localhost', '127.0.0.1', '::1'];
            $is_self = ($host === $self) || ($host === 'www.' . $self) || ('www.' . $host === $self) || in_array($host, $local, true);
            if ($is_self || $host === '') {
                return $pre; // allow loopback / relative
            }
            self::record('http', $host, (string) (is_array($args) && isset($args['method']) ? $args['method'] : 'GET'));
            return new WP_Error('mha_testguard_blocked', 'Outbound HTTP blocked by MHA test isolation: ' . $host);
        }, PHP_INT_MAX, 3);

        add_action('rest_api_init', function () {
            register_rest_route(MHA_NS, '/_test/isolation', [
                'methods'             => 'GET',
                'permission_callback' => MHA_Auth::require_scope('admin'),
                'callback'            => ['MHA_TestGuard', 'rest_status'],
            ]);
            register_rest_route(MHA_NS, '/_test/probe-mail', [
                'methods'             => 'POST',
                'permission_callback' => MHA_Auth::require_scope('admin'),
                'callback'            => ['MHA_TestGuard', 'rest_probe_mail'],
            ]);
            register_rest_route(MHA_NS, '/_test/probe-http', [
                'methods'             => 'POST',
                'permission_callback' => MHA_Auth::require_scope('admin'),
                'callback'            => ['MHA_TestGuard', 'rest_probe_http'],
            ]);
            // READ-ONLY capability/hook introspection of the host HBook build.
            // No writes, no side effects — reflects classes already loaded and
            // reads HBook's own source file to report which do_action('hb_*')
            // hooks this version actually fires (the outbox depends on them).
            register_rest_route(MHA_NS, '/_test/hbook-caps', [
                'methods'             => 'GET',
                'permission_callback' => MHA_Auth::require_scope('admin'),
                'callback'            => ['MHA_TestGuard', 'rest_hbook_caps'],
            ]);
            register_rest_route(MHA_NS, '/_test/store-state', [
                'methods'             => 'GET',
                'permission_callback' => MHA_Auth::require_scope('admin'),
                'callback'            => ['MHA_TestGuard', 'rest_store_state'],
            ]);
        });
    }

    /** READ-MOSTLY: report whether store tables exist, counts, and whether a direct outbox insert works (self-test row is removed). */
    public static function rest_store_state() {
        global $wpdb;
        $tables = [
            'idempotency' => MHA_Store::table_idempotency(),
            'audit'       => MHA_Store::table_audit(),
            'events'      => MHA_Store::table_events(),
        ];
        $state = [];
        foreach ($tables as $k => $t) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
            $count = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}") : null;
            $state[$k] = ['table' => $t, 'exists' => $exists, 'rows' => $count];
        }
        // READ-ONLY compare: raw table rows vs what events_since(0) returns, same request.
        $et = MHA_Store::table_events();
        $wpdb->last_error = '';
        $raw = $wpdb->get_results("SELECT id, type, entity, entity_id, seq FROM {$et} ORDER BY id ASC", ARRAY_A);
        $raw_err = $wpdb->last_error;
        $wpdb->last_error = '';
        $since = MHA_Store::events_since(0, 100);
        $since_err = $wpdb->last_error;
        return MHA_Response::ok([
            'schema_option'        => get_option(MHA_Store::SCHEMA_OPT),
            'schema_version_const' => MHA_Store::SCHEMA_VERSION,
            'tables'               => $state,
            'last_event_id'        => MHA_Store::last_event_id(),
            'raw_rows'             => $raw ?: [],
            'raw_error'            => $raw_err,
            'events_since0_count'  => count($since),
            'events_since0_error'  => $since_err,
            'has_action_blocked'   => (bool) has_action('hb_blocked_accom_updated'),
        ], MHA_Config::resort_id());
    }

    /** READ-ONLY: report HBook version, native-method surface, and which hb_* hooks the host fires. */
    public static function rest_hbook_caps() {
        $methods = [
            'create_resa', 'update_resa_status', 'update_resa_comment',
            'add_blocked_accom', 'delete_blocked_accom', 'automatic_block_accom',
            'update_customer', 'add_resa_payment', 'update_resa_payments',
            'update_resa_price', 'update_resa_dates',
        ];
        $method_surface = [];
        $db_file = null; $db_class_found = class_exists('HbDataBaseActions');
        if ($db_class_found) {
            foreach ($methods as $m) { $method_surface[$m] = method_exists('HbDataBaseActions', $m); }
            try {
                $rc = new \ReflectionClass('HbDataBaseActions');
                $db_file = $rc->getFileName();
            } catch (\Throwable $e) { $db_file = null; }
        }

        // Which hb_* hooks does the host's own source actually fire? (read-only file scan)
        $hooks_in_source = [];
        $source_scanned = [];
        $dir = $db_file ? dirname($db_file) : null;
        // Scan the HBook plugin directory broadly but read-only, cap total bytes.
        $root = $dir ? dirname($dir) : null; // .../hbook/database-actions -> .../hbook
        $scan_files = [];
        if ($db_file && is_readable($db_file)) { $scan_files[] = $db_file; }
        if ($root && is_dir($root)) {
            foreach (['front-end/front-end-ajax-actions.php', 'admin-pages/admin-ajax-actions.php', 'utils/multi-resa.php', 'payment/payment-gateway.php'] as $rel) {
                $p = $root . '/' . $rel;
                if (is_readable($p)) { $scan_files[] = $p; }
            }
        }
        foreach ($scan_files as $p) {
            $src = @file_get_contents($p, false, null, 0, 2000000);
            if ($src === false) { continue; }
            $source_scanned[] = str_replace(ABSPATH, '', $p);
            if (preg_match_all('/do_action\(\s*[\'"]([a-z0-9_]*hb[a-z0-9_]*)[\'"]/i', $src, $m)) {
                foreach ($m[1] as $h) {
                    if (stripos($h, 'hb') === false) { continue; }
                    $hooks_in_source[$h] = isset($hooks_in_source[$h]) ? $hooks_in_source[$h] + 1 : 1;
                }
            }
        }
        ksort($hooks_in_source);

        // Are OUR outbox subscribers registered for the canonical hooks?
        $our_hooks = ['hb_create_reservation','hb_reservations_updated','hb_reservation_updated','hb_parent_reservation_updated','hb_blocked_accom_updated','hb_parent_reservation_cancelled'];
        $subscribed = [];
        foreach ($our_hooks as $h) { $subscribed[$h] = (bool) has_action($h); }

        // Version string if HBook exposes one.
        $ver = defined('HBOOK_VERSION') ? HBOOK_VERSION : (defined('HB_VERSION') ? HB_VERSION : null);

        return MHA_Response::ok([
            'hbook_version_const' => $ver,
            'db_class_found'      => $db_class_found,
            'db_source_file'      => $db_file ? str_replace(ABSPATH, '', $db_file) : null,
            'native_methods'      => $method_surface,
            'hooks_fired_in_source' => $hooks_in_source,     // {hook_name: occurrence_count}
            'our_subscribers'     => $subscribed,            // our add_action registrations present?
            'source_files_scanned' => $source_scanned,
            'price_calc_class'    => class_exists('HbPriceCalc'),
            'price_get_price'     => class_exists('HbPriceCalc') ? method_exists('HbPriceCalc', 'get_price') : false,
        ], MHA_Config::resort_id());
    }

    private static function record($kind, $target, $detail) {
        $opt = ($kind === 'mail') ? self::CNT_MAIL : self::CNT_HTTP;
        update_option($opt, (int) get_option($opt, 0) + 1, false);
        $log = get_option(self::LOG, []);
        if (!is_array($log)) { $log = []; }
        array_unshift($log, ['at' => gmdate('c'), 'kind' => $kind, 'target' => $target, 'detail' => $detail]);
        update_option(self::LOG, array_slice($log, 0, 50), false);
    }

    public static function rest_status() {
        return MHA_Response::ok([
            'isolation'         => self::is_on() ? 'on' : 'off',
            'blocked_mail'      => (int) get_option(self::CNT_MAIL, 0),
            'blocked_http'      => (int) get_option(self::CNT_HTTP, 0),
            'recent'            => array_slice((array) get_option(self::LOG, []), 0, 20),
        ], MHA_Config::resort_id());
    }

    /** Proof: attempt to send an email; under isolation it must be blocked. */
    public static function rest_probe_mail() {
        $before = (int) get_option(self::CNT_MAIL, 0);
        $ret = wp_mail('isolation-probe@example.invalid', 'MHA isolation probe', 'If you can read this in an inbox, isolation FAILED.');
        $after = (int) get_option(self::CNT_MAIL, 0);
        return MHA_Response::ok([
            'wp_mail_return'   => $ret,          // false when blocked
            'blocked_counter'  => ['before' => $before, 'after' => $after],
            'blocked'          => ($after > $before && $ret === false),
        ], MHA_Config::resort_id());
    }

    /** Proof: attempt an outbound HTTP to an external host; under isolation it must be blocked. */
    public static function rest_probe_http() {
        $before = (int) get_option(self::CNT_HTTP, 0);
        $resp = wp_remote_get('https://payments.comgate.cz/v1.0/methods', ['timeout' => 5]);
        $after = (int) get_option(self::CNT_HTTP, 0);
        return MHA_Response::ok([
            'is_wp_error'      => is_wp_error($resp),
            'error'            => is_wp_error($resp) ? $resp->get_error_message() : null,
            'blocked_counter'  => ['before' => $before, 'after' => $after],
            'blocked'          => ($after > $before && is_wp_error($resp)),
        ], MHA_Config::resort_id());
    }
}
