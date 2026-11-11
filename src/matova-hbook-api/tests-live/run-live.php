<?php
/**
 * LIVE verification: exercises the matova/v2 adapter against the REAL HBook
 * 2.2.1 classes on a real SQLite DB. Every assertion observes genuine DB state
 * and/or genuine HBook do_action() hook firings.
 *
 * Run: php tests-live/run-live.php
 */

require __DIR__ . '/bootstrap-live.php';

global $wpdb;
$T = ['pass' => 0, 'fail' => 0, 'log' => []];
function ok($T, $name, $cond, $detail = '') {
    if ($cond) { $T['pass']++; $T['log'][] = "  PASS  $name"; }
    else       { $T['fail']++; $T['log'][] = "  FAIL  $name  -- $detail"; }
    return $T;
}
function resa_row($id) { global $wpdb; return $wpdb->get_row("SELECT * FROM wp_hb_resa WHERE id = " . (int) $id, ARRAY_A); }
function count_rows($sql) { global $wpdb; return (int) $wpdb->get_var($sql); }
function req($method, $params, $headers = [], $body = '') { return new WP_REST_Request($method, $params, $headers, $body); }

// Enable live write modes for the mutation tests.
update_option('mha_write_mode', ['default' => 'live']);

echo "================= LIVE HBOOK VERIFICATION =================\n";

/* ============================================================= 1. PRICING */
hb_test_hook_reset();
$pc = new MHA_Pricing_Controller();
$r = $pc->quote(req('GET', ['accom_id' => 101, 'check_in' => '2026-07-01', 'check_out' => '2026-07-05', 'adults' => 2, 'children' => 0]));
$body = $r->get_data();
$price = $body['data']['price'] ?? null;
$T = ok($T, 'pricing: /pricing/quote returns REAL HbPriceCalc total 4000 (1000/night x4)', $price === 4000.0, 'got ' . json_encode($price));

$direct = MHA_HBook::quote(102, '2026-07-01', '2026-07-04', 2, 0); // 3 nights x1500
$T = ok($T, 'pricing: adapter quote(102,3n) == 4500 via real engine', is_array($direct) && $direct['price'] === 4500.0, json_encode($direct['price'] ?? $direct));

/* ============================================= 2. CREATE RESERVATION (live) */
hb_test_hook_reset();
$logs_before = count_rows("SELECT COUNT(*) FROM wp_hb_resa_logs");
$resa_before = count_rows("SELECT COUNT(*) FROM wp_hb_resa");
$create = MHA_HBook::create_reservation([
    'check_in' => '2026-09-01', 'check_out' => '2026-09-04', 'accom_id' => 102, 'accom_num' => 2,
    'adults' => 2, 'children' => 0, 'price' => 4500, 'accom_price' => 4500, 'status' => 'confirmed',
    'customer_id' => 1, 'currency' => 'CZK',
]);
$new_id = is_array($create) ? ($create['id'] ?? 0) : 0;
$row = $new_id ? resa_row($new_id) : null;
$T = ok($T, 'create: hb_resa row inserted via native create_resa', $row && $row['status'] === 'confirmed' && (int) $row['accom_id'] === 102, json_encode($row ? [$row['id'], $row['status']] : null));
$T = ok($T, 'create: hb_create_reservation hook fired', hb_test_hook_count('hb_create_reservation') === 1, hb_test_hook_count('hb_create_reservation'));
$T = ok($T, 'create: hb_reservations_updated hook fired', hb_test_hook_count('hb_reservations_updated') >= 1, hb_test_hook_count('hb_reservations_updated'));
$T = ok($T, 'create: resa_logs row written (create_resa)', count_rows("SELECT COUNT(*) FROM wp_hb_resa_logs") === $logs_before + 1, 'logs delta');
// accom 102 has accom_preparation_time=1 -> automatic_block_accom creates a prep-time block.
$prep_blocks = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE linked_resa_id = " . (int) $new_id);
$T = ok($T, 'create: automatic_block_accom created prep-time block linked to new resa', $prep_blocks >= 1, "prep blocks=$prep_blocks");

