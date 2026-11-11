<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Base controller for matova/v2.
 *
 * Subclasses implement register() to declare their routes against the
 * matova/v2 namespace, each with an MHA_Auth::require_scope() permission.
 */
abstract class MHA_Controller {

    /** Register this controller's routes. */
    abstract public function register();

    protected function ns() {
        return defined('MHA_NS') ? MHA_NS : 'matova/v2';
    }

    protected function resort_id() {
        return MHA_Config::resort_id();
    }

    /**
     * Wrap a handler: runs the callable, turns its return value / WP_Error /
     * thrown exception into an MHA_Response envelope. Never leaks a PHP fatal.
     *
     * The callable returns either:
     *   - a WP_REST_Response (passed through — e.g. already-built envelope),
     *   - a WP_Error (mapped to err()),
     *   - any data (wrapped in ok()).
     */
    protected function wrap(callable $fn) {
        $resort = $this->resort_id();
        try {
            $result = call_user_func($fn);

            if ($result instanceof WP_REST_Response) {
                return $result;
            }
            if (is_wp_error($result)) {
                return $this->from_wp_error($result, $resort);
            }
            return MHA_Response::ok($result, $resort);
        } catch (\Throwable $e) {
            return MHA_Response::err('upstream_error', 'Unexpected error.', ['exception' => $e->getMessage()], $resort);
        }
    }

    /** Map a WP_Error to the correct envelope + HTTP status. */
    protected function from_wp_error(WP_Error $err, $resort) {
        $code = $err->get_error_code();
        if (!isset(MHA_Response::STATUS[$code])) { $code = 'bad_request'; }
        $data = $err->get_error_data();
        $details = is_array($data) ? $data : [];
        unset($details['status']);
        return MHA_Response::err($code, $err->get_error_message(), $details, $resort);
    }

    /**
     * Mutation helper: enforce Idempotency-Key, run idempotency begin/finish,
     * capture before/after audit, and build the response. The outbox is driven
     * by HBook hooks (MHA_Store::subscribe_hooks), so this does not emit events.
     *
     * @param WP_REST_Request $req
     * @param string   $route        audit/idempotency route label
     * @param string   $target_type  audit target type (e.g. 'reservation')
     * @param mixed    $target_id    audit target id
     * @param callable $read_before  () => array|null   current target state
     * @param callable $do           () => array|WP_Error   performs the native write
     */
    protected function mutate(WP_REST_Request $req, $route, $target_type, $target_id, callable $read_before, callable $do) {
        $resort = $this->resort_id();

        $key = MHA_Request::idempotency_key($req);
        if ($key === '') {
            return MHA_Response::err('bad_request', 'Missing required header Idempotency-Key.', [], $resort);
        }

        $request_sha = hash('sha256', wp_json_encode([
            'route'  => $route,
            'params' => $req->get_params(),
            'body'   => $req->get_body(),
        ]));

        $begin = MHA_Store::idempotency_begin($key, $route, $resort, $request_sha);
        if ($begin === 'conflict') {
            return MHA_Response::err('conflict', 'Idempotency-Key reused with a different request body.', [], $resort);
        }
        if ($begin === 'in_progress') {
            // A concurrent request with this key is mid-write. Never run the native
            // mutation twice — tell the caller to retry; the winner's stored response
            // will replay once it finishes.
            $resp = MHA_Response::err('conflict', 'A request with this Idempotency-Key is already in progress; retry shortly.', ['retry' => true], $resort);
            $resp->header('Retry-After', '2');
            return $resp;
        }
        if (is_array($begin) && !empty($begin['replay'])) {
            $decoded = $begin['response_json'] !== null ? json_decode($begin['response_json'], true) : null;
            $http = $begin['http_status'] ?: 200;
            $resp = new WP_REST_Response($decoded, $http);
            $resp->header('Idempotent-Replay', 'true');
            return $resp;
        }

        // Capture before-state.
        $before = null;
        try { $before = call_user_func($read_before); } catch (\Throwable $e) { $before = null; }

        // Perform the write.
        $outcome = null;
        $response = null;
        try {
            $outcome = call_user_func($do);
            if ($outcome instanceof WP_REST_Response) {
                $response = $outcome;
            } elseif (is_wp_error($outcome)) {
                $response = $this->from_wp_error($outcome, $resort);
            } else {
                $response = MHA_Response::ok($outcome, $resort);
            }
        } catch (\Throwable $e) {
            $response = MHA_Response::err('upstream_error', 'Write failed.', ['exception' => $e->getMessage()], $resort);
        }

        // Capture after-state (best-effort).
        $after = null;
        try { $after = call_user_func($read_before); } catch (\Throwable $e) { $after = null; }

        $http = $response->get_status();
        $ctx = MHA_Auth::context();
        MHA_Store::audit([
            'request_id'  => MHA_Response::request_id(),
            'key_id'      => $ctx ? ($ctx['id'] ?? null) : null,
            'consumer'    => $ctx ? ($ctx['consumer'] ?? null) : null,
            'scope'       => $ctx ? ($ctx['scope'] ?? null) : null,
            'resort_id'   => $resort,
            'route'       => $route,
            'method'      => $req->get_method(),
            'http_status' => $http,
            'target_type' => $target_type,
            'target_id'   => $target_id !== null ? (string) $target_id : null,
            'before_json' => $before,
            'after_json'  => $after,
        ]);

        // Persist the idempotent response for verbatim replay.
        $body = $response->get_data();
        MHA_Store::idempotency_finish($key, wp_json_encode($body), $http);

        return $response;
    }
}
