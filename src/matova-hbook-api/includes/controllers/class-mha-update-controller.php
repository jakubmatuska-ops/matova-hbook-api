<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Remote update control for the staged rollout:
 *   GET  /update/status    (read)       — version, offered release, backups, last result
 *   POST /update/run       (ops-write)  — install the signed release assigned to this site
 *   POST /update/rollback  (ops-write)  — put the previous saved version back
 *
 * None of these lets a caller choose a package: /run installs only what the
 * signed manifest assigns to this site, /rollback only a version this site ran.
 */
class MHA_Update_Controller extends MHA_Controller {

    public function register() {
        register_rest_route($this->ns(), '/update/status', [
            'methods' => 'GET', 'callback' => [$this, 'status'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
        register_rest_route($this->ns(), '/update/run', [
            'methods' => 'POST', 'callback' => [$this, 'run'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);
        register_rest_route($this->ns(), '/update/rollback', [
            'methods' => 'POST', 'callback' => [$this, 'rollback'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);
    }

    public function status(WP_REST_Request $req) {
        MHA_Updater::flush();
        return MHA_Response::ok(MHA_Updater::status(), $this->resort_id());
    }

    public function run(WP_REST_Request $req) {
        if (function_exists('set_time_limit')) { @set_time_limit(180); }
        $r = MHA_Updater::self_update();
        $this->audit('POST /update/run', $r);
        return MHA_Response::ok($r, $this->resort_id());
    }

    public function rollback(WP_REST_Request $req) {
        $r = MHA_Updater::self_rollback();
        $this->audit('POST /update/rollback', $r);
        return MHA_Response::ok($r, $this->resort_id());
    }

    private function audit($route, array $r) {
        try {
            MHA_Store::audit([
                'route' => $route, 'method' => 'POST', 'http_status' => 200,
                'target_type' => 'plugin', 'target_id' => 'matova-hbook-api', 'after_json' => $r,
            ]);
        } catch (\Throwable $e) { /* best effort */ }
    }
}