/* ===================================================== 3. SET STATUS + cancel */
// 3a confirm resa#2 (new -> confirmed)
hb_test_hook_reset();
$res = MHA_HBook::set_status(2, 'confirmed');
$T = ok($T, 'set_status: resa#2 new->confirmed (DB)', resa_row(2)['status'] === 'confirmed', resa_row(2)['status']);
$T = ok($T, "set_status: hb_reservation_updated('status',2) fired", hb_test_hook_count('hb_reservation_updated', 'status', 2) === 1, hb_test_hook_count('hb_reservation_updated', 'status', 2));

// 3b cancel the just-created reservation -> status cancelled AND linked blocks deleted
hb_test_hook_reset();
$blocks_before_cancel = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE linked_resa_id = " . (int) $new_id);
MHA_HBook::set_status($new_id, 'cancelled');
$blocks_after_cancel = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE linked_resa_id = " . (int) $new_id);
$T = ok($T, 'set_status cancel: status -> cancelled', resa_row($new_id)['status'] === 'cancelled', resa_row($new_id)['status']);
$T = ok($T, 'set_status cancel: linked blocked rows deleted', $blocks_before_cancel >= 1 && $blocks_after_cancel === 0, "before=$blocks_before_cancel after=$blocks_after_cancel");

/* ====================================================== 4. ADD PAYMENT (logs) */
hb_test_hook_reset();
$paid_col_before = resa_row(1)['paid'];
$logs_before = json_decode(resa_row(1)['payments_logs'], true);
$n_before = is_array($logs_before) ? count($logs_before) : 0;
$pay = MHA_HBook::add_payment(1, false, ['amount' => 1000.0, 'received_on' => '2026-06-05', 'payment_method' => 'card', 'comment' => 'api']);
$logs_after = json_decode(resa_row(1)['payments_logs'], true);
$n_after = is_array($logs_after) ? count($logs_after) : 0;
$T = ok($T, 'add_payment: appended to payments_logs JSON (count +1)', $n_after === $n_before + 1, "before=$n_before after=$n_after");
$T = ok($T, 'add_payment: flat `paid` column NOT written', (float) resa_row(1)['paid'] === (float) $paid_col_before, 'paid=' . resa_row(1)['paid']);
$T = ok($T, 'add_payment: new entry carries amount 1000', $n_after > 0 && (float) $logs_after[$n_after - 1]['amount'] === 1000.0, json_encode($logs_after[$n_after - 1] ?? null));
$T = ok($T, "add_payment: hb_reservation_updated('paid',1) fired", hb_test_hook_count('hb_reservation_updated', 'paid', 1) === 1, hb_test_hook_count('hb_reservation_updated', 'paid', 1));
$sum = MHA_HBook::payments_sum(resa_row(1)['payments_logs']);
$T = ok($T, 'add_payment: payments_sum reflects logs (2000+1000=3000)', $sum === 3000.0, "sum=$sum");

/* ================================================= 5. CREATE / DELETE BLOCK */
hb_test_hook_reset();
$blocks_before = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked");
$cb = MHA_HBook::create_block(101, 1, '2026-10-01', '2026-10-05', 'api manual block');
$blocks_after = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked");
$T = ok($T, 'create_block: hb_accom_blocked row created (+1)', $blocks_after === $blocks_before + 1, "before=$blocks_before after=$blocks_after");
$T = ok($T, 'create_block: hb_blocked_accom_updated hook fired', hb_test_hook_count('hb_blocked_accom_updated') >= 1, hb_test_hook_count('hb_blocked_accom_updated'));

// find the new block's id and delete it
$new_block = $wpdb->get_row("SELECT * FROM wp_hb_accom_blocked WHERE comment = 'api manual block'", ARRAY_A);
hb_test_hook_reset();
$db = MHA_HBook::delete_block((int) $new_block['id']);
$still = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE id = " . (int) $new_block['id']);
$T = ok($T, 'delete_block: block row removed by (date+accom) native delete', $still === 0 && !is_wp_error($db), json_encode($db));
$T = ok($T, 'delete_block: hb_blocked_accom_updated hook fired', hb_test_hook_count('hb_blocked_accom_updated') >= 1, hb_test_hook_count('hb_blocked_accom_updated'));

