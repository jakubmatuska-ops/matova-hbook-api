<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The ONLY bridge to HBook (CONTRACTS §4).
 *
 * READS  : $wpdb over {prefix}hb_* tables (§8), normalized into typed JSON.
 * WRITES : HBook native objects only (HbDataBaseActions / HbPriceCalc / HbUtils),
 *          never raw SQL. Each write honors the write-mode gate (§7):
 *            off     -> WP_Error('write_disabled')
 *            dry_run -> ['would'=>..] without calling native
 *            live    -> call native, feature-detecting 2.2-only methods
 *
 * No masking happens here; controllers decide what to expose.
 */
class MHA_HBook {

    /** Active-reservation statuses (occupy a unit) — inverse of availability-free set. */
    const ACTIVE_STATUSES = ['new', 'confirmed', 'waiting_payment', 'multi_resa_pending'];

    /* ================================================================ helpers */

    private static function prefix() {
        return MHA_Config::hb_prefix();
    }

    private static function t($name) {
        return self::prefix() . 'hb_' . $name;
    }

    /** Statuses that FREE a unit for availability (§8). */
    private static function free_statuses() {
        $free = ['cancelled', 'pending'];
        if (get_option('hb_resa_paid_has_confirmation') === 'yes') {
            $free[] = 'waiting_payment';
        }
        return $free;
    }

    /** Decode a JSON column into an array, tolerant of serialized/empty values. */
    private static function json_col($v) {
        if ($v === null || $v === '') { return null; }
        if (is_array($v)) { return $v; }
        $d = json_decode((string) $v, true);
        if ($d !== null || trim((string) $v) === 'null') { return $d; }
        // HBook occasionally stores PHP-serialized blobs.
        $un = @unserialize($v);
        return $un !== false ? $un : (string) $v;
    }

    /** Normalize one hb_resa row into clean typed JSON. */
    private static function shape_resa($r) {
        if (!$r) { return null; }
        return [
            'id'             => (int) $r['id'],
            'parent_id'      => isset($r['parent_id']) ? (int) $r['parent_id'] : 0,
            'check_in'       => $r['check_in'] ?? null,
            'check_out'      => $r['check_out'] ?? null,
            'accom_id'       => isset($r['accom_id']) ? (int) $r['accom_id'] : null,
            'accom_num'      => isset($r['accom_num']) ? (int) $r['accom_num'] : null,
            'adults'         => isset($r['adults']) ? (int) $r['adults'] : null,
            'children'       => isset($r['children']) ? (int) $r['children'] : null,
            'status'         => $r['status'] ?? null,
            'accom_price'    => isset($r['accom_price']) ? (float) $r['accom_price'] : null,
            'price'          => isset($r['price']) ? (float) $r['price'] : null,
            'previous_price' => isset($r['previous_price']) ? (float) $r['previous_price'] : null,
            'deposit'        => isset($r['deposit']) ? (float) $r['deposit'] : null,
            'paid'           => isset($r['paid']) ? (float) $r['paid'] : null,
            'amount_to_pay'  => isset($r['amount_to_pay']) ? (float) $r['amount_to_pay'] : null,
            'currency'       => $r['currency'] ?? null,
            'customer_id'    => isset($r['customer_id']) ? (int) $r['customer_id'] : null,
            'discount'       => self::json_col($r['discount'] ?? null),
            'options'        => self::json_col($r['options'] ?? null),
            'fees'           => self::json_col($r['fees'] ?? null),
            'payments_logs'  => self::json_col($r['payments_logs'] ?? null),
            'payment_status' => $r['payment_status'] ?? null,
            'payment_gateway'=> $r['payment_gateway'] ?? null,
            'payment_type'   => $r['payment_type'] ?? null,
            'additional_info'=> $r['additional_info'] ?? null,
            'admin_comment'  => $r['admin_comment'] ?? null,
            'lang'           => $r['lang'] ?? null,
            'coupon'         => $r['coupon'] ?? null,
            'uid'            => $r['uid'] ?? null,
            'alphanum_id'    => $r['alphanum_id'] ?? null,
            'origin'         => $r['origin'] ?? null,
            'received_on'    => $r['received_on'] ?? null,
            'updated_on'     => $r['updated_on'] ?? null,
        ];
    }

    /** Sum of a payments_logs JSON structure (HBook's real paid model). */
    public static function payments_sum($payments_logs) {
        $logs = self::json_col($payments_logs);
        if (!is_array($logs)) { return 0.0; }
        $sum = 0.0;
        foreach ($logs as $entry) {
            if (is_array($entry)) {
                if (isset($entry['amount']))      { $sum += (float) $entry['amount']; }
                elseif (isset($entry['value']))   { $sum += (float) $entry['value']; }
            } elseif (is_numeric($entry)) {
                $sum += (float) $entry;
            }
        }
        return $sum;
    }

    /* ======================================================= enrichment helpers */

