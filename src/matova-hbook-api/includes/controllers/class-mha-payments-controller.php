<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Payments controller.
 *   POST /reservations/{id}/payments   (ops-write)
 *
 * Appends to HBook's payments_logs via native methods only — NEVER the flat
 * `paid` column (CONTRACTS §4).
 */
class MHA_Payments_Controller extends MHA_Controller {

    public function register() {
        register_rest_route($this->ns(), '/reservations/(?P<id>\d+)/payments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'add_payment'],
            'permission_callback' => MHA_Auth::require_scope('ops-write'),
        ]);
        // Read-only money state of a GROUP (HBook parent reservation). HBook
        // keeps a group's payments on the parent, and the reservation list only
        // returns the members — without this a parent payment could be written
        // but never read back.
        register_rest_route($this->ns(), '/groups/(?P<id>\d+)/payments', [
            'methods'             => 'GET',
            'callback'            => [$this, 'group_payments'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    public function group_payments(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $id = (int) $req->get_param('id');
            $p = MHA_HBook::parent_reservation($id);
            if (!$p) {
                return new WP_Error('not_found', 'Group (parent reservation) not found.', ['status' => 404]);
            }
            $logs = isset($p['payments_logs']) && is_array($p['payments_logs']) ? $p['payments_logs'] : [];
            return [
                'parent_id'    => $id,
                'price'        => isset($p['price']) ? (float) $p['price'] : null,
                // Real paid = sum of payments_logs (same rule as single reservations).
                'amount_paid'  => MHA_HBook::payments_sum($logs),
                'currency'     => isset($p['currency']) ? (string) $p['currency'] : null,
                'payments'     => array_values(array_map(function ($e) {
                    return is_array($e)
                        ? ['amount' => isset($e['amount']) ? (float) $e['amount'] : (isset($e['value']) ? (float) $e['value'] : null),
                           'payment_method' => isset($e['payment_method']) ? (string) $e['payment_method'] : null,
                           'received_on' => isset($e['received_on']) ? (string) $e['received_on'] : (isset($e['date']) ? (string) $e['date'] : null)]
                        : ['amount' => is_numeric($e) ? (float) $e : null, 'payment_method' => null, 'received_on' => null];
                }, $logs)),
            ];
        });
    }

    public function add_payment(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'POST /reservations/{id}/payments', 'reservation', $id,
            function () use ($req, $id) {
                // Audit the record that is actually written (parent for groups).
                return ((bool) $req->get_param('is_parent')) ? MHA_HBook::parent_reservation($id) : MHA_HBook::reservation($id);
            },
            function () use ($req, $id) {
                $is_parent = (bool) $req->get_param('is_parent');

                // Target must exist (single or parent).
                if ($is_parent) {
                    if (!MHA_HBook::parent_reservation($id)) {
                        return new WP_Error('not_found', 'Parent reservation not found.', ['status' => 404]);
                    }
                } else {
                    if (!MHA_HBook::reservation($id)) {
                        return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]);
                    }
                }

                $amount = MHA_Request::int_param($req, 'amount', false);
                // Accept float amounts via body too.
                $raw_amount = $req->get_param('amount');
                if ($raw_amount === null || $raw_amount === '') {
                    return new WP_Error('bad_request', 'Missing payment amount.', ['status' => 400]);
                }
                if (!is_numeric($raw_amount)) {
                    return new WP_Error('bad_request', 'Payment amount must be numeric.', ['status' => 400]);
                }
                $amount = (float) $raw_amount;
                if ($amount == 0.0) {
                    return new WP_Error('bad_request', 'Payment amount must be non-zero.', ['status' => 400]);
                }

                // HBook's native add_resa_payment() reads payment_method /
                // received_on / comment / online — NOT method/date/note. Build
                // those exact keys so the method and date actually land in
                // HBook's payments_logs instead of being dropped.
                $method = MHA_Request::string_param($req, 'payment_method', false, 64);
                if (is_wp_error($method)) { return $method; }
                if ($method === null || $method === '') {
                    $method = MHA_Request::string_param($req, 'method', false, 64); // back-compat alias
                    if (is_wp_error($method)) { return $method; }
                }
                $note = MHA_Request::string_param($req, 'note', false, 1000);
                if (is_wp_error($note)) { return $note; }
                $received_on = MHA_Request::string_param($req, 'received_on', false, 10);
                if (is_wp_error($received_on)) { return $received_on; }
                $received_on = (is_string($received_on) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received_on))
                    ? $received_on
                    : substr(current_time('mysql', 1), 0, 10);

                // A Matova OS local payment (card/cash/QR taken at reception) is
                // not an online-gateway payment, so online = 'no'. Comgate and
                // Booking.com payments are written by HBook itself, never here.
                $payment = [
                    'amount'         => $amount,
                    'payment_method' => $method ?: 'manual',
                    'received_on'    => $received_on,
                    'comment'        => $note ?: '',
                    'online'         => 'no',
                    'source'         => 'matova/v2',
                ];
                // Serialised with other reservation writes: two payments landing on
                // the same (parent) reservation at once must not overwrite each other.
                return MHA_HBook::with_inventory_lock(function () use ($id, $is_parent, $payment) {
                    return MHA_HBook::add_payment($id, $is_parent, $payment);
                });
            }
        );
    }
}