// refuse deleting a block linked to a reservation (seed block id=2 linked_resa_id=5)
$refuse = MHA_HBook::delete_block(2);
$linked_still = count_rows("SELECT COUNT(*) FROM wp_hb_accom_blocked WHERE id = 2");
$T = ok($T, 'delete_block: refuses linked block (409 conflict), row untouched', is_wp_error($refuse) && $refuse->get_error_code() === 'conflict' && $linked_still === 1, is_wp_error($refuse) ? $refuse->get_error_code() : 'no-error');

/* ======================================================= 6. UPDATE GUEST */
hb_test_hook_reset();
$before = MHA_HBook::customer(1);
$upd = MHA_HBook::update_guest(1, ['phone' => '+420999888777', 'city' => 'Praha']);
$after = MHA_HBook::customer(1);
$T = ok($T, 'update_guest: phone updated in hb_customers.info JSON', $after['phone'] === '+420999888777', $after['phone']);
$T = ok($T, 'update_guest: city updated in info JSON', $after['city'] === 'Praha', $after['city']);
$T = ok($T, 'update_guest: untouched field (first_name) preserved', $after['first_name'] === $before['first_name'], $after['first_name']);
$T = ok($T, 'update_guest: email column preserved', $after['email'] === $before['email'], $after['email']);

/* =============================================== 7. AVAILABILITY PARITY */
// Compare adapter occupied set vs HBook native get_unavailable_accom_num_per_date.
$hbdb = new HbDataBaseActions();
$ci = '2026-07-01'; $co = '2026-07-06';
$native = $hbdb->get_unavailable_accom_num_per_date(101, $ci, $co);
sort($native);
$adapter = MHA_HBook::availability($ci, $co, 101);
$adapter_nums = [];
foreach ($adapter['occupied'] as $o) { if ((int) $o['accom_id'] === 101) { $adapter_nums[] = (int) $o['accom_num']; } }
$adapter_nums = array_values(array_unique($adapter_nums));
sort($adapter_nums);
$native = array_map('intval', $native);
$T = ok($T, 'availability: adapter occupied set == HBook get_unavailable_accom_num_per_date', $adapter_nums === $native, 'adapter=' . json_encode($adapter_nums) . ' native=' . json_encode($native));

/* ==================================== 8. IDEMPOTENCY + CONCURRENCY + replay */
// 8a INSERT-IGNORE reservation path: two begins with same key -> 2nd sees the row.
$key = 'LIVE-IDEM-1';
$sha = hash('sha256', 'bodyA');
$b1 = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', $sha);
MHA_Store::idempotency_finish($key, json_encode(['ok' => true]), 200);
$b2 = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', $sha);
$T = ok($T, 'idempotency: first begin proceeds (null), INSERT-IGNORE reserves key', $b1 === null, json_encode($b1));
$T = ok($T, 'idempotency: replay returns stored response', is_array($b2) && !empty($b2['replay']) && $b2['http_status'] === 200, json_encode($b2));
$conflict = MHA_Store::idempotency_begin($key, 'POST /x', 'srdicko', hash('sha256', 'bodyB'));
$T = ok($T, 'idempotency: same key + different body -> conflict', $conflict === 'conflict', json_encode($conflict));
$dupe_rows = count_rows("SELECT COUNT(*) FROM wp_mha_idempotency WHERE key_hash = '" . hash('sha256', $key) . "'");
$T = ok($T, 'idempotency: exactly one row per key (INSERT IGNORE dedupe)', $dupe_rows === 1, "rows=$dupe_rows");

