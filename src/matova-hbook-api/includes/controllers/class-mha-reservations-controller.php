<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Reservations controller.
 *   GET    /reservations                 (read)
 *   GET    /reservations/search          (read)
 *   GET    /reservations/{id}            (read)
 *   POST   /reservations/{id}/status     (ops-write)
 *   POST   /reservations/{id}/notes      (ops-write)
 *   POST   /reservations                 (admin, gated/create)
 *   PATCH  /reservations/{id}            (admin, gated/modify)
 */
class MHA_Reservations_Controller extends MHA_Controller {

    public function register() {
        $ns = $this->ns();

        register_rest_route($ns, '/reservations', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => MHA_Auth::require_scope('read'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => MHA_Auth::require_scope('admin'),
            ],
        ]);

        register_rest_route($ns, '/reservations/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/reservations/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => MHA_Auth::require_scope('read'),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'modify'],
                'permission_callback' => MHA_Auth::require_scope('admin'),
            ],
        ]);

        register_rest_route($ns, '/reservations/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_status'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);

        register_rest_route($ns, '/reservations/(?P<id>\d+)/notes', [
            'methods'             => 'POST',
            'callback'            => [$this, 'add_note'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);

        // Swap the units of two reservations (dates stay). Admin, gated 'modify'.
        register_rest_route($ns, '/reservations/(?P<id>\d+)/swap', [
            'methods'             => 'POST',
            'callback'            => [$this, 'swap'],
            'permission_callback' => MHA_Auth::require_scope('admin'),
        ]);

        // Read-only diagnostic: HBook method signatures the move path relies on.
        register_rest_route($ns, '/_diag/hbook', [
            'methods'             => 'GET',
            'callback'            => [$this, 'diag_hbook'],
            'permission_callback' => MHA_Auth::require_scope('admin'),
        ]);
    }

    /** GET /_diag/hbook — admin, read-only. Confirms native signatures. */
    public function diag_hbook(WP_REST_Request $req) {
        return $this->wrap(function () {
            return MHA_HBook::diag_hbook_methods();
        });
    }

    /**
     * Parse the `include` query param (CHANGE 2 PII gating). Comma-separated;
     * recognised tokens: `guest` (customer PII + special_requests) and
     * `payments` (full payments_logs array). Default: neither.
     */
    private function parse_include(WP_REST_Request $req) {
        $raw = $req->get_param('include');
        $set = ['guest' => false, 'payments' => false, 'list' => []];
        if (!is_string($raw) || $raw === '') { return $set; }
        foreach (explode(',', $raw) as $tok) {
            $tok = trim(strtolower($tok));
            if ($tok === 'guest')    { $set['guest'] = true;    $set['list'][] = 'guest'; }
            if ($tok === 'payments') { $set['payments'] = true; $set['list'][] = 'payments'; }
        }
        return $set;
    }

    public function index(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $from = MHA_Request::date_param($req, 'from', false);
            if (is_wp_error($from)) { return $from; }
            $to = MHA_Request::date_param($req, 'to', false);
            if (is_wp_error($to)) { return $to; }

            $statuses = ['pending', 'new', 'confirmed', 'waiting_payment', 'cancelled', 'multi_resa_pending'];
            $status = MHA_Request::enum_param($req, 'status', $statuses, false);
            if (is_wp_error($status)) { return $status; }

            $updated_since = MHA_Request::string_param($req, 'updated_since', false, 40);
            if (is_wp_error($updated_since)) { return $updated_since; }

            list($limit, $offset) = MHA_Request::pagination($req);

            $inc = $this->parse_include($req);
            $rows = MHA_HBook::reservations_in_window($from, $to, $status, $updated_since, $limit, $offset);
            foreach ($rows as &$r) { $r = MHA_HBook::enrich_reservation($r, $inc['guest'], $inc['payments']); }
            unset($r);

            $data = [
                'reservations' => $rows,
                'count'        => count($rows),
                'limit'        => $limit,
                'offset'       => $offset,
            ];
            // Record PII inclusion in the response meta (privacy audit trail).
            return MHA_Response::ok($data, $this->resort_id(), 200, [
                'include'      => $inc['list'],
                'pii_included' => $inc['guest'],
            ]);
        });
    }

    public function search(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $q = MHA_Request::string_param($req, 'q', true, 191);
            if (is_wp_error($q)) { return $q; }
            list($limit, ) = MHA_Request::pagination($req);
            $rows = MHA_HBook::search($q, $limit);
            return ['reservations' => $rows, 'count' => count($rows), 'q' => $q];
        });
    }

    public function show(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $id = (int) $req->get_param('id');
            $resa = MHA_HBook::reservation($id);
            if (!$resa) {
                return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]);
            }
            $inc = $this->parse_include($req);
            $resa = MHA_HBook::enrich_reservation($resa, $inc['guest'], $inc['payments']);
            return MHA_Response::ok($resa, $this->resort_id(), 200, [
                'include'      => $inc['list'],
                'pii_included' => $inc['guest'],
            ]);
        });
    }

    public function set_status(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'POST /reservations/{id}/status', 'reservation', $id,
            function () use ($id) { return MHA_HBook::reservation($id); },
            function () use ($req, $id) {
                // Writable statuses via API: confirmed, cancelled (§8).
                $status = MHA_Request::enum_param($req, 'status', ['confirmed', 'cancelled'], true);
                if (is_wp_error($status)) { return $status; }
                if (!MHA_HBook::reservation($id)) {
                    return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]);
                }
                return MHA_HBook::set_status($id, $status);
            }
        );
    }

    public function add_note(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'POST /reservations/{id}/notes', 'reservation', $id,
            function () use ($id) { return MHA_HBook::reservation($id); },
            function () use ($req, $id) {
                $text = MHA_Request::string_param($req, 'text', true, 5000);
                if (is_wp_error($text)) { return $text; }
                if (!MHA_HBook::reservation($id)) {
                    return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]);
                }
                return MHA_HBook::add_note($id, $text);
            }
        );
    }

    /**
     * POST /reservations — admin, gated OFF by default (create write-mode).
     * Documented as UNTESTED against live HBook (CONTRACTS §4/§7).
     */
    public function create(WP_REST_Request $req) {
        return $this->mutate(
            $req, 'POST /reservations', 'reservation', null,
            function () { return null; },
            function () use ($req) {
                $payload = $req->get_json_params();
                if (!is_array($payload)) { $payload = $req->get_params(); }
                return MHA_HBook::create_reservation($payload);
            }
        );
    }

    /**
     * PATCH /reservations/{id} — admin, gated by the 'modify' write-mode.
     * `price`/`accom_price` → in-place price change.
     * `check_in`/`check_out`/`accom_id`/`accom_num` → full move: HBook dates
     * updated and the accommodation blocks re-orchestrated (see
     * MHA_HBook::move_reservation). A price may ride along with a move.
     */
    public function modify(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'PATCH /reservations/{id}', 'reservation', $id,
            function () use ($id) { return MHA_HBook::reservation($id); },
            function () use ($req, $id) {
                $body = $req->get_json_params();
                if (!is_array($body)) { $body = $req->get_params(); }
                unset($body['id']);
                return MHA_HBook::modify_reservation($id, $body);
            }
        );
    }

    /**
     * POST /reservations/{id}/swap — admin, gated 'modify'. Body: { target_id }.
     * Swaps the units of {id} and {target_id} (dates stay). See
     * MHA_HBook::swap_reservations.
     */
    public function swap(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'POST /reservations/{id}/swap', 'reservation', $id,
            function () use ($id) { return MHA_HBook::reservation($id); },
            function () use ($req, $id) {
                $body = $req->get_json_params();
                if (!is_array($body)) { $body = $req->get_params(); }
                $target = isset($body['target_id']) ? (int) $body['target_id'] : 0;
                if ($target <= 0) {
                    return new WP_Error('bad_request', 'target_id is required.', ['status' => 400]);
                }
                return MHA_HBook::swap_reservations($id, $target);
            }
        );
    }
}
