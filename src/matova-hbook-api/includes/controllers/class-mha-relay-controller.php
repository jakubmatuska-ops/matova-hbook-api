<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Relay + legacy-surface controller (2.4.0).
 *
 *   POST /comgate/call        (ops-write)  read-only relay to the Comgate
 *                                          Merchant API from this server's
 *                                          fixed IP. Same allow-list as the old
 *                                          `matova-comgate/v1` mu-plugin, but
 *                                          authenticated with the scoped,
 *                                          hashed matova/v2 keys — no shared key.
 *   GET  /comgate/outbound-ip (read)       the IP to allow in Comgate.
 *   GET  /legacy              (admin)      which legacy REST namespaces exist
 *                                          here and whether they are switched off.
 *   PUT  /legacy              (admin)      switch legacy namespaces off / on.
 *
 * Switching a legacy namespace off removes its routes from the REST server
 * (every call answers 404) without touching files or stored keys, so it is
 * instantly reversible. This is how the old shared key is retired without a
 * credential change: once nothing calls the old relay, its routes are turned
 * off and the key that unlocked them unlocks nothing.
 */
class MHA_Relay_Controller extends MHA_Controller {

    const OPT_DISABLED = 'mha_legacy_disabled';
    /** Legacy namespaces this plugin is allowed to switch off. Nothing else. */
    const LEGACY_NAMESPACES = ['matova-comgate/v1', 'matova-hbook/v1', 'resort-dashboard/v1', 'matova-move/v1'];
    const COMGATE_BASE = 'https://payments.comgate.cz';

