<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pricing controller (read scope).
 *   GET /pricing/quote?accom_id&check_in&check_out&adults&children
 *
 * Price is always computed by HBook's native HbPriceCalc::get_price — never
 * reimplemented here.
 */
class MHA_Pricing_Controller extends MHA_Controller {

    public function register() {
        register_rest_route($this->ns(), '/pricing/quote', [
            'methods'             => 'GET',
            'callback'            => [$this, 'quote'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    public function quote(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $accom_id = MHA_Request::int_param($req, 'accom_id', true, 1);
            if (is_wp_error($accom_id)) { return $accom_id; }
            $check_in = MHA_Request::date_param($req, 'check_in', true);
            if (is_wp_error($check_in)) { return $check_in; }
            $check_out = MHA_Request::date_param($req, 'check_out', true);
            if (is_wp_error($check_out)) { return $check_out; }
            $adults = MHA_Request::int_param($req, 'adults', true, 1);
            if (is_wp_error($adults)) { return $adults; }
            $children = MHA_Request::int_param($req, 'children', false, 0);
            if (is_wp_error($children)) { return $children; }
            if ($children === null) { $children = 0; }

            if ($check_out <= $check_in) {
                return new WP_Error('bad_request', 'check_out must be after check_in.', ['status' => 400]);
            }

            return MHA_HBook::quote($accom_id, $check_in, $check_out, $adults, $children);
        });
    }
}
