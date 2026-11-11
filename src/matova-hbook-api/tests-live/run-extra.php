<?php
/**
 * EXTRA live verification (invoked from run-live.php, after the 39 core live
 * tests). Runs against the SAME real-HBook-on-SQLite harness + fixture.
 *
 * Groups:
 *   1. CONCURRENCY / idempotency under races (own tables, interleaved)
 *   2. FAILURE INJECTION (error codes, audit written, outbox NOT emitted, DB unchanged)
 *   3. LEGACY-EQUIVALENCE (REAL resort-dashboard/v1 read handlers from
 *      srdicko/mu-plugins/hbook-api-srdicko.php executed in-harness, compared to matova/v2)
 *   4. COMPAT SHIM (includes/compat/class-mha-legacy-shim.php legacy-shaped rows)
 *   5. RESPONSE CONSISTENCY (envelope / meta / HTTP / no leaked PHP notice)
 *
 * Standalone: `php tests-live/run-extra.php` also works (it requires the bootstrap).
 */

if (!class_exists('MHA_WPDB_Shim')) { require __DIR__ . '/bootstrap-live.php'; }

global $wpdb;

/* --------------------------------------------------------------- mini harness */
$X = ['pass' => 0, 'fail' => 0, 'log' => [], 'area' => ''];
function xarea(&$X, $a) { $X['log'][] = "-- {$a}"; $X['area'] = $a; }
function xok(&$X, $name, $cond, $detail = '') {
    if ($cond) { $X['pass']++; $X['log'][] = "  PASS  $name"; }
    else       { $X['fail']++; $X['log'][] = "  FAIL  $name  -- $detail"; }
}
if (!function_exists('req')) { function req($m, $p, $h = [], $b = '') { return new WP_REST_Request($m, $p, $h, $b); } }
function x_count($sql) { global $wpdb; return (int) $wpdb->get_var($sql); }
function x_resa($id) { global $wpdb; return $wpdb->get_row("SELECT * FROM wp_hb_resa WHERE id = " . (int) $id, ARRAY_A); }
function x_env($resp) { return $resp->get_data(); }
/** sorted int list */
function x_ints($a) { $a = array_map('intval', $a); sort($a); return array_values($a); }

$GLOBALS['__extra_notices'] = [];

/* ======================================================================= */
/* GROUP 1 — CONCURRENCY / IDEMPOTENCY UNDER RACES                         */
/* ======================================================================= */
xarea($X, 'concurrency/idempotency');

// 1a. Unique-index collision: two INSERT IGNORE with the SAME key_hash leave
//     exactly one row (the DB-level dedupe MHA_Store::idempotency_begin relies on).
$idem_t = $wpdb->prefix . 'mha_idempotency';
$kh = hash('sha256', 'RACE-UNIQUE-1');
$shaR = hash('sha256', 'raceBody');
$ins_ignore = function () use ($wpdb, $idem_t, $kh, $shaR) {
    return $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$idem_t} (key_hash, route, resort_id, request_sha, response_json, http_status, created)
         VALUES (%s, %s, %s, %s, NULL, 0, %s)",
        $kh, 'POST /race', 'srdicko', $shaR, gmdate('Y-m-d H:i:s')
    ));
};
$n1 = $ins_ignore();
$n2 = $ins_ignore(); // collides on UNIQUE key_hash -> ignored
$rows_race = x_count("SELECT COUNT(*) FROM {$idem_t} WHERE key_hash = '" . $kh . "'");
xok($X, 'race: two INSERT-IGNORE on same key leave exactly one row', $rows_race === 1, "rows=$rows_race n1=$n1 n2=$n2");

// 1b. Interleaved begins BEFORE finish: reserve, then a concurrent duplicate sees
//     the in-flight (reserved-but-unfinished) record and is told to back off
//     ('in_progress') so the native mutation is NEVER executed twice.
$key = 'RACE-INFLIGHT-1';
$sha = hash('sha256', 'bodyX');
$begin_a = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', $sha); // reserves
$begin_b = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', $sha); // BEFORE finish
xok($X, 'race: first begin proceeds (null, reserves key)', $begin_a === null, json_encode($begin_a));
xok($X, 'race: concurrent duplicate before finish is told in_progress (must NOT proceed)',
    $begin_b === 'in_progress', json_encode($begin_b));
$rows_inflight = x_count("SELECT COUNT(*) FROM {$idem_t} WHERE key_hash = '" . hash('sha256', $key) . "'");
xok($X, 'race: still exactly one row for the in-flight key', $rows_inflight === 1, "rows=$rows_inflight");
MHA_Store::idempotency_finish($key, json_encode(['ok' => true, 'data' => ['v' => 42]]), 200);
$begin_c = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', $sha); // after finish
xok($X, 'race: after finish, replay returns stored response verbatim',
    is_array($begin_c) && !empty($begin_c['replay']) && (int) $begin_c['http_status'] === 200
    && json_decode($begin_c['response_json'], true)['data']['v'] === 42, json_encode($begin_c));

// 1c. Same key + different body -> conflict (store level).
$conf = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', hash('sha256', 'DIFFERENT'));
xok($X, 'race: same key + different body -> conflict', $conf === 'conflict', json_encode($conf));

// 1c-stale. An abandoned in-flight reservation (crashed mid-write) must not lock
//     the key forever: a reservation older than the TTL is reclaimed so a retry proceeds.
$stale_key = 'RACE-STALE-1';
$stale_kh = hash('sha256', $stale_key);
$stale_sha = hash('sha256', 'staleBody');
$old = gmdate('Y-m-d H:i:s', time() - (MHA_Store::IDEM_INFLIGHT_TTL + 30));
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$idem_t} (key_hash, route, resort_id, request_sha, response_json, http_status, created)
     VALUES (%s,%s,%s,%s,NULL,0,%s)", $stale_kh, 'POST /x', 'srdicko', $stale_sha, $old));
$stale_begin = MHA_Store::idempotency_begin($stale_key, 'POST /x', 'srdicko', $stale_sha);
xok($X, 'race: abandoned in-flight reservation past TTL is reclaimed (proceed)', $stale_begin === null, json_encode($stale_begin));
// a fresh (non-stale) in-flight reservation is NOT reclaimed
$fresh_key = 'RACE-FRESH-1'; $fresh_kh = hash('sha256', $fresh_key); $fresh_sha = hash('sha256', 'freshBody');
MHA_Store::idempotency_begin($fresh_key, 'POST /x', 'srdicko', $fresh_sha); // reserves now
$fresh_begin = MHA_Store::idempotency_begin($fresh_key, 'POST /x', 'srdicko', $fresh_sha);
xok($X, 'race: fresh in-flight reservation is NOT reclaimed (in_progress)', $fresh_begin === 'in_progress', json_encode($fresh_begin));

