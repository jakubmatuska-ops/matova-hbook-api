<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Guests controller.
 *   GET   /guests/{id}   (read)
 *   PATCH /guests/{id}   (ops-write) — updates hb_customers.info via native method
 */
class MHA_Guests_Controller extends MHA_Controller {

    /** Guest fields the API accepts for update (mapped into hb_customers.info JSON). */
    const EDITABLE = ['first_name', 'last_name', 'phone', 'street', 'city', 'zip', 'country', 'email'];

    public function register() {
        register_rest_route($this->ns(), '/guests/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => MHA_Auth::require_scope('read'),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update'],
                'permission_callback' => MHA_Auth::require_scope('ops-write'),
            ],
        ]);
    }

    public function show(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $id = (int) $req->get_param('id');
            $cust = MHA_HBook::customer($id);
            if (!$cust) {
                return new WP_Error('not_found', 'Guest not found.', ['status' => 404]);
            }
            return $cust;
        });
    }

    public function update(WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return $this->mutate(
            $req, 'PATCH /guests/{id}', 'customer', $id,
            function () use ($id) { return MHA_HBook::customer($id); },
            function () use ($req, $id) {
                if (!MHA_HBook::customer($id)) {
                    return new WP_Error('not_found', 'Guest not found.', ['status' => 404]);
                }
                $body = $req->get_json_params();
                if (!is_array($body)) { $body = $req->get_params(); }

                $fields = [];
                foreach (self::EDITABLE as $f) {
                    if (array_key_exists($f, $body) && is_scalar($body[$f])) {
                        $fields[$f] = trim((string) $body[$f]);
                    }
                }
                if (empty($fields)) {
                    return new WP_Error('bad_request', 'No updatable guest fields supplied.', ['status' => 400, 'allowed' => self::EDITABLE]);
                }
                if (isset($fields['email']) && $fields['email'] !== '' && !is_email($fields['email'])) {
                    return new WP_Error('bad_request', 'Invalid email.', ['status' => 400]);
                }
                return MHA_HBook::update_guest($id, $fields);
            }
        );
    }
}
