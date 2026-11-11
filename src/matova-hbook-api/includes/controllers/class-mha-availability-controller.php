<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Availability + catalogue controller (all read scope).
 *   GET /availability?from&to[&accom_id]
 *   GET /units
 *   GET /blocked?from&to
 *   GET /rules
 *   GET /extras
 *   POST   /blocked        (ops-write)
 *   DELETE /blocked/{id}   (ops-write)
 */
class MHA_Availability_Controller extends MHA_Controller {

    public function register() {
        $ns = $this->ns();

        register_rest_route($ns, '/availability', [
            'methods'             => 'GET',
            'callback'            => [$this, 'availability'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/units', [
            'methods'             => 'GET',
            'callback'            => [$this, 'units'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/blocked', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'blocked'],
                'permission_callback' => MHA_Auth::require_scope('read'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_block'],
                'permission_callback' => MHA_Auth::require_scope('ops-write'),
            ],
        ]);

        register_rest_route($ns, '/blocked/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_block'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);

        register_rest_route($ns, '/rules', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rules'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/extras', [
            'methods'             => 'GET',
            'callback'            => [$this, 'extras'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    public function availability(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $from = MHA_Request::date_param($req, 'from', true);
            if (is_wp_error($from)) { return $from; }
            $to = MHA_Request::date_param($req, 'to', true);
            if (is_wp_error($to)) { return $to; }
            $accom_id = MHA_Request::int_param($req, 'accom_id', false, 1);
            if (is_wp_error($accom_id)) { return $accom_id; }

            $data = MHA_HBook::availability($from, $to, $accom_id);

            // Document the three distinct metrics in the response (CHANGE 1).
            // bookable_free is HBook's native authority and follows the site's
            // hb_resa_paid_has_confirmation config (see bookable_free_config).
            $data['metrics_doc'] = [
                'physical_occupied' => 'Units with a guest physically staying (reservation status in {confirmed,new} overlapping the window).',
                'reserved'          => 'Units tied up operationally: any non-cancelled reservation (incl. waiting_payment/pending) plus blocks.',
                'bookable_free'     => 'Units actually bookable per HBook get_unavailable_accom_num_per_date under the site config (total minus native-unavailable). Legacy resort-dashboard occupancy ({confirmed,new}) is preserved only in the compat shim.',
            ];
            return $data;
        });
    }

    public function units(WP_REST_Request $req) {
        return $this->wrap(function () {
            return [
                'units'          => MHA_HBook::accom_units(),
                'accommodations' => MHA_HBook::accommodations(),
            ];
        });
    }

    public function blocked(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $from = MHA_Request::date_param($req, 'from', false);
            if (is_wp_error($from)) { return $from; }
            $to = MHA_Request::date_param($req, 'to', false);
            if (is_wp_error($to)) { return $to; }
            $rows = MHA_HBook::blocked($from, $to);
            return ['blocked' => $rows, 'count' => count($rows)];
        });
    }

    public function rules(WP_REST_Request $req) {
        return $this->wrap(function () {
            return ['rules' => MHA_HBook::booking_rules()];
        });
    }

    public function extras(WP_REST_Request $req) {
        return $this->wrap(function () {
            return ['extras' => MHA_HBook::extras()];
        });
    }

    public function create_block(WP_REST_Request $req) {
        return $this->mutate(
            $req, 'POST /blocked', 'block', null,
            function () { return null; },
            function () use ($req) {
                $accom_id = MHA_Request::int_param($req, 'accom_id', true, 1);
                if (is_wp_error($accom_id)) { return $accom_id; }
                $accom_num = MHA_Request::int_param($req, 'accom_num', true, 0);
                if (is_wp_error($accom_num)) { return $accom_num; }
                $from = MHA_Request::date_param($req, 'from', true);
                if (is_wp_error($from)) { return $from; }
                $to = MHA_Request::date_param($req, 'to', true);
                if (is_wp_error($to)) { return $to; }
                $comment = MHA_Request::string_param($req, 'comment', false, 1000);
                if (is_wp_error($comment)) { return $comment; }
                return MHA_HBook::create_block($accom_id, $accom_num, $from, $to, $comment ?: '');
            }
        );
    }

    public function delete_block(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'DELETE /blocked/{id}', 'block', $id,
            function () use ($id) { return MHA_HBook::blocked_row($id); },
            function () use ($id) { return MHA_HBook::delete_block($id); }
        );
    }
}