// 1d. Two IDENTICAL mutations with the same Idempotency-Key through the controller
//     -> exactly one DB effect; the second is a verbatim replay with the header.
update_option('mha_write_mode', ['default' => 'live']);
// fresh reservation to mutate (so we don't disturb seed rows)
$mk = MHA_HBook::create_reservation([
    'check_in' => '2026-09-20', 'check_out' => '2026-09-22', 'accom_id' => 101, 'accom_num' => 1,
    'adults' => 2, 'children' => 0, 'price' => 2000, 'accom_price' => 2000, 'status' => 'new',
    'customer_id' => 1, 'currency' => 'CZK',
]);
$rid_same = is_array($mk) ? (int) ($mk['id'] ?? 0) : 0;
$rc = new MHA_Reservations_Controller();
$hdr = ['Idempotency-Key' => 'RACE-CTRL-SAME'];
$params = ['id' => $rid_same, 'status' => 'cancelled'];
$logs_1 = x_count("SELECT COUNT(*) FROM wp_hb_resa_logs WHERE resa_id = {$rid_same}");
$r1 = $rc->set_status(req('POST', $params, $hdr, json_encode($params)));
$logs_after1 = x_count("SELECT COUNT(*) FROM wp_hb_resa_logs WHERE resa_id = {$rid_same}");
$status_after1 = x_resa($rid_same)['status'];
$r2 = $rc->set_status(req('POST', $params, $hdr, json_encode($params)));
$logs_after2 = x_count("SELECT COUNT(*) FROM wp_hb_resa_logs WHERE resa_id = {$rid_same}");
xok($X, 'race: same-key duplicate mutation applied the effect exactly once (status cancelled)',
    $status_after1 === 'cancelled' && $logs_after1 > $logs_1, "status=$status_after1 logs {$logs_1}->{$logs_after1}");
xok($X, 'race: same-key duplicate produced NO second DB effect (log count stable)',
    $logs_after2 === $logs_after1, "after1=$logs_after1 after2=$logs_after2");
xok($X, 'race: second call carries Idempotent-Replay: true', ($r2->get_headers()['Idempotent-Replay'] ?? null) === 'true', json_encode($r2->get_headers()));
xok($X, 'race: replay body identical to first', json_encode($r1->get_data()) === json_encode($r2->get_data()), 'bodies differ');
$idem_rows_same = x_count("SELECT COUNT(*) FROM {$idem_t} WHERE key_hash = '" . hash('sha256', 'RACE-CTRL-SAME') . "'");
xok($X, 'race: exactly one idempotency row for the reused key', $idem_rows_same === 1, "rows=$idem_rows_same");

// 1e. Two DIFFERENT keys on the SAME reservation both apply, in order.
$mk2 = MHA_HBook::create_reservation([
    'check_in' => '2026-09-24', 'check_out' => '2026-09-26', 'accom_id' => 101, 'accom_num' => 1,
    'adults' => 2, 'children' => 0, 'price' => 2000, 'accom_price' => 2000, 'status' => 'new',
    'customer_id' => 1, 'currency' => 'CZK',
]);
$rid_ord = is_array($mk2) ? (int) ($mk2['id'] ?? 0) : 0;
$rA = $rc->set_status(req('POST', ['id' => $rid_ord, 'status' => 'confirmed'], ['Idempotency-Key' => 'ORD-A'], json_encode(['id' => $rid_ord, 'status' => 'confirmed'])));
$st_mid = x_resa($rid_ord)['status'];
$rB = $rc->set_status(req('POST', ['id' => $rid_ord, 'status' => 'cancelled'], ['Idempotency-Key' => 'ORD-B'], json_encode(['id' => $rid_ord, 'status' => 'cancelled'])));
$st_end = x_resa($rid_ord)['status'];
xok($X, 'race: two different keys applied in order (new->confirmed->cancelled)', $st_mid === 'confirmed' && $st_end === 'cancelled', "mid=$st_mid end=$st_end");
$ord_keys = x_count("SELECT COUNT(*) FROM {$idem_t} WHERE key_hash IN ('" . hash('sha256', 'ORD-A') . "','" . hash('sha256', 'ORD-B') . "')");
xok($X, 'race: two distinct idempotency rows for the two different keys', $ord_keys === 2, "rows=$ord_keys");

// 1f. Same key, DIFFERENT body, through the controller -> 409 conflict.
$rCfl = $rc->set_status(req('POST', ['id' => $rid_ord, 'status' => 'confirmed'], ['Idempotency-Key' => 'ORD-B'], json_encode(['id' => $rid_ord, 'status' => 'confirmed'])));
xok($X, 'race: controller same-key different-body -> HTTP 409 conflict',
    $rCfl->get_status() === 409 && (x_env($rCfl)['error']['code'] ?? '') === 'conflict', 'http=' . $rCfl->get_status());

// 1g. Reservation modify — price via native update_resa_price (gated 'modify').
update_option('mha_write_mode', ['default' => 'live']);
$mkp = MHA_HBook::create_reservation([
    'check_in' => '2026-09-28', 'check_out' => '2026-09-30', 'accom_id' => 101, 'accom_num' => 1,
    'adults' => 2, 'children' => 0, 'price' => 2000, 'accom_price' => 2000, 'status' => 'new',
    'customer_id' => 1, 'currency' => 'CZK',
]);
$rid_mod = is_array($mkp) ? (int) ($mkp['id'] ?? 0) : 0;
$price_before = (float) x_resa($rid_mod)['price'];
$rmod = $rc->modify(req('PATCH', ['id' => $rid_mod], ['Idempotency-Key' => 'MOD-PRICE-1'], json_encode(['price' => 3500])));
$price_after = (float) x_resa($rid_mod)['price'];
xok($X, 'modify: price updated via native update_resa_price (2000->3500)', $price_after === 3500.0, "before=$price_before after=$price_after");
xok($X, 'modify: response reports changed=[price] + new price', (x_env($rmod)['data']['price'] ?? null) === 3500.0 && in_array('price', x_env($rmod)['data']['changed'] ?? []), json_encode(x_env($rmod)['data'] ?? null));
// date/accom change is refused (unsupported), no state change
$dres = x_resa($rid_mod)['check_in'];
$rbad = $rc->modify(req('PATCH', ['id' => $rid_mod], ['Idempotency-Key' => 'MOD-DATE-1'], json_encode(['check_in' => '2027-01-01'])));
xok($X, 'modify: date change refused as unsupported (503) + no state change',
    $rbad->get_status() === 503 && (x_env($rbad)['error']['code'] ?? '') === 'unsupported' && x_resa($rid_mod)['check_in'] === $dres, 'http=' . $rbad->get_status());

update_option('mha_write_mode', ['default' => 'off']);

/* ======================================================================= */
/* GROUP 2 — FAILURE INJECTION                                             */
/* ======================================================================= */
xarea($X, 'failure-injection');

// 2a. status write on an invalid reservation id -> not_found; audit row written;
//     outbox NOT emitted; DB unchanged.
update_option('mha_write_mode', ['default' => 'live']);
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$ev_b  = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $rc->set_status(req('POST', ['id' => 999999, 'status' => 'confirmed'], ['Idempotency-Key' => 'FAIL-NF-1'], json_encode(['id' => 999999, 'status' => 'confirmed'])));
xok($X, 'fail(not_found): invalid resa id -> not_found 404', $resp->get_status() === 404 && (x_env($resp)['error']['code'] ?? '') === 'not_found', 'http=' . $resp->get_status());
xok($X, 'fail(not_found): audit row written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');
xok($X, 'fail(not_found): NO outbox event emitted', x_count("SELECT COUNT(*) FROM wp_mha_events") === $ev_b, 'events changed');

