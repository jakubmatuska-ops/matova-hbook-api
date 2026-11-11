<?php
/**
 * SQLite fixture seeder mirroring CONTRACTS §8 for ONE resort (prefix wp_).
 * All guest data is invented — no real PII.
 *
 * Expects global $wpdb (the SQLite shim) to be set by bootstrap.php.
 */

/** @var MHA_WPDB_Shim $wpdb */
global $wpdb;
$pdo = $wpdb->pdo;

/* ---------------------------------------------------------------- schema */

$pdo->exec("DROP TABLE IF EXISTS wp_hb_resa");
$pdo->exec("CREATE TABLE wp_hb_resa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_in TEXT, check_out TEXT,
    accom_id INTEGER, accom_num INTEGER,
    adults INTEGER, children INTEGER,
    accom_price REAL, discount TEXT, price REAL, previous_price REAL,
    deposit REAL, paid REAL, payment_gateway TEXT, currency TEXT,
    customer_id INTEGER, status TEXT, options TEXT, fees TEXT,
    additional_info TEXT, admin_comment TEXT, lang TEXT, coupon TEXT,
    coupon_value REAL, amount_to_pay REAL, payment_type TEXT, payment_info TEXT,
    payment_status TEXT, payments_logs TEXT, received_on TEXT, updated_on TEXT,
    uid TEXT, alphanum_id TEXT, invoice_counter INTEGER, nb_emails_sent INTEGER,
    origin TEXT, origin_url TEXT, synchro_id TEXT, booking_form_num INTEGER,
    parent_id INTEGER, custom_info TEXT
)");

$pdo->exec("DROP TABLE IF EXISTS wp_hb_parents_resa");
$pdo->exec("CREATE TABLE wp_hb_parents_resa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    price REAL, previous_price REAL, deposit REAL, paid REAL,
    currency TEXT, amount_to_pay REAL, payment_type TEXT, payment_status TEXT,
    payments_logs TEXT, received_on TEXT, updated_on TEXT, customer_id INTEGER
)");

$pdo->exec("DROP TABLE IF EXISTS wp_hb_customers");
$pdo->exec("CREATE TABLE wp_hb_customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT, info TEXT, payment_id TEXT, nb_resa INTEGER
)");

$pdo->exec("DROP TABLE IF EXISTS wp_hb_accom_blocked");
$pdo->exec("CREATE TABLE wp_hb_accom_blocked (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    accom_id INTEGER, accom_all_ids TEXT, accom_num INTEGER, accom_all_num TEXT,
    from_date TEXT, to_date TEXT, linked_resa_id INTEGER, is_prepa_time INTEGER,
    comment TEXT, uid TEXT
)");

$pdo->exec("DROP TABLE IF EXISTS wp_hb_accom_num_name");
$pdo->exec("CREATE TABLE wp_hb_accom_num_name (
    accom_id INTEGER, accom_num INTEGER, num_name TEXT,
    PRIMARY KEY (accom_id, accom_num)
)");

$pdo->exec("DROP TABLE IF EXISTS wp_hb_resa_logs");
$pdo->exec("CREATE TABLE wp_hb_resa_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    resa_id INTEGER, is_parent INTEGER, status TEXT, event TEXT,
    previous_status TEXT, msg TEXT, logged_on TEXT
)");

// Minimal rules/options/choices so /rules and /extras controllers run.
$pdo->exec("DROP TABLE IF EXISTS wp_hb_booking_rules");
$pdo->exec("CREATE TABLE wp_hb_booking_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT,
    check_in_days TEXT, check_out_days TEXT, minimum_stay INTEGER, maximum_stay INTEGER
)");
$pdo->exec("DROP TABLE IF EXISTS wp_hb_options");
$pdo->exec("CREATE TABLE wp_hb_options (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT)");
$pdo->exec("DROP TABLE IF EXISTS wp_hb_options_choices");
$pdo->exec("CREATE TABLE wp_hb_options_choices (id INTEGER PRIMARY KEY AUTOINCREMENT, option_id INTEGER, label TEXT, price REAL)");

/* ---------------------------------------------------------------- helpers */

$ins_resa = function (array $r) use ($pdo) {
    $cols = array_keys($r);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO wp_hb_resa (" . implode(',', $cols) . ") VALUES ($ph)");
    $stmt->execute(array_values($r));
};
$ins = function ($table, array $r) use ($pdo) {
    $cols = array_keys($r);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ($ph)");
    $stmt->execute(array_values($r));
};

/* --------------------------------------------------------------- customers */

$customers = [
    ['id' => 1, 'email' => 'anna.svoboda@example.test',  'first_name' => 'Anna',   'last_name' => 'Svobodova', 'phone' => '+420600000001'],
    ['id' => 2, 'email' => 'petr.novak@example.test',    'first_name' => 'Petr',   'last_name' => 'Novakova',  'phone' => '+420600000002'],
    ['id' => 3, 'email' => 'eva.dvorak@example.test',    'first_name' => 'Eva',    'last_name' => 'Dvorakova', 'phone' => '+420600000003'],
    ['id' => 4, 'email' => 'jan.cerny@example.test',     'first_name' => 'Jan',    'last_name' => 'Cerny',     'phone' => '+420600000004'],
    ['id' => 5, 'email' => 'lucie.marek@example.test',   'first_name' => 'Lucie',  'last_name' => 'Markova',   'phone' => '+420600000005'],
    ['id' => 6, 'email' => 'tomas.kral@example.test',    'first_name' => 'Tomas',  'last_name' => 'Kral',      'phone' => '+420600000006'],
    ['id' => 7, 'email' => 'group.leader@example.test',  'first_name' => 'Group',  'last_name' => 'Leader',    'phone' => '+420600000007'],
];
foreach ($customers as $c) {
    $info = [
        'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'phone' => $c['phone'],
        'street' => 'Test Street 1', 'city' => 'Testov', 'zip' => '11000', 'country' => 'CZ',
    ];
    $ins('wp_hb_customers', ['id' => $c['id'], 'email' => $c['email'], 'info' => json_encode($info), 'payment_id' => '', 'nb_resa' => 1]);
}