    /**
     * Resolve the accommodation NAME from accom_id (CHANGE 2 non-PII enrichment).
     * Authority: the hb_accommodation post title; falls back to the base name in
     * hb_accom_num_name when no post exists. Cached per accom_id per request.
     */
    private static function accom_name($accom_id) {
        static $cache = [];
        $aid = (int) $accom_id;
        if ($aid <= 0) { return null; }
        if (array_key_exists($aid, $cache)) { return $cache[$aid]; }

        $name = null;
        if (function_exists('get_post')) {
            $post = get_post($aid);
            if ($post && isset($post->post_title) && $post->post_title !== '') {
                $name = (string) $post->post_title;
            }
        }
        if ($name === null) {
            global $wpdb;
            $num = self::t('accom_num_name');
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT num_name FROM {$num} WHERE accom_id = %d ORDER BY accom_num ASC LIMIT 1", $aid),
                ARRAY_A
            );
            if ($row && isset($row['num_name']) && $row['num_name'] !== '') {
                $name = (string) $row['num_name'];
            }
        }
        return $cache[$aid] = $name;
    }

    /** Nights between check_in/check_out (int, >= 0), or null if undetermined. */
    private static function nights($check_in, $check_out) {
        if (!$check_in || !$check_out) { return null; }
        $a = strtotime((string) $check_in);
        $b = strtotime((string) $check_out);
        if ($a === false || $b === false) { return null; }
        $n = (int) floor(($b - $a) / 86400);
        return $n >= 0 ? $n : null;
    }

    /** Guest special requests, parsed from the additional_info JSON (legacy keys). */
    private static function special_requests($additional_info) {
        $ai = self::json_col($additional_info);
        if (!is_array($ai)) { return ''; }
        $sr = $ai['speciln_poadavky']
            ?? $ai['special_requests']
            ?? $ai['speciln_poadavky_k_ubytovn']
            ?? '';
        return is_string($sr) ? trim($sr) : '';
    }

    /**
     * Enrich a shaped reservation row (CHANGE 2).
     *
     * ALWAYS adds non-PII enrichment: accommodation_type (NAME), nights,
     * amount_paid (sum of non-failed payments_logs amounts — the real HBook paid
     * model, never the flat `paid` column), payment_gateway, payment_status.
     *
     * PII is gated: customer first_name/last_name/email/phone + special_requests
     * are added ONLY when $include_guest is true; otherwise the row carries
     * customer_id only. The full payments_logs array is added only when
     * $include_payments is true.
     */
    public static function enrich_reservation(array $row, $include_guest = false, $include_payments = false) {
        $row['accommodation_type'] = self::accom_name($row['accom_id'] ?? null);
        $row['nights']             = self::nights($row['check_in'] ?? null, $row['check_out'] ?? null);
        $row['amount_paid']        = self::payments_sum($row['payments_logs'] ?? null);
        if (!array_key_exists('payment_gateway', $row)) { $row['payment_gateway'] = null; }
        if (!array_key_exists('payment_status', $row))  { $row['payment_status'] = null; }

        if ($include_payments) {
            $logs = self::json_col($row['payments_logs'] ?? null);
            $row['payments'] = is_array($logs) ? array_values($logs) : [];
        }

        if ($include_guest) {
            $cust = (!empty($row['customer_id'])) ? self::customer((int) $row['customer_id']) : null;
            $row['customer'] = $cust ? [
                'id'         => $cust['id'],
                'first_name' => $cust['first_name'] ?? null,
                'last_name'  => $cust['last_name'] ?? null,
                'email'      => $cust['email'] ?? null,
                'phone'      => $cust['phone'] ?? null,
            ] : null;
            $row['special_requests'] = self::special_requests($row['additional_info'] ?? null);
        }
        return $row;
    }

    /* ================================================================ READS */

    /**
     * Reservations within a window, optionally filtered by status and updated_since.
     * Window overlap: check_in < to AND check_out > from.
     */
    public static function reservations_in_window($from, $to, $status = null, $updated_since = null, $limit = 50, $offset = 0) {
        global $wpdb;
        $resa = self::t('resa');

        $where = ['1=1'];
        $args = [];

        if ($from !== null) { $where[] = 'check_out > %s'; $args[] = $from; }
        if ($to !== null)   { $where[] = 'check_in < %s';  $args[] = $to; }
        if ($status !== null && $status !== '') { $where[] = 'status = %s'; $args[] = $status; }
        if ($updated_since !== null && $updated_since !== '') { $where[] = 'updated_on >= %s'; $args[] = $updated_since; }

        $where_sql = implode(' AND ', $where);
        $args[] = (int) $limit;
        $args[] = (int) $offset;

        $sql = "SELECT * FROM {$resa} WHERE {$where_sql} ORDER BY check_in ASC, id ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) { $rows = []; }

        $out = [];
        foreach ($rows as $r) { $out[] = self::shape_resa($r); }
        return $out;
    }

    public static function reservation($id) {
        global $wpdb;
        $resa = self::t('resa');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$resa} WHERE id = %d", (int) $id), ARRAY_A);
        return self::shape_resa($row);
    }

    /** Fetch the raw hb_parents_resa row (for group/payment ops). */
    public static function parent_reservation($id) {
        global $wpdb;
        $t = self::t('parents_resa');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
        if (!$row) { return null; }
        $row['id'] = (int) $row['id'];
        if (isset($row['payments_logs'])) { $row['payments_logs'] = self::json_col($row['payments_logs']); }
        return $row;
    }

    /** Free-text search across customer name/email + reservation ids/alphanum. */
    public static function search($q, $limit = 50) {
        global $wpdb;
        $resa = self::t('resa');
        $cust = self::t('customers');
        $like = '%' . $wpdb->esc_like((string) $q) . '%';

        // Match on reservation id / alphanum / uid, or joined customer email/info.
        $sql = "SELECT r.* FROM {$resa} r
                LEFT JOIN {$cust} c ON c.id = r.customer_id
                WHERE r.alphanum_id LIKE %s
                   OR r.uid LIKE %s
                   OR CAST(r.id AS CHAR) LIKE %s
                   OR c.email LIKE %s
                   OR c.info LIKE %s
                ORDER BY r.id DESC LIMIT %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $like, $like, $like, $like, $like, (int) $limit),
            ARRAY_A
        );
        if (!is_array($rows)) { $rows = []; }
        $out = [];
        foreach ($rows as $r) { $out[] = self::shape_resa($r); }
        return $out;
    }

    public static function customer($id) {
        global $wpdb;
        $cust = self::t('customers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cust} WHERE id = %d", (int) $id), ARRAY_A);
        if (!$row) { return null; }
        $info = self::json_col($row['info'] ?? null);
        if (!is_array($info)) { $info = []; }
        return [
            'id'         => (int) $row['id'],
            'email'      => $row['email'] ?? null,
            'first_name' => $info['first_name'] ?? null,
            'last_name'  => $info['last_name'] ?? null,
            'phone'      => $info['phone'] ?? null,
            'street'     => $info['street'] ?? null,
            'city'       => $info['city'] ?? null,
            'zip'        => $info['zip'] ?? null,
            'country'    => $info['country'] ?? null,
            'info'       => $info,
            'nb_resa'    => isset($row['nb_resa']) ? (int) $row['nb_resa'] : null,
        ];
    }

    /**
     * Unit catalogue. Joins hb_accom_num_name (per-unit names) with the
     * accommodation post titles (hb_accommodation posts -> accom_id = post ID).
     */
    public static function accom_units() {
        global $wpdb;
        $num = self::t('accom_num_name');
        $rows = $wpdb->get_results("SELECT accom_id, accom_num, num_name FROM {$num} ORDER BY accom_id ASC, accom_num ASC", ARRAY_A);
        if (!is_array($rows)) { $rows = []; }

        // Cache accommodation post titles by id.
        $names = [];
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r['accom_id'];
            if (!isset($names[$aid])) {
                $title = null;
                if (function_exists('get_post')) {
                    $post = get_post($aid);
                    if ($post && isset($post->post_title)) { $title = $post->post_title; }
                }
                $names[$aid] = $title;
            }
            $out[] = [
                'accom_id'           => $aid,
                'accom_num'          => (int) $r['accom_num'],
                'num_name'           => $r['num_name'] !== null ? (string) $r['num_name'] : null,
                'accommodation_name' => $names[$aid],
            ];
        }
        return $out;
    }

    /** Accommodation list (posts), with quantity/occupancy postmeta when present. */
    public static function accommodations() {
        if (!function_exists('get_posts')) { return []; }
        $posts = get_posts([
            'post_type'      => 'hb_accommodation',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'accom_id'   => (int) $p->ID,
                'name'       => $p->post_title,
                'quantity'   => (int) get_post_meta($p->ID, 'hb_quantity', true),
                'occupancy'  => (int) get_post_meta($p->ID, 'hb_number_of_people', true),
            ];
        }
        return $out;
    }

    /** Blocked ranges overlapping the window. */
    public static function blocked($from, $to) {
        global $wpdb;
        $t = self::t('accom_blocked');
        $where = ['1=1'];
        $args = [];
        if ($from !== null) { $where[] = 'to_date > %s';   $args[] = $from; }
        if ($to !== null)   { $where[] = 'from_date < %s'; $args[] = $to; }
        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY from_date ASC, id ASC";
        $rows = empty($args)
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) { $rows = []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'accom_id'       => isset($r['accom_id']) ? (int) $r['accom_id'] : null,
                'accom_num'      => isset($r['accom_num']) ? (int) $r['accom_num'] : null,
                'from_date'      => $r['from_date'] ?? null,
                'to_date'        => $r['to_date'] ?? null,
                'linked_resa_id' => isset($r['linked_resa_id']) ? (int) $r['linked_resa_id'] : 0,
                'is_prepa_time'  => !empty($r['is_prepa_time']),
                'comment'        => $r['comment'] ?? null,
                'uid'            => $r['uid'] ?? null,
            ];
        }
        return $out;
    }

    /** Single blocked row (raw-ish), used by delete_block guard. */
    public static function blocked_row($id) {
        global $wpdb;
        $t = self::t('accom_blocked');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
        if (!$row) { return null; }
        $row['id'] = (int) $row['id'];
        $row['linked_resa_id'] = isset($row['linked_resa_id']) ? (int) $row['linked_resa_id'] : 0;
        return $row;
    }

    /** Booking rules. */
    public static function booking_rules() {
        global $wpdb;
        $t = self::t('booking_rules');
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A);
        if (!is_array($rows)) { $rows = []; }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            foreach (['minimum_stay', 'maximum_stay'] as $k) {
                if (isset($r[$k])) { $r[$k] = (int) $r[$k]; }
            }
        }
        unset($r);
        return $rows;
    }

    /** Extras / options catalogue (hb_options + choices). */
    public static function extras() {
        global $wpdb;
        $opt = self::t('options');
        $rows = $wpdb->get_results("SELECT * FROM {$opt} ORDER BY id ASC", ARRAY_A);
        if (!is_array($rows)) { $rows = []; }
        $choices_tbl = self::t('options_choices');
        $out = [];
        foreach ($rows as $r) {
            $oid = (int) $r['id'];
            $choices = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$choices_tbl} WHERE option_id = %d ORDER BY id ASC", $oid),
                ARRAY_A
            );
            if (!is_array($choices)) { $choices = []; }
            $r['id'] = $oid;
            $r['choices'] = $choices;
            $out[] = $r;
        }
        return $out;
    }

    /** Total bookable units for an accom_id: HBook's accom_quantity postmeta
     *  (the denominator native get_unavailable/available uses), falling back to
     *  the unit-catalogue count, min 1. */
    private static function accom_total_units($accom_id) {
        $aid = (int) $accom_id;
        $q = function_exists('get_post_meta') ? get_post_meta($aid, 'accom_quantity', true) : '';
        if ($q !== '' && $q !== null && is_numeric($q)) { return (int) $q; }
        global $wpdb;
        $num = self::t('accom_num_name');
        $c = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT accom_num) FROM {$num} WHERE accom_id = %d", $aid));
        return $c > 0 ? $c : 1;
    }

    /** Distinct accom_ids present in the unit catalogue. */
    private static function all_catalogue_accom_ids() {
        global $wpdb;
        $num = self::t('accom_num_name');
        $rows = $wpdb->get_results("SELECT DISTINCT accom_id FROM {$num} ORDER BY accom_id ASC", ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) { $out[] = (int) $r['accom_id']; }
        return $out;
    }

    /** Distinct reservation accom_nums per accom_id matching $status_clause over the window. */
    private static function resa_nums_by_accom($from, $to, $accom_id, $status_clause, array $extra_args = []) {
        global $wpdb;
        $resa = self::t('resa');
        $where = ["check_in < %s", "check_out > %s", $status_clause];
        $args = array_merge([$to, $from], $extra_args);
        if ($accom_id !== null) { $where[] = 'accom_id = %d'; $args[] = (int) $accom_id; }
        $sql = "SELECT accom_id, accom_num FROM {$resa} WHERE " . implode(' AND ', $where) . " GROUP BY accom_id, accom_num";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) { $out[(int) $r['accom_id']][] = (int) $r['accom_num']; }
        return $out;
    }

    /** Distinct blocked accom_nums per accom_id over the window. */
    private static function block_nums_by_accom($from, $to, $accom_id) {
        global $wpdb;
        $blocked = self::t('accom_blocked');
        $where = ["from_date < %s", "to_date > %s"];
        $args = [$to, $from];
        if ($accom_id !== null) { $where[] = 'accom_id = %d'; $args[] = (int) $accom_id; }
        $sql = "SELECT accom_id, accom_num FROM {$blocked} WHERE " . implode(' AND ', $where) . " GROUP BY accom_id, accom_num";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) { $out[(int) $r['accom_id']][] = (int) $r['accom_num']; }
        return $out;
    }

    /**
     * Availability (CHANGE 1) — three distinct metrics, HBook as authority.
     *
     * Keeps the existing `occupied` list unchanged (native-unavailable
     * reservations UNION blocks over the window). Adds, per accommodation and in
     * totals, three clearly-named metrics:
     *   - physical_occupied: units with a guest physically staying — reservations
     *     whose status ∈ {confirmed, new} and whose stay overlaps the window.
     *   - reserved: units tied up operationally — any non-cancelled reservation
     *     (incl. waiting_payment/pending/multi_resa_pending) UNION blocks.
     *   - bookable_free: units actually bookable per HBook's NATIVE rule
     *     (total − native-unavailable). native-unavailable excludes cancelled and
     *     pending always, and excludes waiting_payment only when the site option
     *     hb_resa_paid_has_confirmation === 'yes' — i.e. this equals
     *     get_unavailable_accom_num_per_date() under the site's current config.
     */
    public static function availability($from, $to, $accom_id = null) {
        global $wpdb;
        $resa = self::t('resa');
        $blocked = self::t('accom_blocked');

        $free = self::free_statuses();
        $placeholders = implode(',', array_fill(0, count($free), '%s'));

        // Occupied by reservations (native-unavailable set).
        $r_where = ["check_in < %s", "check_out > %s", "status NOT IN ($placeholders)"];
        $r_args = array_merge([$to, $from], $free);
        if ($accom_id !== null) { $r_where[] = 'accom_id = %d'; $r_args[] = (int) $accom_id; }
        $r_sql = "SELECT accom_id, accom_num, check_in, check_out, 'reservation' AS src, id AS ref
                  FROM {$resa} WHERE " . implode(' AND ', $r_where);

        // Occupied by blocks.
        $b_where = ["from_date < %s", "to_date > %s"];
        $b_args = [$to, $from];
        if ($accom_id !== null) { $b_where[] = 'accom_id = %d'; $b_args[] = (int) $accom_id; }
        $b_sql = "SELECT accom_id, accom_num, from_date AS check_in, to_date AS check_out, 'block' AS src, id AS ref
                  FROM {$blocked} WHERE " . implode(' AND ', $b_where);

        $r_rows = $wpdb->get_results($wpdb->prepare($r_sql, $r_args), ARRAY_A);
        $b_rows = $wpdb->get_results($wpdb->prepare($b_sql, $b_args), ARRAY_A);
        if (!is_array($r_rows)) { $r_rows = []; }
        if (!is_array($b_rows)) { $b_rows = []; }

        $occupied = [];
        foreach (array_merge($r_rows, $b_rows) as $row) {
            $occupied[] = [
                'accom_id'  => isset($row['accom_id']) ? (int) $row['accom_id'] : null,
                'accom_num' => isset($row['accom_num']) ? (int) $row['accom_num'] : null,
                'from'      => $row['check_in'],
                'to'        => $row['check_out'],
                'source'    => $row['src'],
                'ref_id'    => (int) $row['ref'],
            ];
        }

        // --- Three distinct metrics, per accommodation + totals ---
        $phys = self::resa_nums_by_accom($from, $to, $accom_id, "status IN ('confirmed', 'new')");
        $resv = self::resa_nums_by_accom($from, $to, $accom_id, "status <> 'cancelled'");
        $unav = self::resa_nums_by_accom($from, $to, $accom_id, "status NOT IN ($placeholders)", $free);
        $blk  = self::block_nums_by_accom($from, $to, $accom_id);

        $accom_ids = ($accom_id !== null) ? [(int) $accom_id] : self::all_catalogue_accom_ids();
        foreach ([$phys, $resv, $unav, $blk] as $set) {
            foreach (array_keys($set) as $aid) {
                if (!in_array((int) $aid, $accom_ids, true)) { $accom_ids[] = (int) $aid; }
            }
        }
        sort($accom_ids);

        $by_accom = [];
        $totals = ['total_units' => 0, 'physical_occupied' => 0, 'reserved' => 0, 'bookable_free' => 0];
        foreach ($accom_ids as $aid) {
            $total    = self::accom_total_units($aid);
            $phys_set = array_unique($phys[$aid] ?? []);
            $resv_set = array_unique(array_merge($resv[$aid] ?? [], $blk[$aid] ?? []));
            $unav_set = array_unique(array_merge($unav[$aid] ?? [], $blk[$aid] ?? []));

            $po = count($phys_set);
            $rs = count($resv_set);
            $bf = $total - count($unav_set);
            if ($bf < 0) { $bf = 0; }

            $by_accom[] = [
                'accom_id'          => (int) $aid,
                'total_units'       => (int) $total,
                'physical_occupied' => $po,
                'reserved'          => $rs,
                'bookable_free'     => $bf,
            ];
            $totals['total_units']       += (int) $total;
            $totals['physical_occupied'] += $po;
            $totals['reserved']          += $rs;
            $totals['bookable_free']     += $bf;
        }

        $paid_has_conf = get_option('hb_resa_paid_has_confirmation');
        return [
            'from'     => $from,
            'to'       => $to,
            'accom_id' => $accom_id !== null ? (int) $accom_id : null,
            'occupied' => $occupied,
            'by_accom' => $by_accom,
            'totals'   => $totals,
            // bookable_free follows HBook's own config (CONTRACTS §8, COMPAT_MATRIX).
            'bookable_free_config' => [
                'authority'                      => 'HBook get_unavailable_accom_num_per_date',
                'option'                         => 'hb_resa_paid_has_confirmation',
                'value'                          => $paid_has_conf,
                'waiting_payment_frees_inventory'=> ($paid_has_conf === 'yes'),
            ],
        ];
    }

    /* ================================================================ WRITE plumbing */

    /**
     * Resolve HBook's native action objects.
     *
     * REAL MECHANISM (verified against HBook 2.2.1 source): the active plugin
     * builds its collaborators inside the HBook class and stores them on the
     * global `$hbook` instance ($hbook->hbdb, $hbook->utils, ...). Those props
     * are private, so they cannot be read from outside. The HBook action
     * classes are, however, thin stateless wrappers over the shared global
     * $wpdb, so a fresh instance behaves identically to the one on $hbook:
     *   - HbDataBaseActions has a no-arg constructor (reads global $wpdb).
     *   - HbUtils::__construct($hbdb, $version).
     *   - HbPriceCalc::__construct($hbdb, $utils, $strings)  — HBook builds this
     *     on demand (there is NO price-calc singleton); $strings comes from
     *     HbUtils::get_strings(). We replicate that exactly.
     *
     * When HBook is not loaded, every slot stays null and writes gate to
     * write_disabled. A method_exists() check still guards each call so a 2.0.9
     * site that lacks a newer method degrades to write_disabled, not a fatal.
     */
    private static function hb() {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $cache = ['db' => null, 'price' => null, 'utils' => null, 'ajax' => null];

        try {
            if (class_exists('HbDataBaseActions')) {
                $db = new HbDataBaseActions();
                $cache['db'] = $db;

                if (class_exists('HbUtils')) {
                    $version = defined('HB_VERSION') ? HB_VERSION : '2.2.1';
                    $utils = new HbUtils($db, $version);
                    $cache['utils'] = $utils;

                    // HBook loads utils/price-calc.php on demand (resa.php /
                    // front-end-ajax), so at REST time HbPriceCalc is usually
                    // absent. Load it ourselves from the HBook plugin dir so
                    // pricing quotes (and price recompute) work on 2.1+.
                    if (!class_exists('HbPriceCalc')) {
                        try {
                            $root = dirname(dirname((new \ReflectionClass('HbDataBaseActions'))->getFileName()));
                            $pf = $root . '/utils/price-calc.php';
                            if (is_file($pf)) { require_once $pf; }
                        } catch (\Throwable $e) { /* older HBook without price-calc */ }
                    }

                    if (class_exists('HbPriceCalc') && method_exists($utils, 'get_strings')) {
                        $strings = $utils->get_strings();
                        $cache['price'] = new HbPriceCalc($db, $utils, $strings);
                    }
                }

                if (class_exists('HbFrontEndAjaxActions')) {
                    $cache['ajax'] = new HbFrontEndAjaxActions($db, $cache['utils']);
                }
            }

            // Fallback: a price calculator whose constructor needs no arguments
            // (older HBook shapes / minimal environments).
            if ($cache['price'] === null && class_exists('HbPriceCalc')) {
                $ctor = (new \ReflectionClass('HbPriceCalc'))->getConstructor();
                if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                    $cache['price'] = new HbPriceCalc();
                }
            }
        } catch (\Throwable $e) {
            // Leave resolved slots as-is; unresolved stay null -> write_disabled.
        }
        return $cache;
    }

    private static function write_disabled($note = 'HBook native method unavailable.') {
        return new WP_Error('write_disabled', $note, ['status' => 503]);
    }

    /**
     * The live HBook version. HBook defines no version constant, so fall back
     * to reading the installed plugin's own header — correct across upgrades.
     */
    public static function hbook_version() {
        if (defined('HB_VERSION')) { return HB_VERSION; }
        if (defined('HBOOK_VERSION')) { return HBOOK_VERSION; }
        try {
            if (class_exists('HbDataBaseActions')) {
                $root = dirname(dirname((new \ReflectionClass('HbDataBaseActions'))->getFileName()));
                $main = $root . '/hbook.php';
                if (is_file($main) && function_exists('get_file_data')) {
                    $d = get_file_data($main, ['Version' => 'Version']);
                    if (!empty($d['Version'])) { return $d['Version']; }
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Live capability map — what THIS site's HBook actually supports right now,
     * feature-detected (not guessed from a version). The app gates every
     * feature on these flags, so when WordPress/HBook is upgraded the new
     * capabilities (pricing, payments, price recompute, …) light up
     * automatically with no app or plugin change.
     */
    public static function capabilities() {
        $hb = self::hb();
        $db = $hb['db']; $utils = $hb['utils'];
        $m = function ($o, $n) { return $o && method_exists($o, $n); };
        return [
            'hbook_loaded'       => (bool) $db,
            'move'               => $m($db, 'update_resa_dates'),
            'unit_change'        => $m($db, 'update_resa_accom'),
            'swap'               => $m($db, 'update_resa_accom'),
            'availability_check' => $m($db, 'is_available_accom_num'),
            'dates_check'        => $utils && method_exists($utils, 'can_update_resa_dates'),
            'blocks'             => $m($db, 'add_blocked_accom') && $m($db, 'delete_blocked_accom') && $m($db, 'automatic_block_accom'),
            'status'             => $m($db, 'update_resa_status'),
            'note'               => $m($db, 'update_resa_comment'),
            'guest'              => $m($db, 'update_customer'),
            'create'             => $m($db, 'create_resa'),
            'price_modify'       => $m($db, 'update_resa_price'),
            'payments'           => $m($db, 'add_resa_payment') || $m($utils, 'update_resa_payments'),
            'pricing'            => (bool) $hb['price'],
            'price_recompute'    => (bool) $hb['price'] && class_exists('HbResa'),
            // 2.4.0 — independent of HBook: relay + group money read + signed updates.
            'comgate_relay'      => true,
            'group_payments_read'=> true,
        ];
    }

    /**
     * Gate wrapper. $kind -> write-mode. Returns:
     *   WP_Error('write_disabled') when off,
     *   ['would'=>$would] when dry_run,
     *   null when live (caller proceeds with native call).
     */
    private static function gate($kind, array $would) {
        $mode = MHA_Config::write_mode($kind);
        if ($mode === 'off') {
            return self::write_disabled('Write mode "' . $kind . '" is off.');
        }
        if ($mode === 'dry_run') {
            return ['would' => $would, 'mode' => 'dry_run'];
        }
        return null; // live
    }

    /* ================================================================ WRITES */

    /** Confirm/cancel a single reservation via update_resa_status. */
    public static function set_status($id, $status) {
        // Re-activating a reservation / blocking a unit changes inventory too.
        return self::with_inventory_lock(function () use ($id, $status) {
            return self::set_status_unlocked($id, $status);
        });
    }

    private static function set_status_unlocked($id, $status) {
        $would = ['action' => 'set_status', 'id' => (int) $id, 'status' => $status];
        $g = self::gate('status', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'update_resa_status')) {
            return self::write_disabled('update_resa_status unavailable on this HBook version.');
        }
        $hb['db']->update_resa_status((int) $id, $status);
        return ['action' => 'set_status', 'id' => (int) $id, 'status' => $status, 'mode' => 'live'];
    }

    /**
     * Modify a reservation. Only the price is a safe single-call native change
     * (update_resa_price fires hb_reservation_updated('price')). Date/accommodation
     * changes are intentionally NOT supported here: HBook's update_resa_dates does
     * not move the accommodation block, so changing dates without re-running the
     * block orchestration would desync availability — we refuse rather than invent
     * that path. Gated by the 'modify' write-mode.
     */
    public static function modify_reservation($id, array $fields) {
        $id = (int) $id;
        $move_keys = ['check_in', 'check_out', 'accom_id', 'accom_num'];
        $has_move  = (bool) array_intersect($move_keys, array_keys($fields));
        $has_price = array_key_exists('price', $fields) || array_key_exists('accom_price', $fields);

        // Date / accommodation change → full move with block re-orchestration.
        // A price can ride along in the same call (applied after the move).
        if ($has_move) {
            return self::move_reservation($id, $fields);
        }

        $unsupported = array_diff(array_keys($fields), ['price', 'accom_price']);
        $would = ['action' => 'modify_reservation', 'id' => $id, 'fields' => $fields];
        $g = self::gate('modify', $would);
        if ($g !== null) { return $g; }

        if (!empty($unsupported)) {
            return new WP_Error('unsupported', 'This field is not modifiable via the API. Unsupported: ' . implode(', ', $unsupported), ['status' => 503]);
        }
        if (!$has_price) {
            return new WP_Error('bad_request', 'No modifiable field supplied (price).', ['status' => 400]);
        }

        $cur = self::reservation($id);
        if (!$cur) { return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]); }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'update_resa_price')) {
            return self::write_disabled('update_resa_price unavailable on this HBook version.');
        }
        $new_price = (float) (array_key_exists('price', $fields) ? $fields['price'] : $fields['accom_price']);
        $prev = isset($cur['price']) ? (float) $cur['price'] : 0.0;
        $hb['db']->update_resa_price($id, $prev, $new_price);

        $after = self::reservation($id);
        return [
            'action'         => 'modify_reservation',
            'id'             => $id,
            'changed'        => ['price'],
            'previous_price' => $prev,
            'price'          => $after && isset($after['price']) ? (float) $after['price'] : $new_price,
            'mode'           => 'live',
        ];
    }

    /* ------------------------------------------------------- inventory lock */

    const INVENTORY_LOCK = 'mha_inventory_write';
    const INVENTORY_LOCK_WAIT = 10; // seconds

    /**
     * Serialise every write that can change which unit is occupied when
     * (create, move, swap). HBook's own availability check and the write that
     * follows are not atomic; without this, two near-simultaneous requests can
     * both pass the check and double-book a unit.
     *
     * MySQL GET_LOCK is connection-scoped and released on disconnect, so a
     * crashed request can never leave the site locked. Returns:
     *   - the callback's result when the lock was held (or the database has no
     *     named locks at all — then behaviour is exactly as before),
     *   - WP_Error 'busy' (HTTP 423) when another write held the lock for the
     *     whole wait. Nothing was written; the caller may retry.
     */
    public static function with_inventory_lock(callable $fn) {
        global $wpdb;
        $name = self::INVENTORY_LOCK . '_' . (isset($wpdb->prefix) ? $wpdb->prefix : '');
        $got = self::named_lock('GET_LOCK', $name, self::INVENTORY_LOCK_WAIT);
        if ($got === 0) {
            return new WP_Error('busy', 'Jiný zápis rezervace právě probíhá. Zkuste to prosím znovu.', ['status' => 423]);
        }
        try {
            return $fn();
        } finally {
            if ($got === 1) { self::named_lock('RELEASE_LOCK', $name, null); }
        }
    }

    /** 1 = acquired/released, 0 = timed out, null = named locks unavailable. */
    private static function named_lock($op, $name, $wait) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) { return null; }
        $can_suppress = method_exists($wpdb, 'suppress_errors');
        $prev = $can_suppress ? $wpdb->suppress_errors(true) : null;
        try {
            $sql = $wait === null
                ? $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)
                : $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, (int) $wait);
            $v = $wpdb->get_var($sql);
            if ($v === null || $v === false || $v === '') { return null; }
            return ((int) $v) === 1 ? 1 : 0;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($can_suppress) { $wpdb->suppress_errors((bool) $prev); }
        }
    }

    /**
     * Move a reservation to new dates and/or unit. This MIRRORS HBook's own
     * calendar move exactly (admin-ajax hb_update_resa_accom / hb_update_resa_dates,
     * verified against HBook 2.2.1 source), so availability stays consistent the
     * same way the native drag-and-drop does:
     *   - Unit change → is_available_accom_num() guard, then update_resa_accom()
     *     (which itself DELETEs the reservation's old linked blocks and sets the
     *     new unit), then automatic_block_accom() for the new slot.
     *   - Date change → can_update_resa_dates() guard (delta-aware: only the
     *     net-new range must be free, so an in-place extend/shift never conflicts
     *     with itself), then update_resa_dates() (also deletes old linked blocks),
     *     then automatic_block_accom().
     * Blocks are recreated only for active reservations (new / confirmed), just
     * like HBook. Older HBook builds that lack the native checks fall back to a
     * local overlap check. Gated by the 'modify' write-mode; a price may ride along.
     */
    public static function move_reservation($id, array $fields) {
        return self::with_inventory_lock(function () use ($id, $fields) {
            return self::move_reservation_unlocked($id, $fields);
        });
    }

    private static function move_reservation_unlocked($id, array $fields) {
        $id = (int) $id;
        $would = ['action' => 'move_reservation', 'id' => $id, 'fields' => $fields];
        $g = self::gate('modify', $would);
        if ($g !== null) { return $g; }

        $cur = self::reservation($id);
        if (!$cur) { return new WP_Error('not_found', 'Reservation not found.', ['status' => 404]); }

        $old_ci = (string) ($cur['check_in'] ?? '');
        $old_co = (string) ($cur['check_out'] ?? '');
        $old_aid = (int) ($cur['accom_id'] ?? 0);
        $old_anum = (int) ($cur['accom_num'] ?? 0);

        $new_ci   = (isset($fields['check_in'])  && $fields['check_in']  !== '') ? (string) $fields['check_in']  : $old_ci;
        $new_co   = (isset($fields['check_out']) && $fields['check_out'] !== '') ? (string) $fields['check_out'] : $old_co;
        $new_aid  = (isset($fields['accom_id'])  && $fields['accom_id']  !== '') ? (int) $fields['accom_id']     : $old_aid;
        $new_anum = (isset($fields['accom_num']) && $fields['accom_num'] !== '') ? (int) $fields['accom_num']    : $old_anum;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_ci) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_co)) {
            return new WP_Error('bad_request', 'check_in/check_out must be YYYY-MM-DD.', ['status' => 400]);
        }
        if (strtotime($new_co) <= strtotime($new_ci)) {
            return new WP_Error('bad_request', 'check_out must be after check_in.', ['status' => 400]);
        }

        $dates_changed = ($new_ci !== $old_ci) || ($new_co !== $old_co);
        $unit_changed  = ($new_aid !== $old_aid) || ($new_anum !== $old_anum);
        if (!$dates_changed && !$unit_changed) {
            return new WP_Error('bad_request', 'Nothing to move (dates and unit unchanged).', ['status' => 400]);
        }

        $hb = self::hb();
        $db = $hb['db'];
        $utils = $hb['utils'];
        if (!$db) { return self::write_disabled('HBook not loaded.'); }
        if (!method_exists($db, 'automatic_block_accom')) {
            return self::write_disabled('automatic_block_accom unavailable on this HBook version.');
        }
        if ($new_aid <= 0 || $new_anum <= 0) {
            return new WP_Error('bad_request', 'accom_id and accom_num must be positive.', ['status' => 400]);
        }

        // HBook re-blocks only for active reservations (new / confirmed).
        $status = (string) ($cur['status'] ?? '');
        $should_block = in_array($status, ['new', 'confirmed'], true);

        // ---- Unit change — mirrors hb_update_resa_accom ----
        // update_resa_accom() itself DELETEs the old linked blocks and sets the
        // new unit; the availability guard ignores the old unit so a real move
        // is clean. Do this first when both unit and dates change.
        if ($unit_changed) {
            if (!method_exists($db, 'update_resa_accom')) {
                return self::write_disabled('update_resa_accom unavailable on this HBook version.');
            }
            $free = method_exists($db, 'is_available_accom_num')
                ? (bool) $db->is_available_accom_num($new_aid, $new_anum, $new_ci, $new_co)
                : empty(self::target_blocked($new_aid, $new_anum, $new_ci, $new_co, $id));
            if (!$free) {
                return new WP_Error('conflict', 'Cílová jednotka je v požadovaném termínu obsazená.', ['status' => 409]);
            }
            $db->update_resa_accom($id, $new_aid, $new_anum);
        }

        // ---- Date change — mirrors hb_update_resa_dates ----
        // can_update_resa_dates() is the delta-aware guard (in-place extend/shift
        // never self-conflicts); update_resa_dates() deletes old linked blocks too.
        if ($dates_changed) {
            if (!method_exists($db, 'update_resa_dates')) {
                return self::write_disabled('update_resa_dates unavailable on this HBook version.');
            }
            if (!$unit_changed) {
                $ok = ($utils && method_exists($utils, 'can_update_resa_dates'))
                    ? (bool) $utils->can_update_resa_dates($id, $new_ci, $new_co)
                    : empty(self::target_blocked($new_aid, $new_anum, $new_ci, $new_co, $id));
                if (!$ok) {
                    return new WP_Error('conflict', 'Termín je pro tuto jednotku v požadovaném rozsahu obsazený.', ['status' => 409]);
                }
            }
            $db->update_resa_dates($id, $new_ci, $new_co);
        }

        // ---- Recreate the inventory blocks for the new slot ----
        $reblock = null;
        if ($should_block) {
            try {
                $reblock = $db->automatic_block_accom($new_aid, $new_anum, $new_ci, $new_co, $id);
            } catch (\Throwable $e) {
                $reblock = ['error' => $e->getMessage()];
            }
        }

        // Recompute the price for the new dates/unit exactly as HBook's own
        // calendar does (HbResa::refresh_price → HbPriceCalc). 2.2.1-only; on
        // older HBook it's a no-op and the app can send a price instead.
        $price_recomputed = self::recompute_price($id);

        // Explicit caller-supplied price overrides the recompute (manual total).
        if (array_key_exists('price', $fields) || array_key_exists('accom_price', $fields)) {
            if (method_exists($db, 'update_resa_price')) {
                $new_price = (float) (array_key_exists('price', $fields) ? $fields['price'] : $fields['accom_price']);
                $after0 = self::reservation($id);
                $prev = ($after0 && isset($after0['price'])) ? (float) $after0['price']
                      : (isset($cur['price']) ? (float) $cur['price'] : 0.0);
                try { $db->update_resa_price($id, $prev, $new_price); $price_recomputed = true; } catch (\Throwable $e) {}
            }
        }

        $after = self::reservation($id);
        $changed = [];
        if ($dates_changed) { $changed[] = 'dates'; }
        if ($unit_changed)  { $changed[] = 'unit'; }
        return [
            'action'      => 'move_reservation',
            'id'          => $id,
            'from'        => ['check_in' => $old_ci, 'check_out' => $old_co, 'accom_id' => $old_aid, 'accom_num' => $old_anum],
            'to'          => ['check_in' => $new_ci, 'check_out' => $new_co, 'accom_id' => $new_aid, 'accom_num' => $new_anum],
            'changed'     => $changed,
            'price_recomputed' => $price_recomputed,
            'reservation' => $after,
            'mode'        => 'live',
        ];
    }

    /** Native price recompute for one reservation (HbResa::refresh_price). */
    private static function recompute_price($id) {
        $hb = self::hb();
        if (!class_exists('HbResa') || !$hb['db'] || !$hb['utils']) { return false; }
        try {
            $resa = new HbResa($hb['db'], $hb['utils']);
            $resa->load((int) $id);
            $resp = method_exists($resa, 'refresh_price') ? $resa->refresh_price() : null;
            return (is_array($resp) && ($resp['status'] ?? '') === 'price_updated');
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Rows in hb_accom_blocked that occupy (accom_id, accom_num) during
     * [from, to), excluding any block linked to $exclude_resa_id. Half-open
     * overlap: block.from < to AND block.to > from.
     */
    private static function target_blocked($accom_id, $accom_num, $from, $to, $exclude_resa_id = 0) {
        global $wpdb;
        $t = self::t('accom_blocked');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, from_date, to_date, linked_resa_id, is_prepa_time FROM {$t}
             WHERE accom_id = %d AND accom_num = %d
               AND from_date < %s AND to_date > %s
               AND linked_resa_id <> %d
             ORDER BY from_date ASC LIMIT 20",
            (int) $accom_id, (int) $accom_num, $to, $from, (int) $exclude_resa_id
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = [
                'block_id'       => (int) $r['id'],
                'from'           => $r['from_date'],
                'to'             => $r['to_date'],
                'linked_resa_id' => (int) $r['linked_resa_id'],
                'is_prep'        => !empty($r['is_prepa_time']),
            ];
        }
        return $out;
    }


    /**
     * Read-only diagnostic: the real signatures of the HBook methods the move
     * path depends on, so the move can be verified per install before use.
     * Admin scope. Never mutates.
     */
    public static function diag_hbook_methods() {
        $hb = self::hb();
        $db = $hb['db'];
        $out = [
            'hbook_loaded'   => (bool) $db,
            'db_class'       => $db ? get_class($db) : null,
            'methods'        => [],
            'accom_change_candidates' => [],
        ];
        $want = ['update_resa_dates', 'automatic_block_accom', 'delete_blocked_accom', 'add_blocked_accom', 'update_resa_price', 'update_resa_status', 'create_resa'];
        foreach ($want as $m) {
            if ($db && method_exists($db, $m)) {
                try {
                    $ref = new \ReflectionMethod($db, $m);
                    $params = [];
                    foreach ($ref->getParameters() as $p) {
                        $params[] = $p->getName() . ($p->isOptional() ? '?' : '');
                    }
                    $out['methods'][$m] = ['exists' => true, 'params' => $params];
                } catch (\Throwable $e) {
                    $out['methods'][$m] = ['exists' => true, 'params' => null];
                }
            } else {
                $out['methods'][$m] = ['exists' => false];
            }
        }
        // Any method that might change a reservation's unit / move it directly.
        if ($db) {
            foreach (get_class_methods($db) as $m) {
                if (preg_match('/accom|unit|move|swap|transfer|reassign|edit|update/i', $m) && preg_match('/resa|reservation|book/i', $m)) {
                    $out['accom_change_candidates'][] = $m;
                }
            }
            // Full method list of the DB class so a native move/swap can be spotted.
            $out['all_db_methods'] = get_class_methods($db);
        }
        // Other HBook classes that may own the native calendar-move logic.
        $out['hbook_classes'] = [];
        foreach (get_declared_classes() as $cls) {
            if (stripos($cls, 'Hb') === 0 || stripos($cls, 'hbook') !== false) {
                $moveish = [];
                foreach (get_class_methods($cls) as $m) {
                    if (preg_match('/move|swap|transfer|reassign|accom|dates|edit_resa|update_resa/i', $m)) {
                        $moveish[] = $m;
                    }
                }
                if ($moveish) { $out['hbook_classes'][$cls] = $moveish; }
            }
        }
        return $out;
    }

    /**
     * Is (accom_id, accom_num) occupied during [ci, co) by an active reservation
     * or a block, ignoring the given reservation ids (and their own blocks)?
     * Used by the swap feasibility check, which must exclude BOTH reservations.
     */
    private static function unit_occupied($aid, $anum, $ci, $co, array $exclude = []) {
        global $wpdb;
        $aid = (int) $aid; $anum = (int) $anum;
        $exclude = array_values(array_unique(array_map('intval', $exclude)));
        $notIn  = $exclude ? (' AND id NOT IN (' . implode(',', $exclude) . ')') : '';
        $rt = self::t('reservations');
        $resa = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rt}
             WHERE accom_id = %d AND accom_num = %d
               AND (status IS NULL OR LOWER(status) NOT LIKE %s)
               AND check_in < %s AND check_out > %s" . $notIn,
            $aid, $anum, '%cancel%', $co, $ci
        ));
        if ((int) $resa > 0) { return true; }
        $bt = self::t('accom_blocked');
        $notInB = $exclude ? (' AND (linked_resa_id IS NULL OR linked_resa_id NOT IN (' . implode(',', $exclude) . '))') : '';
        $blk = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
             WHERE accom_id = %d AND accom_num = %d
               AND from_date < %s AND to_date > %s" . $notInB,
            $aid, $anum, $co, $ci
        ));
        return ((int) $blk > 0);
    }

    /**
     * Swap the units of two reservations (dates stay). Mirrors the native
     * calendar's drop-on-reservation swap: update_resa_accom() on each (it
     * deletes that reservation's old blocks and sets the new unit), then
     * automatic_block_accom() for each new slot. Feasibility is checked up
     * front excluding BOTH reservations, so A can take B's unit and vice-versa.
     * No temporary "park" unit is needed server-side (occupancy is computed,
     * not enforced between the two row updates). Gated by the 'modify' mode.
     */
    public static function swap_reservations($a_id, $b_id) {
        return self::with_inventory_lock(function () use ($a_id, $b_id) {
            return self::swap_reservations_unlocked($a_id, $b_id);
        });
    }

    private static function swap_reservations_unlocked($a_id, $b_id) {
        $a_id = (int) $a_id; $b_id = (int) $b_id;
        $would = ['action' => 'swap', 'a' => $a_id, 'b' => $b_id];
        $g = self::gate('modify', $would);
        if ($g !== null) { return $g; }
        if ($a_id === $b_id) {
            return new WP_Error('bad_request', 'Cannot swap a reservation with itself.', ['status' => 400]);
        }
        $A = self::reservation($a_id);
        if (!$A) { return new WP_Error('not_found', 'Reservation A not found.', ['status' => 404]); }
        $B = self::reservation($b_id);
        if (!$B) { return new WP_Error('not_found', 'Reservation B not found.', ['status' => 404]); }

        $hb = self::hb(); $db = $hb['db'];
        if (!$db || !method_exists($db, 'update_resa_accom') || !method_exists($db, 'automatic_block_accom')) {
            return self::write_disabled('update_resa_accom/automatic_block_accom unavailable on this HBook version.');
        }

        $Aaid = (int) $A['accom_id']; $Aanum = (int) $A['accom_num']; $Aci = (string) $A['check_in']; $Aco = (string) $A['check_out'];
        $Baid = (int) $B['accom_id']; $Banum = (int) $B['accom_num']; $Bci = (string) $B['check_in']; $Bco = (string) $B['check_out'];
        if ($Aaid <= 0 || $Aanum <= 0 || $Baid <= 0 || $Banum <= 0) {
            return new WP_Error('bad_request', 'Both reservations must have a unit.', ['status' => 400]);
        }
        if ($Aaid === $Baid && $Aanum === $Banum) {
            return new WP_Error('bad_request', 'Both reservations are already in the same unit.', ['status' => 400]);
        }
        foreach (['A' => $A, 'B' => $B] as $lbl => $r) {
            if (strpos(strtolower((string) ($r['status'] ?? '')), 'cancel') !== false) {
                return new WP_Error('conflict', 'Zrušenou rezervaci nelze prohodit.', ['status' => 409]);
            }
        }
        // Feasibility: A into B's unit for A's dates, B into A's unit for B's
        // dates — both excluding A and B themselves.
        if (self::unit_occupied($Baid, $Banum, $Aci, $Aco, [$a_id, $b_id])) {
            return new WP_Error('conflict', 'Cílová chatka není po prohození volná.', ['status' => 409]);
        }
        if (self::unit_occupied($Aaid, $Aanum, $Bci, $Bco, [$a_id, $b_id])) {
            return new WP_Error('conflict', 'Původní chatka není po prohození volná.', ['status' => 409]);
        }

        $activeA = in_array(strtolower((string) ($A['status'] ?? '')), ['new', 'confirmed'], true);
        $activeB = in_array(strtolower((string) ($B['status'] ?? '')), ['new', 'confirmed'], true);

        try {
            $db->update_resa_accom($a_id, $Baid, $Banum);
            $db->update_resa_accom($b_id, $Aaid, $Aanum);
        } catch (\Throwable $e) {
            // Best-effort restore of the original units.
            try { $db->update_resa_accom($a_id, $Aaid, $Aanum); } catch (\Throwable $e2) {}
            try { $db->update_resa_accom($b_id, $Baid, $Banum); } catch (\Throwable $e2) {}
            return new WP_Error('upstream_error', 'Swap failed and was restored: ' . $e->getMessage(), ['status' => 502]);
        }
        if ($activeA) { try { $db->automatic_block_accom($Baid, $Banum, $Aci, $Aco, $a_id); } catch (\Throwable $e) {} }
        if ($activeB) { try { $db->automatic_block_accom($Aaid, $Aanum, $Bci, $Bco, $b_id); } catch (\Throwable $e) {} }

        // Recompute both prices for their new units (HBook does this on a move).
        $pa = self::recompute_price($a_id);
        $pb = self::recompute_price($b_id);

        return [
            'action'       => 'swap',
            'a'            => ['id' => $a_id, 'to' => ['accom_id' => $Baid, 'accom_num' => $Banum]],
            'b'            => ['id' => $b_id, 'to' => ['accom_id' => $Aaid, 'accom_num' => $Aanum]],
            'price_recomputed' => ['a' => $pa, 'b' => $pb],
            'reservations' => ['a' => self::reservation($a_id), 'b' => self::reservation($b_id)],
            'mode'         => 'live',
        ];
    }

    /** Cancel a whole group via cancel_parent_resa. */
    public static function cancel_group($id) {
        $would = ['action' => 'cancel_group', 'parent_id' => (int) $id];
        $g = self::gate('status', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'cancel_parent_resa')) {
            return self::write_disabled('cancel_parent_resa unavailable on this HBook version.');
        }
        $hb['db']->cancel_parent_resa((int) $id);
        return ['action' => 'cancel_group', 'parent_id' => (int) $id, 'mode' => 'live'];
    }

    /** Append/overwrite admin comment via update_resa_comment. */
    public static function add_note($id, $text) {
        $would = ['action' => 'add_note', 'id' => (int) $id, 'text' => $text];
        $g = self::gate('note', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'update_resa_comment')) {
            return self::write_disabled('update_resa_comment unavailable on this HBook version.');
        }
        // REAL signature (verified): update_resa_comment($id, $is_parent, $comment).
        // Single reservation => is_parent = false.
        $hb['db']->update_resa_comment((int) $id, false, (string) $text);
        return ['action' => 'add_note', 'id' => (int) $id, 'mode' => 'live'];
    }

    /** Create a block via add_blocked_accom. */
    public static function create_block($accom_id, $accom_num, $from, $to, $comment = '') {
        // Re-activating a reservation / blocking a unit changes inventory too.
        return self::with_inventory_lock(function () use ($accom_id, $accom_num, $from, $to, $comment) {
            return self::create_block_unlocked($accom_id, $accom_num, $from, $to, $comment);
        });
    }

    private static function create_block_unlocked($accom_id, $accom_num, $from, $to, $comment = '') {
        $would = [
            'action' => 'create_block', 'accom_id' => (int) $accom_id, 'accom_num' => (int) $accom_num,
            'from' => $from, 'to' => $to, 'comment' => $comment,
        ];
        $g = self::gate('block', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'add_blocked_accom')) {
            return self::write_disabled('add_blocked_accom unavailable on this HBook version.');
        }
        // REAL signature (verified): add_blocked_accom($accom_id, $accom_num,
        //   $accom_all_ids, $accom_all_num, $from_date, $to_date, $comment,
        //   $linked_resa_id = 0, $is_prepa_time = 0). A single-unit manual block
        //   passes accom_all_ids=0, accom_all_num=0 and no linked reservation.
        $ok = $hb['db']->add_blocked_accom((int) $accom_id, (int) $accom_num, 0, 0, $from, $to, (string) $comment, 0, 0);
        if ($ok === false) {
            return new WP_Error('upstream_error', 'HBook add_blocked_accom failed.', ['status' => 502]);
        }
        // add_blocked_accom returns bool, not the row id — look the new block up so
        // consumers get a stable id they can later DELETE /blocked/{id}.
        global $wpdb;
        $t = self::t('accom_blocked');
        $new_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE accom_id = %d AND accom_num = %d AND from_date = %s AND to_date = %s ORDER BY id DESC LIMIT 1",
            (int) $accom_id, (int) $accom_num, $from, $to
        ));
        return array_merge($would, ['id' => $new_id ?: null, 'mode' => 'live']);
    }

    /** Delete a block; refuse if it is linked to a reservation (linked_resa_id>0). */
    public static function delete_block($id) {
        $row = self::blocked_row($id);
        if (!$row) {
            return new WP_Error('not_found', 'Block not found.', ['status' => 404]);
        }
        if ((int) $row['linked_resa_id'] > 0) {
            return new WP_Error('conflict', 'Block is linked to a reservation and cannot be deleted directly.', ['status' => 409]);
        }

        $would = ['action' => 'delete_block', 'id' => (int) $id];
        $g = self::gate('block', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'delete_blocked_accom')) {
            return self::write_disabled('delete_blocked_accom unavailable on this HBook version.');
        }
        // REAL signature (verified): delete_blocked_accom($date_from, $date_to,
        //   $accom_id, $accom_num, $accom_all_ids, $accom_all_num). HBook deletes
        //   blocks by (date range + accom), NOT by row id — so we supply the
        //   looked-up row's own fields to delete exactly this block.
        $hb['db']->delete_blocked_accom(
            $row['from_date'],
            $row['to_date'],
            (int) ($row['accom_id'] ?? 0),
            (int) ($row['accom_num'] ?? 0),
            (int) ($row['accom_all_ids'] ?? 0),
            (int) ($row['accom_all_num'] ?? 0)
        );
        return array_merge($would, ['mode' => 'live']);
    }

    /**
     * Add a payment — appends to payments_logs via HBook's native model,
     * NEVER the flat `paid` column.
     */
    public static function add_payment($id, $is_parent, array $payment) {
        $would = ['action' => 'add_payment', 'id' => (int) $id, 'is_parent' => (bool) $is_parent, 'payment' => $payment];
        $g = self::gate('payment', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        // Prefer HbDataBaseActions::add_resa_payment($resa_id,$is_parent,$payment).
        if ($hb['db'] && method_exists($hb['db'], 'add_resa_payment')) {
            $hb['db']->add_resa_payment((int) $id, (bool) $is_parent, $payment);
            return array_merge($would, ['mode' => 'live', 'via' => 'add_resa_payment']);
        }
        // Fallback: HbUtils::update_resa_payments(...).
        if ($hb['utils'] && method_exists($hb['utils'], 'update_resa_payments')) {
            $hb['utils']->update_resa_payments((int) $id, (bool) $is_parent, $payment);
            return array_merge($would, ['mode' => 'live', 'via' => 'update_resa_payments']);
        }
        return self::write_disabled('No native payments_logs method (add_resa_payment/update_resa_payments) on this HBook version.');
    }

    /** Update guest fields on hb_customers.info via native update_customer*. */
    public static function update_guest($customer_id, array $fields) {
        $would = ['action' => 'update_guest', 'customer_id' => (int) $customer_id, 'fields' => $fields];
        $g = self::gate('guest', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'update_customer')) {
            return self::write_disabled('update_customer unavailable on this HBook version.');
        }

        // REAL signature (verified): update_customer($id, $email, $info) where
        // $info REPLACES the whole hb_customers.info JSON. HBook has no partial
        // merge, so we read the current record, merge the requested fields into
        // info, and pass the full merged structure — never clobbering unrelated
        // fields. 'email' is a top-level column, not part of info.
        $current = self::customer((int) $customer_id);
        if (!$current) {
            return new WP_Error('not_found', 'Customer not found.', ['status' => 404]);
        }
        $info = (isset($current['info']) && is_array($current['info'])) ? $current['info'] : [];
        $email = $current['email'];

        foreach ($fields as $k => $v) {
            if ($k === 'email') { $email = (string) $v; continue; }
            $info[$k] = $v;
        }

        $hb['db']->update_customer((int) $customer_id, (string) $email, $info);
        return array_merge($would, ['mode' => 'live']);
    }

    /**
     * Price quote via HbPriceCalc::get_price. Read-like but routed through native
     * pricing; not gated (no mutation). Returns the computed price structure or WP_Error.
     */
    public static function quote($accom_id, $check_in, $check_out, $adults, $children = 0) {
        $hb = self::hb();
        if (!$hb['price'] || !method_exists($hb['price'], 'get_price')) {
            // Read op (not a mutation): report as unsupported-on-this-version, not write_disabled.
            return new WP_Error('unsupported', 'HbPriceCalc::get_price unavailable; live quoting needs HBook 2.1+ (this site is older).', ['status' => 503]);
        }
        $breakdown = [];
        // HBook 2.2.1 signature: get_price($accom_id,$check_in,$check_out,$adults,$children,&$breakdown).
        // REAL return shape (verified): array('success'=>bool, 'prices'=>array(
        //   'accom'=>float, 'discount'=>array|0, 'accom_total'=>float)) on success,
        //   or array('success'=>false,'error'=>string) when a season/rate is missing.
        // Older/minimal shapes may return a bare numeric total — both are handled.
        try {
            $result = $hb['price']->get_price((int) $accom_id, $check_in, $check_out, (int) $adults, (int) $children, $breakdown);
        } catch (\Throwable $e) {
            return new WP_Error('upstream_error', 'Price calculation failed: ' . $e->getMessage(), ['status' => 502]);
        }

        $price = null;
        $prices = null;
        if (is_array($result)) {
            if (isset($result['success']) && !$result['success']) {
                $msg = isset($result['error']) ? (string) $result['error'] : 'Price could not be calculated (missing season/rate).';
                return new WP_Error('upstream_error', $msg, ['status' => 502]);
            }
            $prices = isset($result['prices']) && is_array($result['prices']) ? $result['prices'] : null;
            if ($prices !== null && isset($prices['accom_total'])) {
                $price = (float) $prices['accom_total'];
            } elseif ($prices !== null && isset($prices['accom'])) {
                $price = (float) $prices['accom'];
            }
        } elseif (is_numeric($result)) {
            $price = (float) $result;
        }

        if ($price === null) {
            return new WP_Error('upstream_error', 'HbPriceCalc returned an unrecognized price shape.', ['status' => 502]);
        }

        return [
            'accom_id'  => (int) $accom_id,
            'check_in'  => $check_in,
            'check_out' => $check_out,
            'adults'    => (int) $adults,
            'children'  => (int) $children,
            'price'     => $price,
            'prices'    => $prices,
            'breakdown' => $breakdown,
        ];
    }

    /** Columns the API is allowed to set directly on create (whitelist). */
    const CREATE_COLS = [
        'check_in', 'check_out', 'accom_id', 'accom_num', 'adults', 'children',
        'accom_price', 'price', 'previous_price', 'deposit', 'amount_to_pay',
        'currency', 'customer_id', 'status', 'discount', 'options', 'fees',
        'additional_info', 'admin_comment', 'lang', 'coupon', 'origin', 'parent_id',
    ];

    /** Guest `info` fields accepted when creating a customer on the fly. */
    const CUSTOMER_INFO_COLS = [
        'first_name', 'last_name', 'phone', 'country_iso', 'usa_state_iso',
        'canada_province_iso', 'address', 'city', 'zip_code', 'company', 'additional_info',
    ];

    /**
     * Resolve the customer for a create payload.
     *   - explicit customer_id wins;
     *   - otherwise a nested `customer`/`guest` object (or top-level guest fields)
     *     with at least a name or email creates a customer via HBook's native
     *     create_customer($email,$info) and returns the new id;
     *   - nothing usable returns 0 (reservation created without a customer).
     * Never blocks the reservation: on any failure (unsupported HBook version,
     * DB error) it returns 0 so the booking is still created — the caller
     * reports customer_id:0 and the app can warn that the guest wasn't attached.
     * Returns int customer_id (>0), or 0 when nothing was attached.
     */
    private static function resolve_customer_for_create($hb, array $payload) {
        if (!empty($payload['customer_id'])) { return (int) $payload['customer_id']; }

        $src = [];
        foreach (['customer', 'guest'] as $k) {
            if (isset($payload[$k]) && is_array($payload[$k])) { $src = $payload[$k]; break; }
        }
        if (!$src) { $src = $payload; } // top-level fallback

        $info = [];
        foreach (self::CUSTOMER_INFO_COLS as $col) {
            if (isset($src[$col]) && $src[$col] !== '') {
                $info[$col] = sanitize_text_field((string) $src[$col]);
            }
        }
        $email = (isset($src['email']) && is_string($src['email'])) ? sanitize_email($src['email']) : '';

        // No name and no email → nothing to attach.
        if (empty($info['first_name']) && empty($info['last_name']) && $email === '') {
            return 0;
        }
        // Degrade gracefully on HBook versions without create_customer.
        if (!$hb['db'] || !method_exists($hb['db'], 'create_customer')) {
            return 0;
        }
        try {
            $cid = $hb['db']->create_customer($email, $info);
        } catch (\Throwable $e) {
            return 0;
        }
        return $cid ? (int) $cid : 0;
    }

    /**
     * Create a reservation through HBook's native data layer.
     *
     * REAL PATH (verified against HBook 2.2.1): HbFrontEndAjaxActions::hb_create_resa()
     * takes NO arguments — it reads $_POST, echoes JSON and die()s, so it CANNOT
     * be driven programmatically with a payload (the old adapter's
     * `hb_create_resa($payload)` call was never valid). The safe, server-side
     * create is HbDataBaseActions::create_resa($resa_info) — which inserts the
     * row, fires hb_create_reservation + hb_reservations_updated and writes a
     * resa_logs row — followed by automatic_block_accom() to create the
     * preparation-time / linked blocks exactly as the native orchestration does.
     *
     * Price is NEVER reimplemented: the caller must supply an authoritative
     * price (obtained from /pricing/quote -> HbPriceCalc). Gated by write-mode
     * 'create' (OFF by default).
     */
    public static function create_reservation(array $payload) {
        return self::with_inventory_lock(function () use ($payload) {
            return self::create_reservation_unlocked($payload);
        });
    }

    private static function create_reservation_unlocked(array $payload) {
        $would = ['action' => 'create_reservation', 'payload' => $payload];
        $g = self::gate('create', $would);
        if ($g !== null) { return $g; }

        $hb = self::hb();
        if (!$hb['db'] || !method_exists($hb['db'], 'create_resa')) {
            return self::write_disabled('create_resa unavailable on this HBook version.');
        }

        // Minimal required fields for a safe, consistent row.
        foreach (['check_in', 'check_out', 'accom_id', 'accom_num', 'adults'] as $req) {
            if (!isset($payload[$req]) || $payload[$req] === '' || $payload[$req] === null) {
                return new WP_Error('bad_request', "Missing required field: {$req}.", ['status' => 400]);
            }
        }

        // Resolve/create the guest so the reservation carries a customer (name).
        // Never blocks the booking: 0 means no customer was attached.
        $cid = self::resolve_customer_for_create($hb, $payload);
        if ($cid > 0) { $payload['customer_id'] = $cid; }

        // Whitelist columns; never pass arbitrary keys into the native insert.
        $resa_info = [];
        foreach (self::CREATE_COLS as $col) {
            if (array_key_exists($col, $payload)) { $resa_info[$col] = $payload[$col]; }
        }
        if (!isset($resa_info['status']) || $resa_info['status'] === '') {
            $resa_info['status'] = 'new';
        }
        if (!isset($resa_info['children'])) { $resa_info['children'] = 0; }

        try {
            $new_id = $hb['db']->create_resa($resa_info);
        } catch (\Throwable $e) {
            return new WP_Error('upstream_error', 'create_reservation failed: ' . $e->getMessage(), ['status' => 502]);
        }
        if (!$new_id) {
            return new WP_Error('upstream_error', 'HBook create_resa returned no id.', ['status' => 502]);
        }

        // Replicate the native orchestration's automatic blocks (prep time /
        // linked accommodations). Best-effort — a failure here does not undo the
        // reservation, which is already committed and hook-announced.
        $blocks = null;
        if (method_exists($hb['db'], 'automatic_block_accom')) {
            try {
                $blocks = $hb['db']->automatic_block_accom(
                    (int) $resa_info['accom_id'], (int) $resa_info['accom_num'],
                    $resa_info['check_in'], $resa_info['check_out'], (int) $new_id
                );
            } catch (\Throwable $e) {
                $blocks = ['error' => $e->getMessage()];
            }
        }

        // Optional payment capture: staff marked the booking paid / took a deposit
        // in the create form. Recorded natively through payments_logs.
        $payment_recorded = null;
        if (!empty($payload['payment']) && is_array($payload['payment'])) {
            $pay    = $payload['payment'];
            $amount = isset($pay['amount']) ? (float) $pay['amount'] : 0.0;
            if ($amount > 0 && method_exists($hb['db'], 'add_resa_payment')) {
                $new_payment = [
                    'amount'         => $amount,
                    'payment_method' => isset($pay['payment_method']) ? sanitize_text_field((string) $pay['payment_method']) : '',
                    'comment'        => isset($pay['comment']) ? sanitize_text_field((string) $pay['comment']) : '',
                ];
                if (!empty($pay['received_on'])) {
                    $new_payment['received_on'] = sanitize_text_field((string) $pay['received_on']);
                }
                try {
                    $pid = $hb['db']->add_resa_payment((int) $new_id, false, $new_payment);
                    $payment_recorded = ['payment_id' => $pid, 'amount' => $amount, 'method' => $new_payment['payment_method']];
                } catch (\Throwable $e) {
                    $payment_recorded = ['error' => $e->getMessage()];
                }
            }
        }

        return [
            'action'            => 'create_reservation',
            'id'                => (int) $new_id,
            'status'            => $resa_info['status'],
            'customer_id'       => isset($resa_info['customer_id']) ? (int) $resa_info['customer_id'] : 0,
            'automatic_blocks'  => $blocks,
            'payment'           => $payment_recorded,
            'mode'              => 'live',
        ];
    }
}
