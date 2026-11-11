<?php
/**
 * Seed a realistic dataset using HBook's OWN schema DDL (HbDataBaseSchema),
 * translated to SQLite by dbDelta(). All guest data is invented (no real PII).
 *
 * @var MHA_WPDB_Shim $wpdb
 */
global $wpdb;
$pdo = $wpdb->pdo;

/* ---- wp_posts (accommodation types as real hb_accommodation posts) ---- */

$pdo->exec("CREATE TABLE IF NOT EXISTS wp_posts (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    post_type TEXT, post_status TEXT, post_title TEXT, post_date TEXT, menu_order INTEGER
)");

/* ---- HBook authentic schema via its own DDL ---- */

$hbdb_seed = new HbDataBaseActions();
$schema    = new HbDataBaseSchema($hbdb_seed);
dbDelta($schema->get_schema());

/* ---- accommodation posts + occupancy/quantity/prep meta ---- */

$accoms = [
    101 => ['title' => 'Chatka Standard', 'occ' => 4, 'max' => 6, 'qty' => 2, 'prep' => 0],
    102 => ['title' => 'Apartman Lux',    'occ' => 4, 'max' => 6, 'qty' => 2, 'prep' => 1],
];
$ins = function ($table, array $r) use ($pdo) {
    $cols = array_keys($r);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ($ph)");
    $stmt->execute(array_values($r));
};
foreach ($accoms as $id => $a) {
    $ins('wp_posts', ['ID' => $id, 'post_type' => 'hb_accommodation', 'post_status' => 'publish', 'post_title' => $a['title'], 'post_date' => '2026-01-01 00:00:00', 'menu_order' => 0]);
    update_post_meta($id, 'accom_occupancy', $a['occ']);
    update_post_meta($id, 'accom_max_occupancy', $a['max']);
    update_post_meta($id, 'accom_quantity', $a['qty']);
    update_post_meta($id, 'accom_preparation_time', $a['prep']);
    update_post_meta($id, 'accom_to_block', '');
}

/* ---- unit catalogue (hb_accom_num_name) ---- */

foreach ([[101, 1, 'Chatka 101/1'], [101, 2, 'Chatka 101/2'], [102, 1, 'Apartman 102/1'], [102, 2, 'Apartman 102/2']] as $u) {
    $ins('wp_hb_accom_num_name', ['accom_id' => $u[0], 'accom_num' => $u[1], 'num_name' => $u[2]]);
}

/* ---- seasons + dates (one all-year season, all weekdays) ---- */