    public function register() {
        register_rest_route($this->ns(), '/comgate/call', [
            'methods'             => 'POST',
            'callback'            => [$this, 'comgate_call'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);
        register_rest_route($this->ns(), '/comgate/outbound-ip', [
            'methods'             => 'GET',
            'callback'            => [$this, 'outbound_ip'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
        register_rest_route($this->ns(), '/legacy', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'legacy_show'],
                'permission_callback' => MHA_Auth::require_scope('admin'),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'legacy_set'],
                'permission_callback' => MHA_Auth::require_scope('admin'),
            ],
        ]);
    }

    /** READ-ONLY allow-list. Anything not matching is refused. */
    public static function comgate_path_allowed($path) {
        if (!is_string($path)) { return false; }
        $allowed = [
            '#^/v2\.0/method$#',
            '#^/v2\.0/payment/transId/[A-Za-z0-9_\-]{1,64}$#',
            '#^/v2\.0/transfer/[A-Za-z0-9_\-]{1,64}$#',
            '#^/v2\.0/transfer$#',
            '#^/v1\.0/methods$#',
            '#^/v1\.0/status$#',
            '#^/v1\.0/transferList$#',
            '#^/v1\.0/singleTransfer$#',
        ];
        foreach ($allowed as $re) { if (preg_match($re, $path)) { return true; } }
        return false;
    }

    public function comgate_call(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $b = $req->get_json_params();
            if (!is_array($b)) { $b = []; }
            $merchant = isset($b['merchant']) ? trim((string) $b['merchant']) : '';
            $secret   = isset($b['secret'])   ? trim((string) $b['secret'])   : '';
            $path     = isset($b['path'])     ? (string) $b['path']           : '';
            $query    = isset($b['query']) && is_array($b['query']) ? $b['query'] : [];
            if ($merchant === '' || $secret === '' || $path === '') {
                return new WP_Error('bad_request', 'merchant, secret and path are required.', ['status' => 400]);
            }
            if (!self::comgate_path_allowed($path)) {
                return new WP_Error('forbidden', 'Path not allowed (read-only relay).', ['status' => 403]);
            }
            $args = [
                'timeout' => 30,
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($merchant . ':' . $secret),
                ],
            ];
            if (strpos($path, '/v1.0/') === 0) {
                unset($args['headers']['Authorization']);
                $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                $args['body'] = array_merge(['merchant' => $merchant, 'secret' => $secret, 'type' => 'json'], $query);
                $r = wp_remote_post(self::COMGATE_BASE . $path, $args);
            } else {
                $url = self::COMGATE_BASE . $path;
                if (!empty($query)) { $url .= '?' . http_build_query($query); }
                $r = wp_remote_get($url, $args);
            }
            if (is_wp_error($r)) {
                return new WP_Error('upstream_error', $r->get_error_message(), ['status' => 502]);
            }
            // The merchant secret is never echoed back, logged or stored.
            return [
                'http_status'  => (int) wp_remote_retrieve_response_code($r),
                'content_type' => (string) wp_remote_retrieve_header($r, 'content-type'),
                'body'         => substr((string) wp_remote_retrieve_body($r), 0, 200000),
            ];
        });
    }

    public function outbound_ip(WP_REST_Request $req) {
        return $this->wrap(function () {
            $r = wp_remote_get('https://api.ipify.org?format=json', ['timeout' => 15]);
            if (is_wp_error($r)) {
                return new WP_Error('upstream_error', $r->get_error_message(), ['status' => 502]);
            }
            $ip = json_decode((string) wp_remote_retrieve_body($r), true);
            return ['ip' => isset($ip['ip']) ? (string) $ip['ip'] : null];
        });
    }

    /* ------------------------------------------------------------ legacy */

    public static function disabled_namespaces() {
        $v = get_option(self::OPT_DISABLED, []);
        if (!is_array($v)) { return []; }
        return array_values(array_intersect(self::LEGACY_NAMESPACES, array_map('strval', $v)));
    }

    /** `rest_endpoints` filter: drop every route of a switched-off namespace. */
    public static function filter_endpoints($endpoints) {
        $off = self::disabled_namespaces();
        if (!$off || !is_array($endpoints)) { return $endpoints; }
        foreach (array_keys($endpoints) as $route) {
            foreach ($off as $ns) {
                if ($route === '/' . $ns || strpos($route, '/' . $ns . '/') === 0) {
                    unset($endpoints[$route]);
                    break;
                }
            }
        }
        return $endpoints;
    }

    private static function present_namespaces() {
        $present = [];
        if (function_exists('rest_get_server')) {
            $server = rest_get_server();
            $all = method_exists($server, 'get_namespaces') ? (array) $server->get_namespaces() : [];
            $present = array_values(array_intersect(self::LEGACY_NAMESPACES, $all));
        }
        return $present;
    }

    public function legacy_show(WP_REST_Request $req) {
        return $this->wrap(function () {
            return [
                'known'    => self::LEGACY_NAMESPACES,
                'present'  => self::present_namespaces(),
                'disabled' => self::disabled_namespaces(),
            ];
        });
    }

    public function legacy_set(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $b = $req->get_json_params();
            $want = isset($b['disabled']) && is_array($b['disabled']) ? array_map('strval', $b['disabled']) : null;
            if ($want === null) {
                return new WP_Error('bad_request', '"disabled" must be a list of legacy namespaces.', ['status' => 400]);
            }
            $unknown = array_values(array_diff($want, self::LEGACY_NAMESPACES));
            if ($unknown) {
                // Never let this switch touch matova/v2, wp/v2 or anything else.
                return new WP_Error('bad_request', 'Not a legacy namespace: ' . implode(', ', $unknown), ['status' => 400]);
            }
            $clean = array_values(array_unique($want));
            update_option(self::OPT_DISABLED, $clean, false);
            if (class_exists('MHA_Store') && method_exists('MHA_Store', 'audit')) {
                try { MHA_Store::audit('legacy.set', 'site', 0, ['disabled' => $clean]); } catch (\Throwable $e) { /* audit is best effort */ }
            }
            return ['disabled' => self::disabled_namespaces()];
        });
    }
}