// 2b. status not in the writable allow-list -> bad_request; audit still written; DB unchanged.
$st_before = x_resa(1)['status'];
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$ev_b  = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $rc->set_status(req('POST', ['id' => 1, 'status' => 'pending'], ['Idempotency-Key' => 'FAIL-ENUM-1'], json_encode(['id' => 1, 'status' => 'pending'])));
xok($X, 'fail(bad_request): status not in {confirmed,cancelled} -> bad_request', (x_env($resp)['error']['code'] ?? '') === 'bad_request', json_encode(x_env($resp)['error'] ?? null));
xok($X, 'fail(bad_request): audit row written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');
xok($X, 'fail(bad_request): outbox NOT emitted', x_count("SELECT COUNT(*) FROM wp_mha_events") === $ev_b, 'events changed');
xok($X, 'fail(bad_request): reservation#1 status unchanged', x_resa(1)['status'] === $st_before, x_resa(1)['status']);

// 2c. block delete on a reservation-linked row (seed block id=2, linked_resa_id=5) -> conflict.
$av = new MHA_Availability_Controller();
$blk_before = x_count("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE id = 2");
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$ev_b  = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $av->delete_block(req('DELETE', ['id' => 2], ['Idempotency-Key' => 'FAIL-LINK-1']));
xok($X, 'fail(conflict): delete linked block -> 409 conflict', $resp->get_status() === 409 && (x_env($resp)['error']['code'] ?? '') === 'conflict', 'http=' . $resp->get_status());
xok($X, 'fail(conflict): linked block row untouched', x_count("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE id = 2") === $blk_before, 'row removed');
xok($X, 'fail(conflict): audit row written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');
xok($X, 'fail(conflict): outbox NOT emitted', x_count("SELECT COUNT(*) FROM wp_mha_events") === $ev_b, 'events changed');

// 2d. quote for an unknown accom -> upstream_error (real HbPriceCalc failure), no leak.
$pc = new MHA_Pricing_Controller();
$resp = $pc->quote(req('GET', ['accom_id' => 999, 'check_in' => '2026-07-01', 'check_out' => '2026-07-03', 'adults' => 2]));
$env = x_env($resp);
xok($X, 'fail(upstream_error): quote unknown accom -> upstream_error 502', $resp->get_status() === 502 && ($env['error']['code'] ?? '') === 'upstream_error', 'http=' . $resp->get_status());
xok($X, 'fail(upstream_error): error envelope, no stack leak in message', ($env['ok'] ?? null) === false && is_string($env['error']['message'] ?? null) && stripos($env['error']['message'], 'Stack trace') === false, json_encode($env['error'] ?? null));

// 2e. payment with a malformed amount -> bad_request; audit written; payments_logs unchanged.
$pay_c = new MHA_Payments_Controller();
$pl_before = x_resa(1)['payments_logs'];
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$resp = $pay_c->add_payment(req('POST', ['id' => 1, 'amount' => 'not-a-number'], ['Idempotency-Key' => 'FAIL-PAY-1'], json_encode(['id' => 1, 'amount' => 'not-a-number'])));
xok($X, 'fail(bad_request): malformed payment amount -> bad_request', (x_env($resp)['error']['code'] ?? '') === 'bad_request', json_encode(x_env($resp)['error'] ?? null));
xok($X, 'fail(bad_request): payments_logs unchanged', x_resa(1)['payments_logs'] === $pl_before, 'logs changed');
xok($X, 'fail(bad_request): audit row written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');

// 2f. WRITE-MODE GATE matrix on the SAME operation: off -> write_disabled,
//     dry_run -> would_* (no native call), live -> actually mutates.
$mk3 = MHA_HBook::create_reservation([
    'check_in' => '2026-09-28', 'check_out' => '2026-09-30', 'accom_id' => 101, 'accom_num' => 1,
    'adults' => 2, 'children' => 0, 'price' => 2000, 'accom_price' => 2000, 'status' => 'new',
    'customer_id' => 1, 'currency' => 'CZK',
]);
$rid_gate = is_array($mk3) ? (int) ($mk3['id'] ?? 0) : 0;

// off
update_option('mha_write_mode', ['status' => 'off']);
$st0 = x_resa($rid_gate)['status'];
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$ev_b  = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $rc->set_status(req('POST', ['id' => $rid_gate, 'status' => 'cancelled'], ['Idempotency-Key' => 'GATE-OFF-1'], json_encode(['id' => $rid_gate, 'status' => 'cancelled'])));
xok($X, 'gate(off): -> write_disabled 503', $resp->get_status() === 503 && (x_env($resp)['error']['code'] ?? '') === 'write_disabled', 'http=' . $resp->get_status());
xok($X, 'gate(off): DB unchanged', x_resa($rid_gate)['status'] === $st0, x_resa($rid_gate)['status']);
xok($X, 'gate(off): audit written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');
xok($X, 'gate(off): outbox NOT emitted', x_count("SELECT COUNT(*) FROM wp_mha_events") === $ev_b, 'events changed');

// dry_run
update_option('mha_write_mode', ['status' => 'dry_run']);
$st0 = x_resa($rid_gate)['status'];
$aud_b = x_count("SELECT COUNT(*) FROM wp_mha_audit");
$ev_b  = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $rc->set_status(req('POST', ['id' => $rid_gate, 'status' => 'cancelled'], ['Idempotency-Key' => 'GATE-DRY-1'], json_encode(['id' => $rid_gate, 'status' => 'cancelled'])));
$env = x_env($resp);
xok($X, 'gate(dry_run): HTTP 200 + would_* effect, mode=dry_run', $resp->get_status() === 200 && ($env['data']['mode'] ?? '') === 'dry_run' && ($env['data']['would']['status'] ?? '') === 'cancelled', json_encode($env['data'] ?? null));
xok($X, 'gate(dry_run): DB unchanged (no native call)', x_resa($rid_gate)['status'] === $st0, x_resa($rid_gate)['status']);
xok($X, 'gate(dry_run): audit written', x_count("SELECT COUNT(*) FROM wp_mha_audit") === $aud_b + 1, 'audit delta');
xok($X, 'gate(dry_run): outbox NOT emitted (native never called)', x_count("SELECT COUNT(*) FROM wp_mha_events") === $ev_b, 'events changed');

// live
update_option('mha_write_mode', ['status' => 'live']);
$ev_b = x_count("SELECT COUNT(*) FROM wp_mha_events");
$resp = $rc->set_status(req('POST', ['id' => $rid_gate, 'status' => 'cancelled'], ['Idempotency-Key' => 'GATE-LIVE-1'], json_encode(['id' => $rid_gate, 'status' => 'cancelled'])));
xok($X, 'gate(live): HTTP 200 and reservation actually mutated to cancelled', $resp->get_status() === 200 && x_resa($rid_gate)['status'] === 'cancelled', 'status=' . x_resa($rid_gate)['status']);
xok($X, 'gate(live): outbox event(s) emitted by native hooks', x_count("SELECT COUNT(*) FROM wp_mha_events") > $ev_b, 'no new events');

update_option('mha_write_mode', ['default' => 'off']);

/* ======================================================================= */
/* GROUP 3 — LEGACY-EQUIVALENCE (real resort-dashboard/v1 handlers)         */
/* ======================================================================= */
xarea($X, 'legacy-equivalence');