// 8b Two identical mutations with the same Idempotency-Key via the controller -> one effect.
$rc = new MHA_Reservations_Controller();
$hdr = ['Idempotency-Key' => 'LIVE-STATUS-7'];
$params = ['id' => 2, 'status' => 'new'];
hb_test_hook_reset();
$resp1 = $rc->set_status(req('POST', $params, $hdr, json_encode($params)));
$status_logs_1 = count_rows("SELECT COUNT(*) FROM wp_hb_resa_logs WHERE resa_id = 2 AND event = 'update'");
$resp2 = $rc->set_status(req('POST', $params, $hdr, json_encode($params)));
$status_logs_2 = count_rows("SELECT COUNT(*) FROM wp_hb_resa_logs WHERE resa_id = 2 AND event = 'update'");
$replay_hdr = $resp2->get_headers();
$T = ok($T, 'idempotency: duplicate mutation produced no second DB effect (log count stable)', $status_logs_1 === $status_logs_2, "first=$status_logs_1 second=$status_logs_2");
$T = ok($T, 'idempotency: replay response carries Idempotent-Replay header', isset($replay_hdr['Idempotent-Replay']) && $replay_hdr['Idempotent-Replay'] === 'true', json_encode($replay_hdr));
$T = ok($T, 'idempotency: replay body identical to first', json_encode($resp1->get_data()) === json_encode($resp2->get_data()), 'bodies differ');

/* ================================= 9. FAILURE PATH: write_disabled + audit */
update_option('mha_write_mode', ['default' => 'off']);
$audit_before = count_rows("SELECT COUNT(*) FROM wp_mha_audit");
$status_before = resa_row(1)['status'];
$resp = $rc->set_status(req('POST', ['id' => 1, 'status' => 'cancelled'], ['Idempotency-Key' => 'LIVE-FAIL-1'], json_encode(['id' => 1, 'status' => 'cancelled'])));
$audit_after = count_rows("SELECT COUNT(*) FROM wp_mha_audit");
$err = $resp->get_data();
$T = ok($T, 'failure(write_disabled): response is error envelope', isset($err['error']) || ($resp->get_status() >= 400), json_encode($err));
$T = ok($T, 'failure(write_disabled): audit row written', $audit_after === $audit_before + 1, "before=$audit_before after=$audit_after");
$T = ok($T, 'failure(write_disabled): DB unchanged (resa#1 status)', resa_row(1)['status'] === $status_before, resa_row(1)['status']);

// 9b genuine native-driven upstream_error (missing rate/season for unknown accom)
$bad = MHA_HBook::quote(999, '2026-07-01', '2026-07-03', 2, 0);
$T = ok($T, 'failure(upstream_error): real HbPriceCalc failure surfaces as upstream_error', is_wp_error($bad) && $bad->get_error_code() === 'upstream_error', is_wp_error($bad) ? $bad->get_error_code() : 'no-error');

/* ============================================================= 10. OUTBOX */
// Hook bridge turned HBook do_action()s into outbox events (observed earlier).
$events = count_rows("SELECT COUNT(*) FROM wp_mha_events");
$T = ok($T, 'outbox: HBook hooks produced outbox events (>0)', $events > 0, "events=$events");
$ev_types = $wpdb->get_results("SELECT DISTINCT type FROM wp_mha_events", ARRAY_A);
$types = array_map(function ($r) { return $r['type']; }, $ev_types);
$T = ok($T, 'outbox: reservation.created + reservation.updated + blocked_accom.updated emitted', in_array('reservation.created', $types) && in_array('reservation.updated', $types) && in_array('blocked_accom.updated', $types), json_encode($types));

/* ----------------------------------------------------------------- report */
echo implode("\n", $T['log']), "\n";
echo "==========================================================\n";
echo "LIVE TOTAL: PASS {$T['pass']}  FAIL {$T['fail']}\n";
echo ($T['fail'] === 0 ? "ALL LIVE TESTS PASSED\n" : "LIVE FAILURES PRESENT\n");

// Extra live groups (concurrency, failure-injection, legacy-equivalence,
// compat-shim, response-consistency). Shares this harness + fixture.
$GLOBALS['__run_live_active'] = true;
require __DIR__ . '/run-extra.php';
$extra_fail = (int) ($GLOBALS['__extra_fail'] ?? 0);

$combined_fail = $T['fail'] + $extra_fail;
echo "\n==========================================================\n";
echo "COMBINED LIVE: PASS " . ($T['pass'] + (int) ($GLOBALS['__extra_pass'] ?? 0)) . "  FAIL {$combined_fail}\n";
exit($combined_fail === 0 ? 0 : 1);
