<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Standardized JSON envelope for every matova/v2 route.
 * ok() / err() return WP_REST_Response objects carrying the agreed shape.
 */
class MHA_Response {

    const STATUS = [
        'bad_request'    => 400,
        'unauthorized'   => 401,
        'forbidden'      => 403,
        'not_found'      => 404,
        'conflict'       => 409,
        'rate_limited'   => 429,
        'write_disabled' => 503,
        'upstream_error' => 502,
        'unsupported'    => 503, // 501 is intercepted as an HTML page by the WEDOS/Apache stack; 503 passes through as JSON
    ];

    public static function request_id() {
        static $rid = null;
        if ($rid === null) {
            $rid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        }
        return $rid;
    }

    public static function meta($resort_id = null) {
        return [
            'resort'     => $resort_id,
            'as_of'      => gmdate('c'),
            'api'        => defined('MHA_NS') ? MHA_NS : 'matova/v2',
            'request_id' => self::request_id(),
        ];
    }

    public static function ok($data, $resort_id = null, $http = 200, array $extra_meta = []) {
        $body = [
            'ok'   => true,
            'data' => $data,
            'meta' => array_merge(self::meta($resort_id), $extra_meta),
        ];
        return new WP_REST_Response($body, $http);
    }

    /**
     * @param string $code  one of self::STATUS keys
     */
    public static function err($code, $message, array $details = [], $resort_id = null) {
        $http = isset(self::STATUS[$code]) ? self::STATUS[$code] : 400;
        $body = [
            'ok'    => false,
            'error' => [
                'code'    => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
            'meta'  => self::meta($resort_id),
        ];
        return new WP_REST_Response($body, $http);
    }

    /** Add Retry-After to a rate_limited response. */
    public static function rate_limited($retry_after, $resort_id = null) {
        $r = self::err('rate_limited', 'Rate limit exceeded.', ['retry_after' => (int) $retry_after], $resort_id);
        $r->header('Retry-After', (string) (int) $retry_after);
        return $r;
    }
}