// Decorator adds MySQL-only SHOW TABLES / DESCRIBE / SHOW TABLES LIKE support so
// the real legacy handlers (which introspect the schema that way) run unchanged.
if (!class_exists('MHA_Legacy_WPDB')) {
    class MHA_Legacy_WPDB extends MHA_WPDB_Shim {
        public $posts = 'wp_posts';
        public function get_results($sql, $output = 'ARRAY_A') {
            if (preg_match('/^\s*SHOW\s+TABLES\s*$/i', $sql)) {
                $names = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                return array_map(function ($n) { return [0 => $n]; }, $names);
            }
            if (preg_match('/^\s*DESCRIBE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                $rows = $this->pdo->query("PRAGMA table_info(" . $m[1] . ")")->fetchAll(PDO::FETCH_ASSOC);
                return array_map(function ($c) { return ['Field' => $c['name']]; }, $rows);
            }
            return parent::get_results($sql, $output);
        }
        public function get_var($sql) {
            if (preg_match('/^\s*SHOW\s+TABLES\s+LIKE\s+(.+)$/i', $sql, $m)) {
                $like = trim($m[1], " '\"");
                $n = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $like . "'")->fetchColumn();
                return $n === false ? null : $n;
            }
            return parent::get_var($sql);
        }
    }
}
$legacy_wpdb = new MHA_Legacy_WPDB($wpdb->pdo, 'wp_');

