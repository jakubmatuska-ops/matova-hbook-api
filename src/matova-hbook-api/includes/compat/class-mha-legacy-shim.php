<?php
if (!defined('ABSPATH')) { exit; }

/**
 * OPTIONAL, default-OFF legacy-shape compatibility shim (TRANSITION AID ONLY).
 *
 * Purpose: let a single high-traffic read consumer repoint its base URL to
 * matova/v2 BEFORE its response-parsing code is updated, by exposing one or two
 * alias routes under the v2 namespace that reshape a v2 read into the legacy
 * `resort-dashboard/v1`-style JSON. This buys a consumer an overlap window; it is
 * NOT a permanent surface and MUST be removed once the consumer parses the v2
 * envelope.
 *
 * Why a shim exists at all (and why it is deliberately tiny):
 *   The clean migration path is: consumer unwraps v2 `data` client-side (one
 *   line) and reads HBook-column field names. That is preferred (see
 *   docs/CONSOLIDATION.md). This shim is the fallback for a consumer that cannot
 *   be edited in the same window as the cutover (e.g. a Make scenario whose
 *   mapping is expensive to rewire). Everything it serves can instead be done in
 *   the consumer, so keep this OFF unless a specific consumer needs it.
 *
 * Hard rules honored:
 *   - DEFAULT OFF. Registers nothing unless option `mha_legacy_shim` === 'on'.
 *   - NO AUTH BYPASS. Every alias uses the same MHA_Auth::require_scope('read')
 *     permission callback as the real v2 route. No shared/legacy key is accepted.
 *   - Lives entirely under the v2 namespace (matova/v2/compat/...). It does NOT
 *     register under resort-dashboard/v1 and never touches the legacy mu-plugins.
 *   - Additive + reversible: setting the option back to off (or not requiring
 *     this file) removes the aliases on the next request.
 *   - Reads only. No write aliases — write cutover goes direct to v2 so the
 *     write-mode gate, idempotency and audit are never bypassed.
 *
 * ---------------------------------------------------------------------------
 * ACTIVATION: the main bootstrap requires includes explicitly (no glob) and the
 * build task may not edit existing .php files. To make this file available, add
 * one line to matova-hbook-api.php (owner action):
 *
 *     require_once MHA_DIR . 'includes/compat/class-mha-legacy-shim.php';
 *     MHA_Legacy_Shim::boot();
 *
 * Then enable at runtime only when a consumer needs it:
 *
 *     wp option update mha_legacy_shim on
 *
 * and disable the moment that consumer is on native v2 shapes:
 *
 *     wp option update mha_legacy_shim off
 * ---------------------------------------------------------------------------
 */
class MHA_Legacy_Shim {

    const OPT = 'mha_legacy_shim';

    /** Wire the rest_api_init hook. Call once from the bootstrap. */
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    /** True only when the option is explicitly 'on'. */
    public static function enabled() {
        return get_option(self::OPT, 'off') === 'on';
    }

