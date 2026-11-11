<?php
if (!defined('ABSPATH')) { exit; }

/**
 * meta/health controller.
 *   GET /health  (public)
 *   GET /meta    (read scope)
 *   GET /events  (read scope) — outbox pull sync
 */
class MHA_Meta_Controller extends MHA_Controller {

    public function register() {
        $ns = $this->ns();

        register_rest_route($ns, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/meta', [
            'methods'             => 'GET',
            'callback'            => [$this, 'meta'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/events', [
            'methods'             => 'GET',
            'callback'            => [$this, 'events'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    public function health(WP_REST_Request $req) {
        return $this->wrap(function () {
            return [
                'status'  => 'ok',
                'api'     => defined('MHA_NS') ? MHA_NS : 'matova/v2',
                'version' => defined('MHA_VERSION') ? MHA_VERSION : null,
                'resort'  => MHA_Config::resort_id(),
                'time'    => gmdate('c'),
            ];
        });
    }

    public function meta(WP_REST_Request $req) {
        return $this->wrap(function () {
            global $wpdb;

            $hb_version = MHA_HBook::hbook_version();

            // Row counts for a few key HBook tables (best-effort).
            $prefix = MHA_Config::hb_prefix();
            $counts = [];
            foreach (['resa', 'parents_resa', 'customers', 'accom_blocked', 'accom_num_name'] as $tbl) {
                $name = $prefix . 'hb_' . $tbl;
                // Table name interpolated (not user input); value is a safe identifier.
                $c = $wpdb->get_var("SELECT COUNT(*) FROM `" . str_replace('`', '', $name) . "`");
                $counts[$tbl] = $c !== null ? (int) $c : null;
            }

            return [
                'resort'       => MHA_Config::resort(),
                'hbook_version'=> $hb_version,
                'wp_version'   => get_bloginfo('version'),
                'php_version'  => PHP_VERSION,
                'table_counts' => $counts,
                'last_event_id'=> MHA_Store::last_event_id(),
                'write_modes'  => [
                    'status'  => MHA_Config::write_mode('status'),
                    'note'    => MHA_Config::write_mode('note'),
                    'block'   => MHA_Config::write_mode('block'),
                    'payment' => MHA_Config::write_mode('payment'),
                    'guest'   => MHA_Config::write_mode('guest'),
                    'create'  => MHA_Config::write_mode('create'),
                    'modify'  => MHA_Config::write_mode('modify'),
                ],
                // Live feature detection — the app gates features on this so new
                // HBook/WP capabilities light up automatically after an upgrade.
                'capabilities' => MHA_HBook::capabilities(),
            ];
        });
    }

    public function events(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $since = MHA_Request::int_param($req, 'since', false, 0);
            if (is_wp_error($since)) { return $since; }
            if ($since === null) { $since = 0; }
            list($limit, ) = MHA_Request::pagination($req, 100);
            $events = MHA_Store::events_since($since, $limit);
            $next = $since;
            foreach ($events as $e) { if ($e['id'] > $next) { $next = $e['id']; } }
            return ['events' => $events, 'since' => $since, 'next_cursor' => $next, 'count' => count($events)];
        });
    }
}