$ins('wp_hb_seasons', ['id' => 1, 'name' => 'Main Season', 'priority' => '']);
$ins('wp_hb_seasons_dates', ['id' => 1, 'season_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'days' => '0123456']);

/* ---- rates (per-night) + accom/season junctions ---- */

$ins('wp_hb_rates', ['id' => 1, 'type' => 'accom', 'all_accom' => 0, 'all_seasons' => 0, 'amount' => 1000, 'nights' => 1]);
$ins('wp_hb_rates_accom', ['rate_id' => 1, 'accom_id' => 101]);
$ins('wp_hb_rates_seasons', ['rate_id' => 1, 'season_id' => 1]);
$ins('wp_hb_rates', ['id' => 2, 'type' => 'accom', 'all_accom' => 0, 'all_seasons' => 0, 'amount' => 1500, 'nights' => 1]);
$ins('wp_hb_rates_accom', ['rate_id' => 2, 'accom_id' => 102]);
$ins('wp_hb_rates_seasons', ['rate_id' => 2, 'season_id' => 1]);

/* ---- booking rule (global min/max stay) ---- */

$ins('wp_hb_booking_rules', ['id' => 1, 'type' => 'global', 'name' => 'default', 'check_in_days' => '0123456', 'check_out_days' => '0123456', 'minimum_stay' => 1, 'maximum_stay' => 28, 'all_accom' => 1, 'all_seasons' => 1, 'conditional_type' => 'comp']);

/* ---- strings (en_US) so HbUtils::get_strings() returns price_breakdown keys ---- */

$strings = [
    'price_breakdown_accom_price' => 'Accommodation',
    'price_breakdown_adult_one' => '%nb_adult adult', 'price_breakdown_adults_several' => '%nb_adults adults',
    'price_breakdown_child_one' => '%nb_child child', 'price_breakdown_children_several' => '%nb_children children',
    'price_breakdown_extra_adult_one' => '%nb_adult extra adult', 'price_breakdown_extra_adults_several' => '%nb_adults extra adults',
    'price_breakdown_extra_child_one' => '%nb_child extra child', 'price_breakdown_extra_children_several' => '%nb_children extra children',
    'price_breakdown_night_one' => 'night', 'price_breakdown_nights_several' => 'nights',
    'price_breakdown_dates' => '%from_date to %to_date:', 'price_breakdown_discount' => 'Discount:',
    'price_breakdown_fees' => 'Fees:', 'price_breakdown_multiple_nights' => 'per %nb_nights nights',
    'error_season_not_defined' => 'No season for %night', 'error_rate_not_defined' => 'No rate for %season_name / %accom_name',
    'fee_details_adult_one' => 'adult', 'fee_details_adults_several' => 'adults',
    'fee_details_child_one' => 'child', 'fee_details_children_several' => 'children', 'fee_details_persons' => 'persons',
];
foreach ($strings as $id => $val) { $ins('wp_hb_strings', ['id' => $id, 'locale' => 'en_US', 'value' => $val]); }

/* ---- customers (invented PII) ---- */

$customers = [
    1 => ['email' => 'anna.svoboda@example.test', 'first_name' => 'Anna', 'last_name' => 'Svobodova', 'phone' => '+420600000001'],
    2 => ['email' => 'petr.novak@example.test',   'first_name' => 'Petr', 'last_name' => 'Novak',     'phone' => '+420600000002'],
    3 => ['email' => 'eva.dvorak@example.test',   'first_name' => 'Eva',  'last_name' => 'Dvorakova', 'phone' => '+420600000003'],
    7 => ['email' => 'group.leader@example.test', 'first_name' => 'Group','last_name' => 'Leader',    'phone' => '+420600000007'],
];
foreach ($customers as $cid => $c) {
    $info = ['first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'phone' => $c['phone'], 'street' => 'Testova 1', 'city' => 'Testov', 'zip' => '11000', 'country_iso' => 'CZ'];
    $ins('wp_hb_customers', ['id' => $cid, 'email' => $c['email'], 'info' => json_encode($info), 'payment_id' => '', 'nb_resa' => 1]);
}

/* ---- reservations across statuses (+ a group parent/children) ---- */

$pl = function ($amount) { return $amount === null ? '' : json_encode([['id' => 1, 'amount' => $amount, 'received_on' => '2026-06-01', 'payment_method' => 'bank', 'comment' => '', 'refunded_amount' => 0]]); };

$resas = [
    // id, ci, co, accom, num, status, price, paidAmt, cust, parent
    [1, '2026-07-01', '2026-07-05', 101, 1, 'confirmed',       4000, 2000, 1, 0],
    [2, '2026-07-03', '2026-07-06', 101, 2, 'new',             3000, null, 2, 0],
    [3, '2026-07-02', '2026-07-04', 101, 1, 'cancelled',       3000, null, 3, 0],
    [4, '2026-07-10', '2026-07-12', 102, 1, 'waiting_payment', 2000, 500,  1, 0],
    [5, '2026-07-04', '2026-07-07', 102, 2, 'confirmed',       6000, 6000, 2, 0],
    [6, '2026-07-20', '2026-07-22', 101, 2, 'pending',         1500, null, 3, 0],
    [7, '2026-08-01', '2026-08-03', 102, 1, 'confirmed',       2500, null, 7, 100],
    [8, '2026-08-01', '2026-08-03', 101, 1, 'confirmed',       2500, null, 7, 100],
];
foreach ($resas as $r) {
    list($id, $ci, $co, $aid, $anum, $status, $price, $paidAmt, $cust, $parent) = $r;
    $ins('wp_hb_resa', [
        'id' => $id, 'check_in' => $ci, 'check_out' => $co, 'accom_id' => $aid, 'accom_num' => $anum,
        'adults' => 2, 'children' => 0, 'accom_price' => $price, 'price' => $price, 'previous_price' => $price,
        'deposit' => 0, 'paid' => 0, 'currency' => 'CZK', 'customer_id' => $cust, 'status' => $status,
        'discount' => '', 'options' => '', 'fees' => '', 'amount_to_pay' => $price, 'payment_status' => 'na',
        'payments_logs' => $pl($paidAmt), 'received_on' => '2026-06-01 09:00:00', 'updated_on' => '2026-06-15 09:00:00',
        'uid' => 'uid-' . $id, 'alphanum_id' => 'AB' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
        'origin' => 'web', 'parent_id' => $parent,
    ]);
}

/* ---- parent (group) reservation ---- */

$ins('wp_hb_parents_resa', [
    'id' => 100, 'price' => 5000, 'previous_price' => 5000, 'deposit' => 0, 'paid' => 0, 'currency' => 'CZK',
    'amount_to_pay' => 5000, 'payment_type' => 'bank', 'payment_status' => 'na',
    'payments_logs' => json_encode([['id' => 1, 'amount' => 1000, 'received_on' => '2026-06-20', 'refunded_amount' => 0]]),
    'received_on' => '2026-06-20 09:00:00', 'customer_id' => 7,
]);

/* ---- blocks (one free, one linked to a reservation) ---- */

$ins('wp_hb_accom_blocked', ['id' => 1, 'accom_id' => 101, 'accom_all_ids' => 0, 'accom_num' => 2, 'accom_all_num' => 0, 'from_date' => '2026-07-15', 'to_date' => '2026-07-18', 'linked_resa_id' => 0, 'is_prepa_time' => 0, 'comment' => 'maintenance', 'uid' => 'blk-1']);
$ins('wp_hb_accom_blocked', ['id' => 2, 'accom_id' => 102, 'accom_all_ids' => 0, 'accom_num' => 1, 'accom_all_num' => 0, 'from_date' => '2026-07-25', 'to_date' => '2026-07-27', 'linked_resa_id' => 5, 'is_prepa_time' => 0, 'comment' => 'linked', 'uid' => 'blk-2']);

/* ---- options (extras) ---- */

$ins('wp_hb_options', ['id' => 1, 'name' => 'Breakfast', 'amount' => 150, 'amount_children' => 100, 'choice_type' => 'checkbox', 'link' => '', 'apply_to_type' => 'per-person', 'quantity_max_option' => '', 'quantity_max' => 0, 'quantity_max_child' => 0, 'all_accom' => 1]);
$ins('wp_hb_options_choices', ['id' => 1, 'option_id' => 1, 'name' => 'Yes', 'amount' => 150, 'amount_children' => 100]);