    /**
     * Register the alias routes — only when enabled. Kept to a single
     * high-traffic read: the resort-dashboard/v1-style /reservations list.
     */
    public static function register() {
        if (!self::enabled()) { return; }

        $ns = defined('MHA_NS') ? MHA_NS : 'matova/v2';

        // GET /matova/v2/compat/resort-dashboard/reservations
        // Legacy-shaped flat list, same auth + resort resolution as v2.
        register_rest_route($ns, '/compat/resort-dashboard/reservations', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'reservations_legacy_shape'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    /**
     * Reshape the v2 reservations read into the legacy resort-dashboard/v1
     * `/reservations` flat-row shape. Uses the SAME read path the v2 controller
     * uses (MHA_HBook::reservations_in_window), so there is one source of truth;
     * only the field names/envelope differ.
     *
     * Legacy consumers expected: { status: 'ok', reservations: [ {flat...} ] }
     * with no v2 `ok/data/meta` envelope. We add an `_shim` marker so logs make
     * the temporary path obvious.
     */
    public static function reservations_legacy_shape(WP_REST_Request $req) {
        $resort = MHA_Config::resort_id();
        try {
            if (!class_exists('MHA_HBook') || !method_exists('MHA_HBook', 'reservations_in_window')) {
                return new WP_REST_Response([
                    'status'  => 'error',
                    'message' => 'v2 read path unavailable; shim cannot serve.',
                    '_shim'   => true,
                ], 503);
            }

            $from   = self::clean_date($req->get_param('from'));
            $to     = self::clean_date($req->get_param('to'));
            $status = $req->get_param('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            $limit  = (int) $req->get_param('limit');
            if ($limit <= 0 || $limit > 200) { $limit = 50; }
            $offset = (int) $req->get_param('offset');
            if ($offset < 0) { $offset = 0; }

            $rows = MHA_HBook::reservations_in_window($from, $to, $status, null, $limit, $offset);
            if (!is_array($rows)) { $rows = []; }

            $out = [];
            foreach ($rows as $r) {
                // The shim is the sanctioned place the FULL legacy shape (incl. PII)
                // lives: enrich with guest PII + non-PII enrichment, then flatten.
                $enriched = MHA_HBook::enrich_reservation((array) $r, true, false);
                $out[] = self::to_legacy_row($enriched);
            }

            return new WP_REST_Response([
                'status'       => 'ok',
                'reservations' => $out,
                'count'        => count($out),
                'resort'       => $resort,
                '_shim'        => true, // TEMPORARY legacy-shape alias — remove after cutover.
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'shim error: ' . $e->getMessage(),
                '_shim'   => true,
            ], 502);
        }
    }

    /**
     * Map one ENRICHED v2 reservation row to the exact legacy
     * resort-dashboard/v1 flat shape (incl. inline PII), so Lovable/OPS/bridge
     * migrate without breakage. Key set + order mirror the real
     * hbapi_get_reservations() handler.
     *
     * Field parity notes:
     *   - amount_paid reproduces the legacy flat `paid` column (bug-for-bug: the
     *     legacy handler read `paid`, not payments_logs). v2's /reservations uses
     *     the payments_logs sum instead; the shim keeps legacy behaviour.
     *   - accommodation_type is the HBook-resolved accommodation NAME (via
     *     enrich_reservation); the legacy handler used a host-keyed placeholder
     *     map (rd_get_accom_map) — the resolved name is a strict improvement.
     *   - admin_comment_source resolves own-comment → additional_info scan →
     *     'none' (legacy's parent-comment provenance is a legacy-only nuance).
     */
    private static function to_legacy_row(array $r) {
        $cust  = (isset($r['customer']) && is_array($r['customer'])) ? $r['customer'] : [];
        $first = (string) ($cust['first_name'] ?? '');
        $last  = (string) ($cust['last_name'] ?? '');
        $name  = trim($first . ' ' . $last);

        $admin_comment = (string) ($r['admin_comment'] ?? '');
        $admin_comment_source = self::admin_comment_source($r, $admin_comment);

        return [
            'reservation_id'       => self::pick($r, ['id', 'reservation_id']),
            'num'                  => self::pick($r, ['id', 'reservation_id']),
            'alphanum_id'          => self::pick($r, ['alphanum_id']),
            'check_in'             => self::pick($r, ['check_in']),
            'check_out'            => self::pick($r, ['check_out']),
            'nights'               => isset($r['nights']) && $r['nights'] !== null ? (string) $r['nights'] : '',
            'accommodation_type'   => self::pick($r, ['accommodation_type', 'accom_id']),
            'accommodation_number' => self::pick($r, ['accom_num', 'accommodation_number']),
            'adults'               => self::pick($r, ['adults']),
            'children'             => self::pick($r, ['children']),
            'price'                => self::pick($r, ['price']),
            'deposit'              => self::pick($r, ['deposit']),
            'amount_paid'          => self::pick($r, ['paid', 'amount_paid']),
            'currency'             => self::pick($r, ['currency'], 'CZK'),
            'status'               => self::pick($r, ['status']),
            'payment_status'       => self::pick($r, ['payment_status']),
            'payment_gateway'      => self::pick($r, ['payment_gateway']),
            'admin_comment'        => $admin_comment,
            'admin_comment_source' => $admin_comment_source,
            'extras'               => self::extras_string($r['options'] ?? null),
            'special_requests'     => (string) ($r['special_requests'] ?? ''),
            'received_on'          => self::pick($r, ['received_on']),
            'origin'               => self::pick($r, ['origin']),
            'first_name'           => $first,
            'last_name'            => $last,
            'customer_name'        => $name,
            'email'                => (string) ($cust['email'] ?? ''),
            'phone'                => (string) ($cust['phone'] ?? ''),
            'customer_id'          => self::pick($r, ['customer_id']),
            'parent_id'            => self::pick($r, ['parent_id']),
        ];
    }

    /** Legacy "Name xQty, ..." extras string from the options JSON. */
    private static function extras_string($options) {
        if (is_string($options)) {
            if ($options === '' || $options === '[]') { return ''; }
            $options = json_decode($options, true);
        }
        if (!is_array($options)) { return ''; }
        $parts = [];
        foreach ($options as $opt) {
            if (is_array($opt) && !empty($opt['name'])) {
                $qty = isset($opt['quantity']) ? ' x' . $opt['quantity'] : '';
                $parts[] = $opt['name'] . $qty;
            }
        }
        return implode(', ', $parts);
    }

    /** Legacy admin_comment provenance: own → additional_info scan → none. */
    private static function admin_comment_source(array $r, $own) {
        if ((string) $own !== '') { return 'self'; }
        $ai_raw = $r['additional_info'] ?? '';
        if (is_array($ai_raw)) { $ai = $ai_raw; }
        elseif (is_string($ai_raw) && $ai_raw !== '' && $ai_raw !== '[]' && $ai_raw !== '{}') { $ai = json_decode($ai_raw, true); }
        else { $ai = null; }
        if (is_array($ai)) {
            foreach (['admin_comment', 'admin_note', 'note', 'comment', 'customer_note', 'poznamka', 'poznamka_admin'] as $k) {
                if (!empty($ai[$k]) && is_string($ai[$k])) { return 'self_additional_info:' . $k; }
            }
        }
        return 'none';
    }

    /** First present, non-null key from $keys, else $default. Always a string. */
    private static function pick(array $r, array $keys, $default = '') {
        foreach ($keys as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) {
                return is_scalar($r[$k]) ? (string) $r[$k] : $default;
            }
        }
        return $default;
    }

    /** Accept only YYYY-MM-DD; anything else → null (let the read default). */
    private static function clean_date($v) {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return $v; }
        return null;
    }
}