/* ------------------------------------------------------------ reservations */
// payments_logs is HBook's real paid model: list of {amount,date,...}.

$pl = function ($amount) { return $amount === null ? null : json_encode([['amount' => $amount, 'date' => '2026-06-01 10:00:00', 'method' => 'bank']]); };

$resas = [
    // id, ci, co, accom_id, accom_num, status, price, paid-amount, customer, parent_id
    [1, '2026-07-01', '2026-07-05', 101, 1, 'confirmed',        4000, 2000, 1, 0],
    [2, '2026-07-03', '2026-07-06', 101, 2, 'new',              3000, null, 2, 0],
    [3, '2026-07-02', '2026-07-04', 101, 1, 'cancelled',        3000, null, 3, 0], // frees unit
    [4, '2026-07-10', '2026-07-12', 102, 1, 'waiting_payment',  2000, 500,  4, 0],
    [5, '2026-07-04', '2026-07-07', 102, 2, 'confirmed',        6000, 6000, 5, 0], // fully paid
    [6, '2026-07-20', '2026-07-22', 101, 2, 'pending',          1500, null, 6, 0], // frees unit
    [7, '2026-08-01', '2026-08-03', 102, 1, 'confirmed',        2500, null, 7, 100], // group child
    [8, '2026-08-01', '2026-08-03', 101, 1, 'confirmed',        2500, null, 7, 100], // group child
];
foreach ($resas as $r) {
    list($id, $ci, $co, $aid, $anum, $status, $price, $paidAmt, $cust, $parent) = $r;
    $ins_resa([
        'id' => $id, 'check_in' => $ci, 'check_out' => $co,
        'accom_id' => $aid, 'accom_num' => $anum, 'adults' => 2, 'children' => 0,
        'accom_price' => $price, 'price' => $price, 'previous_price' => $price,
        'deposit' => 0, 'paid' => 0, 'currency' => 'CZK', 'customer_id' => $cust,
        'status' => $status, 'discount' => json_encode([]), 'options' => json_encode([]),
        'fees' => json_encode([]), 'amount_to_pay' => $price, 'payment_status' => 'na',
        'payments_logs' => $pl($paidAmt),
        'received_on' => '2026-06-01 09:00:00', 'updated_on' => '2026-06-15 09:00:00',
        'uid' => 'uid-' . $id, 'alphanum_id' => 'AB' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
        'origin' => 'web', 'parent_id' => $parent,
    ]);
}

// Parent (group) reservation.
$ins('wp_hb_parents_resa', [
    'id' => 100, 'price' => 5000, 'previous_price' => 5000, 'deposit' => 0, 'paid' => 0,
    'currency' => 'CZK', 'amount_to_pay' => 5000, 'payment_type' => 'bank', 'payment_status' => 'na',
    'payments_logs' => json_encode([['amount' => 1000, 'date' => '2026-06-20 10:00:00']]),
    'received_on' => '2026-06-20 09:00:00', 'updated_on' => '2026-06-20 09:00:00', 'customer_id' => 7,
]);

/* ------------------------------------------------------------- unit catalogue */

$ins('wp_hb_accom_num_name', ['accom_id' => 101, 'accom_num' => 1, 'num_name' => 'Chatka 101/1']);
$ins('wp_hb_accom_num_name', ['accom_id' => 101, 'accom_num' => 2, 'num_name' => 'Chatka 101/2']);
$ins('wp_hb_accom_num_name', ['accom_id' => 102, 'accom_num' => 1, 'num_name' => 'Chatka 102/1']);
$ins('wp_hb_accom_num_name', ['accom_id' => 102, 'accom_num' => 2, 'num_name' => 'Chatka 102/2']);

/* ------------------------------------------------------------- blocks */

$ins('wp_hb_accom_blocked', [
    'id' => 1, 'accom_id' => 101, 'accom_num' => 2, 'from_date' => '2026-07-15', 'to_date' => '2026-07-18',
    'linked_resa_id' => 0, 'is_prepa_time' => 0, 'comment' => 'maintenance', 'uid' => 'blk-1',
]);
$ins('wp_hb_accom_blocked', [
    'id' => 2, 'accom_id' => 102, 'accom_num' => 1, 'from_date' => '2026-07-25', 'to_date' => '2026-07-27',
    'linked_resa_id' => 5, 'is_prepa_time' => 0, 'comment' => 'linked to resa', 'uid' => 'blk-2',
]);

/* ------------------------------------------------------------- logs/rules/extras */

$ins('wp_hb_resa_logs', ['id' => 1, 'resa_id' => 1, 'is_parent' => 0, 'status' => 'confirmed', 'event' => 'created', 'previous_status' => '', 'msg' => 'seed', 'logged_on' => '2026-06-01 09:00:00']);
$ins('wp_hb_booking_rules', ['id' => 1, 'type' => 'global', 'name' => 'default', 'check_in_days' => '', 'check_out_days' => '', 'minimum_stay' => 2, 'maximum_stay' => 21]);
$ins('wp_hb_options', ['id' => 1, 'name' => 'Breakfast', 'type' => 'checkbox']);
$ins('wp_hb_options_choices', ['id' => 1, 'option_id' => 1, 'label' => 'Yes', 'price' => 150]);