// Load the REAL legacy read handlers (guarded by its wp-config boot check).
if (!defined('HBAPI_SECRET'))   { define('HBAPI_SECRET', str_repeat('x', 32)); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', sys_get_temp_dir()); }
$legacy_loaded = function_exists('hbapi_get_reservations');
if (!$legacy_loaded) {
    $legacy_src = MHA_PLUGIN_DIR . '/../../srdicko/mu-plugins/hbook-api-srdicko.php';
    if (is_file($legacy_src)) { require $legacy_src; $legacy_loaded = function_exists('hbapi_get_reservations'); }
}
xok($X, 'legacy: real srdicko read handlers loaded into the harness', $legacy_loaded, 'handlers missing');

/** Run a legacy handler with the decorated $wpdb, restoring the real shim after. */
function legacy_call(callable $fn, $req) {
    global $wpdb, $legacy_wpdb;
    $orig = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = $legacy_wpdb;
    try { $out = $fn($req); } finally { $GLOBALS['wpdb'] = $orig; $wpdb = $orig; }
    return $out;
}

if ($legacy_loaded) {
    $win = ['from' => '2026-07-01', 'to' => '2026-07-31', 'check_in' => '2026-07-01', 'check_out' => '2026-07-05', 'accom_num' => 101, 'adults' => 2, 'children' => 0];

    /* -- /reservations -> GET /reservations ------------------------------- */
    $leg = legacy_call('hbapi_get_reservations', req('GET', $win))->get_data();
    $v2  = x_env($rc->index(req('GET', $win)))['data'];
    $leg_ids = x_ints(array_map(function ($r) { return (int) $r['reservation_id']; }, $leg['reservations']));
    $v2_ids  = x_ints(array_map(function ($r) { return (int) $r['id']; }, $v2['reservations']));
    xok($X, 'eq[reservations]: same reservation-id set', $leg_ids === $v2_ids, 'leg=' . json_encode($leg_ids) . ' v2=' . json_encode($v2_ids));
    xok($X, 'eq[reservations]: same count', (int) $leg['count'] === (int) $v2['count'], "leg={$leg['count']} v2={$v2['count']}");
    // per-id compare check_in/check_out/status/price
    $v2_by = []; foreach ($v2['reservations'] as $r) { $v2_by[(int) $r['id']] = $r; }
    $field_match = true; $field_detail = '';
    foreach ($leg['reservations'] as $lr) {
        $id = (int) $lr['reservation_id']; $vr = $v2_by[$id] ?? null;
        if (!$vr) { $field_match = false; $field_detail = "missing v2 #$id"; break; }
        if ((string) $lr['check_in'] !== (string) $vr['check_in'] || (string) $lr['check_out'] !== (string) $vr['check_out']
            || (string) $lr['status'] !== (string) $vr['status'] || (float) $lr['price'] !== (float) $vr['price']) {
            $field_match = false; $field_detail = "#$id " . json_encode([$lr['check_in'], $lr['status'], $lr['price']]) . ' vs ' . json_encode([$vr['check_in'], $vr['status'], $vr['price']]); break;
        }
    }
    xok($X, 'eq[reservations]: per-row check_in/out/status/price match', $field_match, $field_detail);
    // Document field delta. v2 now ALWAYS carries non-PII enrichment
    // (accommodation_type, nights), so those are no longer legacy-only. The
    // default v2 /reservations (no include=guest) still omits PII + the legacy
    // customer_name/num conveniences — those remain legacy-only.
    $leg_only = array_diff(array_keys($leg['reservations'][0] ?? []), array_keys($v2['reservations'][0] ?? []));
    xok($X, 'eq[reservations]: enrichment now in v2 (accommodation_type/nights NOT legacy-only); PII+customer_name/num remain legacy-only by default',
        !in_array('accommodation_type', $leg_only, true) && !in_array('nights', $leg_only, true)
        && in_array('customer_name', $leg_only, true) && in_array('num', $leg_only, true)
        && in_array('first_name', $leg_only, true) && in_array('email', $leg_only, true),
        'leg_only=' . json_encode(array_values($leg_only)));

    /* -- /blocked -> GET /blocked (+DELETE) ------------------------------- */
    $legB = legacy_call('hbapi_get_blocked', req('GET', $win))->get_data();
    $v2B  = x_env($av->blocked(req('GET', $win)))['data'];
    $legB_ids = x_ints(array_map(function ($b) { return (int) $b['id']; }, $legB['blocked']));
    $v2B_ids  = x_ints(array_map(function ($b) { return (int) $b['id']; }, $v2B['blocked']));
    xok($X, 'eq[blocked]: same block-id set over wide July window', $legB_ids === $v2B_ids, 'leg=' . json_encode($legB_ids) . ' v2=' . json_encode($v2B_ids));
    // dates + linked_resa_id equality for shared ids
    $v2B_by = []; foreach ($v2B['blocked'] as $b) { $v2B_by[(int) $b['id']] = $b; }
    $bmatch = true; foreach ($legB['blocked'] as $lb) { $vb = $v2B_by[(int) $lb['id']] ?? null; if (!$vb || (string) $lb['from_date'] !== (string) $vb['from_date'] || (string) $lb['to_date'] !== (string) $vb['to_date'] || (int) $lb['linked_resa_id'] !== (int) $vb['linked_resa_id']) { $bmatch = false; break; } }
    xok($X, 'eq[blocked]: from_date/to_date/linked_resa_id match', $bmatch, 'mismatch');

    /* -- /reservations-occupancy -> occupied set (availability) ----------- */
    // Legacy BLOCK = {confirmed,new}; v2 ACTIVE adds waiting_payment/multi -> compare
    // the confirmed/new subset so the shapes line up, and separately assert the
    // documented semantic difference on waiting_payment.
    $occWin = ['from' => '2026-07-01', 'to' => '2026-07-08'];
    $legO = legacy_call('hbapi_get_reservations_occupancy', req('GET', $occWin))->get_data();
    $legO_nums = x_ints(array_map(function ($r) { return (int) $r['accommodation_number']; }, $legO['reservations']));
    $v2av = MHA_HBook::availability($occWin['from'], $occWin['to'], null);
    $v2_cn_nums = [];
    foreach ($v2av['occupied'] as $o) {
        if ($o['source'] !== 'reservation') { continue; }
        $st = x_resa($o['ref_id'])['status'] ?? '';
        if (in_array($st, ['confirmed', 'new'], true)) { $v2_cn_nums[] = (int) $o['accom_num']; }
    }
    $v2_cn_nums = x_ints($v2_cn_nums);
    xok($X, 'eq[occupancy]: legacy blocking (confirmed/new) accom_num set == v2 occupied confirmed/new subset', $legO_nums === $v2_cn_nums, 'leg=' . json_encode($legO_nums) . ' v2=' . json_encode($v2_cn_nums));
    // Documented divergence: waiting_payment (r4) over a window that includes it.
    $wpWin = ['from' => '2026-07-10', 'to' => '2026-07-13'];
    $legWp = legacy_call('hbapi_get_reservations_occupancy', req('GET', $wpWin))->get_data();
    $legWp_has = count($legWp['reservations']) > 0;
    $v2Wp = MHA_HBook::availability($wpWin['from'], $wpWin['to'], null);
    $v2Wp_has = false; foreach ($v2Wp['occupied'] as $o) { if ($o['source'] === 'reservation') { $v2Wp_has = true; } }
    xok($X, 'eq[occupancy]: DOCUMENTED divergence — v2 counts waiting_payment(r4) as occupied, legacy does not',
        $legWp_has === false && $v2Wp_has === true, 'leg_has=' . var_export($legWp_has, true) . ' v2_has=' . var_export($v2Wp_has, true));

    /* -- /accom-units -> GET /units --------------------------------------- */
    $legU = legacy_call('hbapi_get_accom_units', req('GET', []))->get_data();
    $v2U  = x_env($av->units(req('GET', [])))['data'];
    $leg_pairs = [];
    foreach ($legU['accommodations'] as $acc) { foreach ($acc['units'] as $u) { $leg_pairs[] = (int) $acc['accom_id'] . '/' . $u['accom_num'] . '/' . $u['num_name']; } }
    sort($leg_pairs);
    $v2_pairs = [];
    foreach ($v2U['units'] as $u) { $v2_pairs[] = (int) $u['accom_id'] . '/' . $u['accom_num'] . '/' . $u['num_name']; }
    sort($v2_pairs);
    xok($X, 'eq[accom-units]: same (accom_id/accom_num/num_name) unit set', $leg_pairs === $v2_pairs, 'leg=' . json_encode($leg_pairs) . ' v2=' . json_encode($v2_pairs));

    /* -- /accom-list -> GET /units.accommodations ------------------------- */
    $legL = legacy_call('hbapi_get_accom_list', req('GET', []))->get_data();
    $leg_posts = [];
    foreach ($legL['accommodations'] as $p) { $leg_posts[(int) $p['ID']] = (string) $p['post_title']; }
    $v2_accoms = [];
    foreach ($v2U['accommodations'] as $a) { $v2_accoms[(int) $a['accom_id']] = (string) $a['name']; }
    ksort($leg_posts); ksort($v2_accoms);
    xok($X, 'eq[accom-list]: same post ID->title set as v2 accommodations id->name', $leg_posts === $v2_accoms, 'leg=' . json_encode($leg_posts) . ' v2=' . json_encode($v2_accoms));

    /* -- /customers -> GET /guests/{id} ----------------------------------- */
    $legC = legacy_call('hbapi_get_customers', req('GET', ['limit' => 5000]))->get_data();
    $legC_by = []; foreach ($legC['customers'] as $c) { $legC_by[(int) $c['customer_id']] = $c; }
    $gc = new MHA_Guests_Controller();
    $cmatch = true; $cdetail = '';
    foreach ([1, 2, 3, 7] as $cid) {
        $lc = $legC_by[$cid] ?? null;
        $vc = x_env($gc->show(req('GET', ['id' => $cid])))['data'] ?? null;
        if (!$lc || !$vc) { $cmatch = false; $cdetail = "missing #$cid"; break; }
        if ((string) $lc['first_name'] !== (string) $vc['first_name'] || (string) $lc['last_name'] !== (string) $vc['last_name']
            || strtolower((string) $lc['email']) !== strtolower((string) $vc['email']) || (string) $lc['phone'] !== (string) $vc['phone']) {
            $cmatch = false; $cdetail = "#$cid " . json_encode([$lc['first_name'], $lc['email']]) . ' vs ' . json_encode([$vc['first_name'], $vc['email']]); break;
        }
    }
    xok($X, 'eq[customers]: first_name/last_name/email/phone match v2 /guests/{id}', $cmatch, $cdetail);

    /* -- /pricing (stay calc) -> GET /pricing/quote ----------------------- */
    foreach ([[101, '2026-07-01', '2026-07-05', 4000.0], [102, '2026-07-01', '2026-07-04', 4500.0]] as $pq) {
        list($aid, $ci, $co, $expect) = $pq;
        $legP = legacy_call('hbapi_calculate_stay_price', req('GET', ['accom_num' => $aid, 'check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'children' => 0]))->get_data();
        $v2P  = x_env($pc->quote(req('GET', ['accom_id' => $aid, 'check_in' => $ci, 'check_out' => $co, 'adults' => 2])))['data'];
        $leg_total = isset($legP['total_price']) ? (float) $legP['total_price'] : null;
        $v2_total  = isset($v2P['price']) ? (float) $v2P['price'] : null;
        xok($X, "eq[pricing]: accom $aid total legacy==v2==expected ($expect)", $leg_total === $expect && $v2_total === $expect, "legacy=$leg_total v2=$v2_total");
    }

    /* -- /pricing (data dump) rate amounts -------------------------------- */
    $legPD = legacy_call('hbapi_get_pricing', req('GET', []))->get_data();
    $rate_amounts = x_ints(array_map(function ($r) { return (int) $r['amount']; }, $legPD['rates']));
    xok($X, 'eq[pricing-dump]: legacy rate amounts match seed (1000,1500)', $rate_amounts === [1000, 1500], json_encode($rate_amounts));

    /* -- /booking-rules -> GET /rules ------------------------------------- */
    $legR = legacy_call('hbapi_get_booking_rules', req('GET', ['check_in' => '2026-07-01', 'check_out' => '2026-07-05']))->get_data();
    $v2R  = x_env($av->rules(req('GET', [])))['data'];
    $v2_rule = $v2R['rules'][0] ?? null;
    // v2 exposes the raw rule row with min/max; legacy reshapes into season-resolved
    // aggregate and (for a type='global' rule) classifies it as neither a
    // validation nor a rate rule, so its constraints are empty. Documented MISMATCH.
    $legR_season_ok = isset($legR['seasons_covered']) && count($legR['seasons_covered']) === 1;
    $v2_min_max = $v2_rule && (int) $v2_rule['minimum_stay'] === 1 && (int) $v2_rule['maximum_stay'] === 28;
    $legR_drops = isset($legR['accom'][0]['constraints_aggregate']) && $legR['accom'][0]['constraints_aggregate']['min_stay_required'] === null;
    xok($X, 'eq[booking-rules]: v2 /rules exposes raw min/max(1/28); legacy season-resolved shape present', $v2_min_max && $legR_season_ok, 'v2minmax=' . var_export($v2_min_max, true) . ' legseason=' . var_export($legR_season_ok, true));
    xok($X, 'eq[booking-rules]: DOCUMENTED — legacy classifier drops min/max for a type=global rule (constraints empty)', $legR_drops, json_encode($legR['accom'][0]['constraints_aggregate'] ?? null));

    /* -- /extras-config -> GET /extras ------------------------------------ */
    $legE = legacy_call('hbapi_get_extras_config', req('GET', []))->get_data();
    $v2E  = x_env($av->extras(req('GET', [])))['data'];
    $leg_extra_ids = x_ints(array_map(function ($e) { return (int) $e['extra_id']; }, $legE['extras']));
    $v2_extra_ids  = x_ints(array_map(function ($e) { return (int) $e['id']; }, $v2E['extras']));
    xok($X, 'eq[extras]: same option id set + amount', $leg_extra_ids === $v2_extra_ids && (float) $legE['extras'][0]['amount'] === (float) $v2E['extras'][0]['amount'], 'leg=' . json_encode($leg_extra_ids) . ' v2=' . json_encode($v2_extra_ids));

    /* -- /availability (NOT value-comparable in-harness; compared via parts) */
    // hbapi_get_availability is not run here for two reasons, both environmental
    // (not plugin bugs): (1) it builds internal sub-requests with the real-WP
    // WP_REST_Request('GET', '<route>') signature where arg2 is the ROUTE, while
    // this harness stub treats arg2 as params (set_param then fatals); and (2) it
    // maps occupancy through the hardcoded rd_get_accom_map() host->accom_id
    // catalogue, which does not contain the fixture's accom_ids (101/102). Its
    // occupancy is therefore value-compared above via its own component handlers
    // (/reservations-occupancy + /blocked), which DO run in-harness.
    xok($X, 'eq[availability]: legacy delegate not value-comparable in-harness (WP_REST_Request route-arg + hardcoded accom map); verified via component handlers',
        function_exists('hbapi_get_availability'), 'handler missing');
}

/* ======================================================================= */
/* GROUP 4 — COMPAT SHIM                                                    */
/* ======================================================================= */
xarea($X, 'compat-shim');

if (!class_exists('MHA_Legacy_Shim')) { require MHA_PLUGIN_DIR . '/includes/compat/class-mha-legacy-shim.php'; }
update_option('mha_legacy_shim', 'off');
xok($X, 'shim: disabled by default (enabled() false)', MHA_Legacy_Shim::enabled() === false, 'enabled when off');
update_option('mha_legacy_shim', 'on');
xok($X, 'shim: enabled only when option == on', MHA_Legacy_Shim::enabled() === true, 'not enabled');

if ($legacy_loaded) {
    $winS = ['from' => '2026-07-01', 'to' => '2026-07-31'];
    $shimResp = MHA_Legacy_Shim::reservations_legacy_shape(req('GET', $winS));
    $shim = $shimResp->get_data();
    xok($X, 'shim: returns legacy-shaped envelope (status/reservations/_shim), not v2 ok/data/meta', ($shim['status'] ?? '') === 'ok' && isset($shim['reservations']) && !empty($shim['_shim']) && !isset($shim['data']), json_encode(array_keys($shim)));
    // compare shim rows to the real legacy handler's rows on shared fields
    $leg = legacy_call('hbapi_get_reservations', req('GET', $winS))->get_data();
    $leg_by = []; foreach ($leg['reservations'] as $r) { $leg_by[(int) $r['reservation_id']] = $r; }
    $shim_ids = x_ints(array_map(function ($r) { return (int) $r['reservation_id']; }, $shim['reservations']));
    $leg_ids2 = x_ints(array_map(function ($r) { return (int) $r['reservation_id']; }, $leg['reservations']));
    xok($X, 'shim: same reservation-id set as the real legacy handler', $shim_ids === $leg_ids2, 'shim=' . json_encode($shim_ids) . ' leg=' . json_encode($leg_ids2));
    $smatch = true; $sdetail = '';
    foreach ($shim['reservations'] as $sr) {
        $id = (int) $sr['reservation_id']; $lr = $leg_by[$id] ?? null;
        if (!$lr) { $smatch = false; $sdetail = "missing legacy #$id"; break; }
        // shared legacy field names both shapes carry:
        foreach (['check_in', 'check_out', 'status', 'accommodation_number'] as $f) {
            if ((string) $sr[$f] !== (string) $lr[$f]) { $smatch = false; $sdetail = "#$id.$f " . $sr[$f] . ' vs ' . $lr[$f]; break 2; }
        }
    }
    xok($X, 'shim: legacy-named fields (check_in/out/status/accommodation_number) match real legacy rows', $smatch, $sdetail);
    // Shim now resolves the REAL HBook accommodation name (via enrich_reservation),
    // a strict improvement over the legacy host-keyed rd_get_accom_map() placeholder.
    $shim0 = $shim['reservations'][0];
    xok($X, 'shim: accommodation_type resolves the real HBook name (not a bare accom_id placeholder)',
        isset($shim0['accommodation_type']) && $shim0['accommodation_type'] !== '' && !ctype_digit((string) $shim0['accommodation_type']),
        'acc_type=' . ($shim0['accommodation_type'] ?? 'null'));

    /* -- FULL legacy-shape parity incl. PII (CHANGE 2 shim guarantee) -------- */
    // The shim must carry EVERY legacy flat-row key the real handler emits.
    $legacy_keys = array_keys($leg['reservations'][0]);
    $shim_keys   = array_keys($shim['reservations'][0]);
    $missing     = array_diff($legacy_keys, $shim_keys);
    xok($X, 'shim: carries the full legacy key set (no legacy field dropped)', empty($missing), 'missing=' . json_encode(array_values($missing)));

    // Per-row value parity with the real legacy handler across every shared key
    // EXCEPT accommodation_type (shim resolves the real name; legacy used a
    // placeholder map that does not know the fixture accom_ids).
    $parity = true; $pdetail = '';
    foreach ($shim['reservations'] as $sr) {
        $id = (int) $sr['reservation_id']; $lr = $leg_by[$id] ?? null;
        if (!$lr) { $parity = false; $pdetail = "missing legacy #$id"; break; }
        foreach ($legacy_keys as $k) {
            if ($k === 'accommodation_type') { continue; }
            if ((string) ($sr[$k] ?? '') !== (string) ($lr[$k] ?? '')) {
                $parity = false; $pdetail = "#$id.$k shim=" . json_encode($sr[$k] ?? null) . ' leg=' . json_encode($lr[$k] ?? null); break 2;
            }
        }
    }
    xok($X, 'shim: full value parity with real legacy handler (all shared keys incl. PII; accommodation_type excepted)', $parity, $pdetail);

    $shim_r1 = null; foreach ($shim['reservations'] as $sr) { if ((int) $sr['reservation_id'] === 1) { $shim_r1 = $sr; break; } }

    // amount_paid reproduces the legacy FLAT paid column (bug-for-bug), not the
    // payments_logs sum that v2 /reservations uses.
    xok($X, 'shim: amount_paid reproduces legacy flat `paid`, NOT the payments_logs sum',
        $shim_r1 && (string) $shim_r1['amount_paid'] === (string) ($leg_by[1]['amount_paid'] ?? 'X'),
        'shim=' . ($shim_r1['amount_paid'] ?? 'null') . ' leg=' . ($leg_by[1]['amount_paid'] ?? 'null'));

    // Inline PII really is in the shim rows (compared to the live customer record,
    // which core tests mutated — so read it dynamically rather than hardcoding).
    $c1 = MHA_HBook::customer(1);
    xok($X, 'shim: inline PII present for resa#1 (first_name/last_name/email/phone/customer_name) == live customer record',
        $shim_r1 && $shim_r1['first_name'] !== '' && $shim_r1['first_name'] === (string) $c1['first_name']
        && $shim_r1['last_name'] === (string) $c1['last_name'] && $shim_r1['email'] === (string) $c1['email']
        && $shim_r1['phone'] === (string) $c1['phone']
        && $shim_r1['customer_name'] === trim($c1['first_name'] . ' ' . $c1['last_name']),
        json_encode($shim_r1 ? [$shim_r1['first_name'], $shim_r1['email'], $shim_r1['phone'], $shim_r1['customer_name']] : null));
}
update_option('mha_legacy_shim', 'off');

/* ======================================================================= */
/* GROUP 6 — AVAILABILITY: THREE DISTINCT METRICS (CHANGE 1)               */
/* ======================================================================= */
xarea($X, 'availability-metrics');

$hbdb_av = new HbDataBaseActions();

// Window containing the waiting_payment reservation r4 (accom 102 num1, 07-10..07-12).
$wm = ['from' => '2026-07-10', 'to' => '2026-07-13'];

update_option('hb_resa_paid_has_confirmation', 'no'); // site default
$av_no = MHA_HBook::availability($wm['from'], $wm['to'], 102);
$a102 = null; foreach ($av_no['by_accom'] as $b) { if ($b['accom_id'] === 102) { $a102 = $b; } }
xok($X, 'avail: by_accom + totals carry the three metrics (physical_occupied/reserved/bookable_free)',
    $a102 && isset($a102['physical_occupied'], $a102['reserved'], $a102['bookable_free'], $a102['total_units'])
    && isset($av_no['totals']['physical_occupied'], $av_no['totals']['reserved'], $av_no['totals']['bookable_free']),
    json_encode($a102));
xok($X, 'avail: legacy `occupied` list still present (back-compat shape preserved)',
    isset($av_no['occupied']) && is_array($av_no['occupied']), 'occupied missing');
xok($X, 'avail: a waiting_payment reservation makes reserved(1) > physical_occupied(0) on accom 102',
    $a102 && $a102['reserved'] === 1 && $a102['physical_occupied'] === 0, json_encode($a102));

// bookable_free == total - native-unavailable, matching HBook's own authority (config=no)
$nat_no = $hbdb_av->get_unavailable_accom_num_per_date(102, $wm['from'], $wm['to']);
$bf_no_expected = (int) $a102['total_units'] - count($nat_no);
xok($X, 'avail(no): bookable_free == total - native get_unavailable_accom_num_per_date == 1 (waiting_payment ties up inventory)',
    $a102['bookable_free'] === $bf_no_expected && $a102['bookable_free'] === 1 && $av_no['bookable_free_config']['value'] === 'no',
    'bf=' . $a102['bookable_free'] . ' expected=' . $bf_no_expected . ' native=' . json_encode($nat_no));

// Toggle the site config -> native frees waiting_payment -> bookable_free rises, still matching native.
update_option('hb_resa_paid_has_confirmation', 'yes');
$av_yes = MHA_HBook::availability($wm['from'], $wm['to'], 102);
$a102y = null; foreach ($av_yes['by_accom'] as $b) { if ($b['accom_id'] === 102) { $a102y = $b; } }
$nat_yes = $hbdb_av->get_unavailable_accom_num_per_date(102, $wm['from'], $wm['to']);
$bf_yes_expected = (int) $a102y['total_units'] - count($nat_yes);
xok($X, 'avail(yes): toggling hb_resa_paid_has_confirmation=yes raises bookable_free 1->2 (waiting_payment freed), still == native',
    $a102y['bookable_free'] === $bf_yes_expected && $a102y['bookable_free'] === 2
    && $av_yes['bookable_free_config']['value'] === 'yes' && $av_yes['bookable_free_config']['waiting_payment_frees_inventory'] === true,
    'bf=' . $a102y['bookable_free'] . ' expected=' . $bf_yes_expected . ' native=' . json_encode($nat_yes));
xok($X, 'avail(yes): reserved is config-independent (still counts waiting_payment operationally) — reserved stays 1',
    $a102y['reserved'] === 1, json_encode($a102y));
update_option('hb_resa_paid_has_confirmation', 'no'); // restore

// A confirmed in-house booking counts as physical_occupied; invariants hold.
$av_p = MHA_HBook::availability('2026-07-01', '2026-07-05', 101);
$a101 = null; foreach ($av_p['by_accom'] as $b) { if ($b['accom_id'] === 101) { $a101 = $b; } }
xok($X, 'avail: a confirmed in-house booking counts in physical_occupied (accom 101 >= 1)',
    $a101 && $a101['physical_occupied'] >= 1, json_encode($a101));
$inv_ok = true; foreach ($av_p['by_accom'] as $b) { if ($b['bookable_free'] < 0 || $b['bookable_free'] > $b['total_units'] || $b['physical_occupied'] > $b['reserved']) { $inv_ok = false; } }
xok($X, 'avail: invariants hold for every accom (0<=bookable_free<=total; physical_occupied<=reserved)', $inv_ok, json_encode($av_p['by_accom']));

// The availability CONTROLLER surfaces the metrics + documentation.
$av_ctl = x_env($av->availability(req('GET', ['from' => '2026-07-10', 'to' => '2026-07-13'])));
xok($X, 'avail: controller response carries by_accom/totals + metrics_doc + bookable_free_config',
    ($av_ctl['ok'] ?? null) === true && isset($av_ctl['data']['by_accom'], $av_ctl['data']['totals'], $av_ctl['data']['metrics_doc'], $av_ctl['data']['bookable_free_config']),
    json_encode(array_keys($av_ctl['data'] ?? [])));

/* ======================================================================= */
/* GROUP 7 — RESERVATION ENRICHMENT + PII GATING (CHANGE 2)                */
/* ======================================================================= */
xarea($X, 'reservation-enrichment-pii');

$rc2  = new MHA_Reservations_Controller();
$win2 = ['from' => '2026-07-01', 'to' => '2026-07-31'];

// resa#3 (cancelled, customer 3 Eva) is untouched by the earlier mutation tests.
$get_row = function ($data, $id) { foreach ($data['reservations'] as $r) { if ((int) $r['id'] === $id) { return $r; } } return null; };
$c3 = MHA_HBook::customer(3);

// -- Default (no include): enrichment ALWAYS present, PII OMITTED --
$def = x_env($rc2->index(req('GET', $win2)));
$d3  = $get_row($def['data'], 3);
xok($X, 'enrich: default /reservations ALWAYS carries accommodation_type (resolved NAME), nights, amount_paid',
    $d3 && array_key_exists('accommodation_type', $d3) && $d3['accommodation_type'] !== null && $d3['accommodation_type'] !== ''
    && $d3['nights'] === 2 && array_key_exists('amount_paid', $d3),
    json_encode($d3 ? [$d3['accommodation_type'], $d3['nights'], $d3['amount_paid']] : null));
xok($X, 'enrich: amount_paid is the payments_logs sum (r3 has none -> 0.0), never the flat paid column',
    $d3 && (float) $d3['amount_paid'] === 0.0, 'amount_paid=' . ($d3['amount_paid'] ?? 'null'));
xok($X, 'enrich: default OMITS guest PII (no customer object / first_name / email / phone / special_requests)',
    $d3 && !isset($d3['customer']) && !isset($d3['first_name']) && !isset($d3['email']) && !isset($d3['phone']) && !isset($d3['special_requests']),
    json_encode(array_keys($d3 ?? [])));
xok($X, 'enrich: default carries customer_id only', $d3 && array_key_exists('customer_id', $d3) && (int) $d3['customer_id'] === 3, 'cid=' . ($d3['customer_id'] ?? 'null'));
xok($X, 'enrich: meta records pii_included=false by default', ($def['meta']['pii_included'] ?? null) === false, json_encode($def['meta'] ?? null));

// -- include=guest: PII appears, enrichment stays, meta records it --
$g  = x_env($rc2->index(req('GET', array_merge($win2, ['include' => 'guest']))));
$g3 = $get_row($g['data'], 3);
xok($X, 'enrich(include=guest): customer PII present (first_name/last_name/email/phone) == live customer record',
    $g3 && isset($g3['customer']) && $g3['customer']['first_name'] === (string) $c3['first_name']
    && $g3['customer']['last_name'] === (string) $c3['last_name'] && $g3['customer']['email'] === (string) $c3['email']
    && $g3['customer']['phone'] === (string) $c3['phone'],
    json_encode($g3['customer'] ?? null));
xok($X, 'enrich(include=guest): special_requests key added', $g3 && array_key_exists('special_requests', $g3), 'no special_requests');
xok($X, 'enrich(include=guest): non-PII enrichment still present (accommodation_type/nights)',
    $g3 && $g3['accommodation_type'] !== null && $g3['nights'] === 2, json_encode([$g3['accommodation_type'] ?? null, $g3['nights'] ?? null]));
xok($X, 'enrich(include=guest): meta records pii_included=true + include list',
    ($g['meta']['pii_included'] ?? null) === true && in_array('guest', $g['meta']['include'] ?? [], true), json_encode($g['meta'] ?? null));

// -- GET /reservations/{id} parity --
$sdef = x_env($rc2->show(req('GET', ['id' => 3])));
xok($X, 'enrich: /reservations/{id} default omits PII, includes enrichment + pii_included=false',
    ($sdef['meta']['pii_included'] ?? null) === false && !isset($sdef['data']['customer'])
    && array_key_exists('accommodation_type', $sdef['data']) && $sdef['data']['nights'] === 2,
    json_encode($sdef['meta'] ?? null));
$sg = x_env($rc2->show(req('GET', ['id' => 3, 'include' => 'guest'])));
xok($X, 'enrich: /reservations/{id} include=guest adds customer PII + pii_included=true',
    isset($sg['data']['customer']) && $sg['data']['customer']['email'] === (string) $c3['email'] && ($sg['meta']['pii_included'] ?? null) === true,
    json_encode($sg['data']['customer'] ?? null));

// -- include=payments: full payments_logs array (r5 is fully paid 6000) --
$pp = x_env($rc2->show(req('GET', ['id' => 5, 'include' => 'payments'])));
xok($X, 'enrich(include=payments): full payments_logs array present with real amounts (r5 = 6000)',
    isset($pp['data']['payments']) && is_array($pp['data']['payments']) && count($pp['data']['payments']) >= 1
    && (float) $pp['data']['payments'][0]['amount'] === 6000.0,
    json_encode($pp['data']['payments'] ?? null));
update_option('mha_legacy_shim', 'off');

/* ======================================================================= */
/* GROUP 5 — RESPONSE CONSISTENCY                                           */
/* ======================================================================= */
xarea($X, 'response-consistency');

// Install a notice/warning recorder for the duration of the endpoint sweep.
set_error_handler(function ($no, $str) {
    if ($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) { $GLOBALS['__extra_notices'][] = $str; }
    return false; // let normal handling continue too
});

$mc = new MHA_Meta_Controller();
$op = new MHA_Operations_Controller();

$cases = [
    ['meta',      $mc->meta(req('GET', [])), true],
    ['health',    $mc->health(req('GET', [])), true],
    ['events',    $mc->events(req('GET', ['since' => 0])), true],
    ['reservations', $rc->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-31'])), true],
    ['reservation.show', $rc->show(req('GET', ['id' => 1])), true],
    ['reservation.notfound', $rc->show(req('GET', ['id' => 999999])), false, 'not_found', 404],
    ['search',    $rc->search(req('GET', ['q' => 'Novak'])), true],
    ['availability', $av->availability(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])), true],
    ['availability.baddate', $av->availability(req('GET', ['from' => 'xx', 'to' => '2026-07-08'])), false, 'bad_request', 400],
    ['units',     $av->units(req('GET', [])), true],
    ['blocked',   $av->blocked(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-31'])), true],
    ['rules',     $av->rules(req('GET', [])), true],
    ['extras',    $av->extras(req('GET', [])), true],
    ['pricing.quote', $pc->quote(req('GET', ['accom_id' => 101, 'check_in' => '2026-07-01', 'check_out' => '2026-07-05', 'adults' => 2])), true],
    ['pricing.badrange', $pc->quote(req('GET', ['accom_id' => 101, 'check_in' => '2026-07-05', 'check_out' => '2026-07-01', 'adults' => 2])), false, 'bad_request', 400],
    ['occupancy', $op->occupancy(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])), true],
    ['outstanding', $op->outstanding(req('GET', [])), true],
    ['stats.monthly', $op->stats_monthly(req('GET', ['year' => 2026])), true],
    ['day',       $op->day(req('GET', ['date' => '2026-07-04'])), true],
    ['guest.show', $gc->show(req('GET', ['id' => 1])), true],
    ['guest.notfound', $gc->show(req('GET', ['id' => 999999])), false, 'not_found', 404],
];

foreach ($cases as $c) {
    $label = $c[0]; $resp = $c[1]; $expect_ok = $c[2];
    $body = $resp->get_data();
    $meta = $body['meta'] ?? [];
    $meta_ok = isset($meta['resort']) && array_key_exists('request_id', $meta) && ($meta['api'] ?? '') === 'matova/v2';
    if ($expect_ok) {
        xok($X, "resp[$label]: ok envelope + data + meta(resort/request_id/api) + HTTP 200",
            ($body['ok'] ?? null) === true && array_key_exists('data', $body) && $meta_ok && $resp->get_status() === 200,
            'http=' . $resp->get_status() . ' meta=' . json_encode($meta));
    } else {
        $code = $c[3]; $http = $c[4];
        xok($X, "resp[$label]: error envelope code=$code HTTP $http + meta present",
            ($body['ok'] ?? null) === false && ($body['error']['code'] ?? '') === $code && $resp->get_status() === $http
            && is_string($body['error']['message'] ?? null) && $meta_ok,
            'http=' . $resp->get_status() . ' code=' . ($body['error']['code'] ?? '?'));
    }
}
restore_error_handler();
xok($X, 'resp: no PHP notice/warning leaked across the endpoint sweep (incl. error paths)',
    count($GLOBALS['__extra_notices']) === 0, 'notices=' . json_encode(array_slice($GLOBALS['__extra_notices'], 0, 5)));

/* ----------------------------------------------------------------- report */
echo "\n============== EXTRA LIVE VERIFICATION ===============\n";
echo implode("\n", $X['log']), "\n";
echo "------------------------------------------------------\n";
echo "EXTRA TOTAL: PASS {$X['pass']}  FAIL {$X['fail']}\n";
echo ($X['fail'] === 0 ? "ALL EXTRA TESTS PASSED\n" : "EXTRA FAILURES PRESENT\n");

$GLOBALS['__extra_pass'] = $X['pass'];
$GLOBALS['__extra_fail'] = $X['fail'];

// Standalone invocation: exit non-zero on failure. When required from run-live,
// that file owns the final combined exit.
if (empty($GLOBALS['__run_live_active'])) {
    exit($X['fail'] === 0 ? 0 : 1);
}
