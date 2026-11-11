<?php
/**
 * Hand-rolled assertion runner for matova-hbook-api.
 * Run: php tests/run.php
 *
 * Covers (CONTRACTS §10): auth, envelope, idempotency/store, read controllers,
 * and writes in off/dry_run. No external framework.
 */

require __DIR__ . '/bootstrap.php';

/* ----------------------------------------------------------- tiny framework */

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__fails'] = [];
$GLOBALS['__area'] = '';
$GLOBALS['__area_counts'] = [];

function area($name) { $GLOBALS['__area'] = $name; if (!isset($GLOBALS['__area_counts'][$name])) { $GLOBALS['__area_counts'][$name] = [0, 0]; } }

function ok($cond, $msg) {
    $a = $GLOBALS['__area'];
    if ($cond) {
        $GLOBALS['__pass']++;
        $GLOBALS['__area_counts'][$a][0]++;
    } else {
        $GLOBALS['__fail']++;
        $GLOBALS['__area_counts'][$a][1]++;
        $GLOBALS['__fails'][] = "[{$a}] {$msg}";
        echo "  FAIL [{$a}] {$msg}\n";
    }
}
function eq($expected, $actual, $msg) {
    $cond = $expected === $actual;
    if (!$cond) { $msg .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    ok($cond, $msg);
}

/** Helpers to drive a controller method and unwrap the envelope. */
function req($method = 'GET', $params = [], $headers = [], $body = '') {
    return new WP_REST_Request($method, $params, $headers, $body);
}
function envelope(WP_REST_Response $r) { return $r->get_data(); }

/* ================================================================= AUTH */

area('auth');

// Clean key store for deterministic auth tests.
update_option('mha_keys', []);
$GLOBALS['__caps'] = [];

$rawRead  = MHA_Auth::issue_key('ops-consumer', 'read', 'srdicko', 'read key');
$rawOps   = MHA_Auth::issue_key('ops-consumer', 'ops-write', 'srdicko', 'ops key');
$rawAdmin = MHA_Auth::issue_key('admin-consumer', 'admin', 'srdicko', 'admin key');
ok(is_string($rawRead) && strlen($rawRead) >= MHA_MIN_KEY_LEN, 'issue_key returns a raw key >= min length');

function authorize($raw, $scope, $extra_params = []) {
    $headers = $raw !== null ? ['authorization' => 'Bearer ' . $raw] : [];
    $r = req('GET', $extra_params, $headers);
    $cb = MHA_Auth::require_scope($scope);
    return $cb($r);
}

// valid issued key authorizes (round-trip via stored hash)
$res = authorize($rawRead, 'read');
ok($res === true, 'valid issued read key authorizes a read route (hash round-trip)');

// admin satisfies ops-write and read (scope ladder)
ok(authorize($rawAdmin, 'ops-write') === true, 'admin key satisfies ops-write');
ok(authorize($rawAdmin, 'read') === true, 'admin key satisfies read');
ok(MHA_Auth::scope_allows('admin', 'ops-write') && MHA_Auth::scope_allows('admin', 'read'), 'scope ladder: admin >= ops-write >= read');

// read key on an ops-write route -> 403 forbidden
$res = authorize($rawRead, 'ops-write');
ok(is_wp_error($res), 'read key on ops-write route is rejected');
eq('forbidden', $res->get_error_code(), 'read-on-write rejection code is forbidden');
eq(403, $res->get_error_data()['status'] ?? null, 'read-on-write rejection is HTTP 403');

// unknown key (valid length, not stored) -> 401
$unknown = 'mha_' . str_repeat('f', 48);
$res = authorize($unknown, 'read');
ok(is_wp_error($res) && $res->get_error_code() === 'unauthorized', 'unknown key -> unauthorized');
eq(401, $res->get_error_data()['status'] ?? null, 'unknown key -> HTTP 401');

// short key (< MHA_MIN_KEY_LEN) -> 401
$res = authorize('short', 'read');
ok(is_wp_error($res) && $res->get_error_code() === 'unauthorized', 'short key -> unauthorized');
eq(401, $res->get_error_data()['status'] ?? null, 'short key -> HTTP 401');

// revoked key -> 401
$rawRevoke = MHA_Auth::issue_key('temp', 'read', 'srdicko', 'to revoke');
$records = get_option('mha_keys');
$revId = null;
foreach ($records as $rec) { if (($rec['label'] ?? '') === 'to revoke') { $revId = $rec['id']; } }
ok(authorize($rawRevoke, 'read') === true, 'freshly issued key works before revoke');
ok(MHA_Auth::revoke_key($revId) === true, 'revoke_key finds and flips active:false');
$res = authorize($rawRevoke, 'read');
ok(is_wp_error($res) && $res->get_error_code() === 'unauthorized', 'revoked key -> unauthorized');

// api_key / key in the URL -> 403 (never accepted even with a valid bearer)
$r = req('GET', ['api_key' => $rawRead], ['authorization' => 'Bearer ' . $rawRead]);
$res = (MHA_Auth::require_scope('read'))($r);
ok(is_wp_error($res) && $res->get_error_code() === 'forbidden', '?api_key= is rejected with forbidden');
eq(403, $res->get_error_data()['status'] ?? null, '?api_key= rejection is HTTP 403');
$r = req('GET', ['key' => $rawRead], ['authorization' => 'Bearer ' . $rawRead]);
$res = (MHA_Auth::require_scope('read'))($r);
ok(is_wp_error($res) && $res->get_error_code() === 'forbidden', '?key= is rejected with forbidden');

// constant-time: a matching record listed AFTER a non-matching one still matches
// (proves the loop does not early-return on the first mismatch).
$keyB = 'mha_' . str_repeat('b', 48);
$recA = ['id' => 'k_nomatch', 'label' => 'A', 'consumer' => 'cA', 'scope' => 'read', 'resort' => 'srdicko',
         'hash' => MHA_Auth::hash_key('mha_' . str_repeat('a', 48)), 'created' => gmdate('c'), 'last_used' => null, 'active' => true];
$recB = ['id' => 'k_match', 'label' => 'B', 'consumer' => 'cB', 'scope' => 'ops-write', 'resort' => 'srdicko',
         'hash' => MHA_Auth::hash_key($keyB), 'created' => gmdate('c'), 'last_used' => null, 'active' => true];
update_option('mha_keys', [$recA, $recB]);
$res = authorize($keyB, 'read');
ok($res === true, 'matching key after a non-matching record still authorizes (no early-return)');
ok(MHA_Auth::context()['id'] === 'k_match', 'authorized principal is the second (matching) record');

// rate limit: per-key token bucket returns 429 after MHA_RATE_PER_MIN
update_option('mha_keys', []);
$rawRl = MHA_Auth::issue_key('rl-consumer', 'read', 'srdicko', 'rate key');
$limit = MHA_RATE_PER_MIN;
$last = null;
for ($i = 1; $i <= $limit + 1; $i++) { $last = authorize($rawRl, 'read'); }
ok(is_wp_error($last) && $last->get_error_code() === 'rate_limited', 'rate limit trips after configured count');
eq(429, $last->get_error_data()['status'] ?? null, 'rate limit -> HTTP 429');
ok(($last->get_error_data()['retry_after'] ?? 0) >= 1, 'rate limit carries a retry_after >= 1');

// Restore a clean key store + admin cap off for the remaining tests.
update_option('mha_keys', []);

/* ================================================================= ENVELOPE */

area('envelope');

$okResp = MHA_Response::ok(['x' => 1], 'srdicko');
ok($okResp instanceof WP_REST_Response, 'ok() returns WP_REST_Response');
eq(200, $okResp->get_status(), 'ok() default HTTP 200');
$b = $okResp->get_data();
eq(true, $b['ok'], 'ok() envelope ok:true');
eq(1, $b['data']['x'], 'ok() carries data');
ok(isset($b['meta']['resort'], $b['meta']['as_of'], $b['meta']['api'], $b['meta']['request_id']), 'ok() meta has resort/as_of/api/request_id');
eq('srdicko', $b['meta']['resort'], 'ok() meta.resort echoes resort');
eq('matova/v2', $b['meta']['api'], 'ok() meta.api is matova/v2');

$errMap = ['bad_request' => 400, 'unauthorized' => 401, 'forbidden' => 403, 'not_found' => 404,
           'conflict' => 409, 'rate_limited' => 429, 'write_disabled' => 503, 'upstream_error' => 502];
foreach ($errMap as $code => $http) {
    $e = MHA_Response::err($code, 'msg', [], 'srdicko');
    eq($http, $e->get_status(), "err('{$code}') maps to HTTP {$http}");
    $eb = $e->get_data();
    eq(false, $eb['ok'], "err('{$code}') envelope ok:false");
    eq($code, $eb['error']['code'], "err('{$code}') carries error.code");
}

$rl = MHA_Response::rate_limited(42, 'srdicko');
eq(429, $rl->get_status(), 'rate_limited() is HTTP 429');
eq('42', $rl->get_headers()['Retry-After'] ?? null, 'rate_limited() sets Retry-After header');
eq('rate_limited', $rl->get_data()['error']['code'], 'rate_limited() error.code');

/* ========================================================= IDEMPOTENCY/STORE */

area('idempotency/store');

$key1 = 'idem-key-1';
$sha1 = hash('sha256', 'body-one');
$begin = MHA_Store::idempotency_begin($key1, 'POST /x', 'srdicko', $sha1);
ok($begin === null, 'first idempotency_begin returns null (safe to proceed)');
MHA_Store::idempotency_finish($key1, json_encode(['ok' => true, 'data' => ['v' => 7]]), 201);
$replay = MHA_Store::idempotency_begin($key1, 'POST /x', 'srdicko', $sha1);
ok(is_array($replay) && !empty($replay['replay']), 'replay with same key+sha returns stored response');
eq(201, $replay['http_status'], 'replay carries stored http_status');
$dec = json_decode($replay['response_json'], true);
eq(7, $dec['data']['v'], 'replay response body is verbatim');

$conflict = MHA_Store::idempotency_begin($key1, 'POST /x', 'srdicko', hash('sha256', 'DIFFERENT'));
ok($conflict === 'conflict', 'same key + different request_sha -> conflict');

// events_since ordered + audit insert
$before_events = MHA_Store::last_event_id();
$e1 = MHA_Store::outbox_emit('reservation.created', 'srdicko', 'reservation', 55, ['id' => 55]);
$e2 = MHA_Store::outbox_emit('reservation.updated', 'srdicko', 'reservation', 55, ['id' => 55]);
ok($e2 > $e1, 'outbox ids increase');
$evs = MHA_Store::events_since($before_events, 100);
ok(count($evs) >= 2, 'events_since returns the new rows');
ok($evs[0]['id'] < $evs[count($evs) - 1]['id'], 'events_since is ordered ascending');
eq(55, $evs[0]['payload']['id'], 'events_since decodes payload_json');
eq($e2, MHA_Store::last_event_id(), 'last_event_id tracks max id');

// hook bridge -> outbox (subscribe_hooks ran during boot)
$pre = MHA_Store::last_event_id();
do_action('hb_create_reservation', 999);
$post = MHA_Store::events_since($pre, 10);
ok(count($post) === 1 && $post[0]['type'] === 'reservation.created', 'HBook hook hb_create_reservation emits an outbox event');

// audit insert
global $wpdb;
$audit_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit");
$aid = MHA_Store::audit([
    'request_id' => 'req-test', 'key_id' => 'k1', 'consumer' => 'c', 'scope' => 'ops-write',
    'resort_id' => 'srdicko', 'route' => 'POST /x', 'method' => 'POST', 'http_status' => 200,
    'target_type' => 'reservation', 'target_id' => '1', 'before_json' => ['a' => 1], 'after_json' => ['a' => 2],
]);
ok($aid > 0, 'audit() inserts a row and returns id');
eq($audit_before + 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit"), 'audit row count increments');
$arow = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_mha_audit WHERE id = %d", $aid));
eq('{"a":1}', $arow['before_json'], 'audit encodes before_json');

/* ========================================================= READ CONTROLLERS */

area('read-controllers');

$resController  = new MHA_Reservations_Controller();
$availController = new MHA_Availability_Controller();
$opsController  = new MHA_Operations_Controller();
$priceController = new MHA_Pricing_Controller();
$guestController = new MHA_Guests_Controller();
$metaController = new MHA_Meta_Controller();

// reservations list: window
$d = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])));
ok($d['ok'], 'reservations index ok');
eq(4, $d['data']['count'], 'window [07-01,07-08) returns 4 reservations (r1,r2,r3,r5)');
eq(1, $d['data']['reservations'][0]['id'], 'reservations ordered by check_in ASC: first is r1');

// window + status filter
$d = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08', 'status' => 'confirmed'])));
eq(2, $d['data']['count'], 'window + status=confirmed returns 2 (r1,r5)');

// pagination
$d = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08', 'limit' => 2, 'offset' => 0])));
eq(2, $d['data']['count'], 'pagination limit=2 returns 2 rows');
$ids_p1 = array_map(fn($r) => $r['id'], $d['data']['reservations']);
$d = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08', 'limit' => 2, 'offset' => 2])));
$ids_p2 = array_map(fn($r) => $r['id'], $d['data']['reservations']);
ok(count(array_intersect($ids_p1, $ids_p2)) === 0, 'pagination offset returns a disjoint page');

// bad date -> bad_request
$d = envelope($resController->index(req('GET', ['from' => 'not-a-date'])));
eq(false, $d['ok'], 'invalid date -> error envelope');
eq('bad_request', $d['error']['code'], 'invalid date -> bad_request');

// reservation by id
$d = envelope($resController->show(req('GET', ['id' => 1])));
ok($d['ok'] && $d['data']['id'] === 1, 'reservation by id returns the row');
eq('confirmed', $d['data']['status'], 'reservation status shaped');
eq(4000.0, $d['data']['price'], 'reservation price cast to float');

// reservation by id (group child) -> parent_id > 0; parent fetch works
$d = envelope($resController->show(req('GET', ['id' => 7])));
ok($d['data']['parent_id'] === 100, 'group child reservation exposes parent_id');
$parent = MHA_HBook::parent_reservation(100);
ok(is_array($parent) && (int) $parent['id'] === 100, 'parent_reservation() fetches the group parent row');

// not found
$d = envelope($resController->show(req('GET', ['id' => 99999])));
eq('not_found', $d['error']['code'], 'missing reservation -> not_found');

// search by name
$d = envelope($resController->search(req('GET', ['q' => 'Novakova'])));
ok($d['ok'], 'search ok');
$found = array_filter($d['data']['reservations'], fn($r) => $r['id'] === 2);
ok(!empty($found), 'search by customer last name finds the joined reservation (r2)');

// availability: occupied consistent with active resas (no blocks in window)
$d = envelope($availController->availability(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])));
ok($d['ok'], 'availability ok');
eq(3, count($d['data']['occupied']), 'availability [07-01,07-08): 3 occupied (active resas; cancelled+pending excluded)');
$srcs = array_count_values(array_map(fn($o) => $o['source'], $d['data']['occupied']));
eq(3, $srcs['reservation'] ?? 0, 'all 3 occupancy entries are reservations');

// availability: window capturing only blocks
$d = envelope($availController->availability(req('GET', ['from' => '2026-07-14', 'to' => '2026-07-26'])));
$srcs = array_count_values(array_map(fn($o) => $o['source'], $d['data']['occupied']));
eq(2, $srcs['block'] ?? 0, 'block window [07-14,07-26): 2 block occupancies');
ok(!isset($srcs['reservation']), 'block window has no reservation occupancy');

// units
$d = envelope($availController->units(req('GET')));
eq(4, count($d['data']['units']), 'units lists 4 seeded accom_num_name rows');
ok(isset($d['data']['units'][0]['accom_id'], $d['data']['units'][0]['num_name']), 'unit rows carry accom_id + num_name');

// blocked
$d = envelope($availController->blocked(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-31'])));
eq(2, $d['data']['count'], 'blocked list returns both seeded blocks within July');

// pricing/quote (HBook price stub)
$d = envelope($priceController->quote(req('GET', ['accom_id' => 101, 'check_in' => '2026-07-01', 'check_out' => '2026-07-05', 'adults' => 2])));
ok($d['ok'], 'quote ok');
ok(is_numeric($d['data']['price']) && $d['data']['price'] == 2000.0, 'quote shapes native price (4 nights x 500 = 2000)');
eq(4, $d['data']['breakdown']['nights'], 'quote carries native breakdown');
// quote validation
$d = envelope($priceController->quote(req('GET', ['accom_id' => 101, 'check_in' => '2026-07-05', 'check_out' => '2026-07-01', 'adults' => 2])));
eq('bad_request', $d['error']['code'], 'quote rejects check_out <= check_in');

// operations/day: arrivals/departures/in-house
$d = envelope($opsController->day(req('GET', ['date' => '2026-07-04'])));
eq(1, $d['data']['counts']['arrivals'], '/day 07-04 arrivals=1 (r5)');
eq(3, $d['data']['counts']['in_house'], '/day 07-04 in_house=3 (r1,r2,r5)');
eq(0, $d['data']['counts']['departures'], '/day 07-04 departures=0 (r3 is cancelled)');
$d = envelope($opsController->day(req('GET', ['date' => '2026-07-05'])));
eq(1, $d['data']['counts']['departures'], '/day 07-05 departures=1 (r1 checks out)');

// operations/outstanding: price - sum(payments_logs)
$d = envelope($opsController->outstanding(req('GET')));
ok($d['ok'], 'outstanding ok');
eq(11500.0, $d['data']['total_outstanding'], 'outstanding total = 2000+3000+1500+2500+2500');
eq(5, $d['data']['count'], 'outstanding lists 5 unpaid active reservations');
$has_r5 = array_filter($d['data']['items'], fn($i) => $i['id'] === 5);
ok(empty($has_r5), 'fully-paid r5 excluded from outstanding');
$r1item = array_values(array_filter($d['data']['items'], fn($i) => $i['id'] === 1))[0];
eq(2000.0, $r1item['outstanding'], 'r1 outstanding = price 4000 - paid 2000');
eq(2000.0, $r1item['paid'], 'r1 paid summed from payments_logs');

// operations/occupancy
$d = envelope($opsController->occupancy(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])));
ok($d['ok'], 'occupancy ok');
eq(4, $d['data']['units_total'], 'occupancy units_total = 4 seeded units');
eq(7, $d['data']['nights'], 'occupancy window spans 7 nights');
eq(28, $d['data']['capacity_unit_nights'], 'occupancy capacity = 4 units x 7 nights');
ok($d['data']['occupied_unit_nights'] > 0 && $d['data']['occupied_unit_nights'] <= 28, 'occupied_unit_nights within capacity');
ok($d['data']['occupancy_rate'] !== null && $d['data']['occupancy_rate'] > 0, 'occupancy_rate computed');
$d = envelope($opsController->occupancy(req('GET', ['from' => '2026-07-08', 'to' => '2026-07-01'])));
eq('bad_request', $d['error']['code'], 'occupancy rejects to <= from');

// stats/monthly
$d = envelope($opsController->stats_monthly(req('GET', ['year' => 2026])));
ok($d['ok'] && $d['data']['year'] === 2026, 'stats/monthly ok for 2026');
eq(12, count($d['data']['months']), 'stats/monthly returns 12 months');
// July check-ins among active: r1,r2,r5 (r4 too) = 4 ; r3 cancelled, r6 pending excluded
eq(4, $d['data']['months'][7]['reservations'], 'stats July counts 4 active check-ins');
eq(2, $d['data']['months'][8]['reservations'], 'stats August counts 2 group children');
ok($d['data']['months'][7]['revenue'] > 0, 'stats July revenue summed');

// meta
$d = envelope($metaController->meta(req('GET')));
ok($d['ok'], 'meta ok');
eq(8, $d['data']['table_counts']['resa'], 'meta table_counts.resa = 8 (backtick COUNT query)');
eq('off', $d['data']['write_modes']['status'], 'meta reports write_modes (status off by default)');
ok(is_array($d['data']['resort']), 'meta carries the resort registry record');

// events endpoint
$d = envelope($metaController->events(req('GET', ['since' => 0, 'limit' => 100])));
ok($d['ok'] && isset($d['data']['events'], $d['data']['next_cursor']), 'events endpoint returns events + next_cursor');

// guests read
$d = envelope($guestController->show(req('GET', ['id' => 2])));
ok($d['ok'] && $d['data']['last_name'] === 'Novakova', 'guest read shapes info JSON into fields');

/* ================================================================= WRITES */

area('writes');

// Reset write modes to default (off) and ensure no HBook native db/utils exist.
delete_option('mha_write_mode');
$GLOBALS['__caps'] = [];

// Missing Idempotency-Key on a mutation -> bad_request (before any work)
$d = envelope($resController->set_status(req('POST', ['id' => 1, 'status' => 'confirmed'])));
eq(false, $d['ok'], 'mutation without Idempotency-Key -> error');
eq('bad_request', $d['error']['code'], 'missing Idempotency-Key -> bad_request');

// write mode off -> write_disabled, audit still written, reservation unchanged
$audit_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit");
$r = $resController->set_status(req('POST', ['id' => 1, 'status' => 'confirmed'], ['idempotency-key' => 'w-off-1']));
$d = envelope($r);
eq(503, $r->get_status(), 'status write (off) -> HTTP 503');
eq('write_disabled', $d['error']['code'], 'status write (off) -> write_disabled');
eq($audit_before + 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit"), 'off write still writes an audit row');
eq('confirmed', MHA_HBook::reservation(1)['status'], 'off write did not mutate the reservation');

// create (admin, gated create) off -> write_disabled
$r = $resController->create(req('POST', ['adults' => 2], ['idempotency-key' => 'w-create-1'], json_encode(['accom_id' => 101])));
eq(503, $r->get_status(), 'create (off) -> HTTP 503 write_disabled');
eq('write_disabled', envelope($r)['error']['code'], 'create (off) error.code write_disabled');

// dry_run: returns a would_* effect, NO native call, still audits, no mutation
update_option('mha_write_mode', ['status' => 'dry_run', 'block' => 'dry_run', 'payment' => 'dry_run']);
$audit_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit");
$r = $resController->set_status(req('POST', ['id' => 1, 'status' => 'cancelled'], ['idempotency-key' => 'w-dry-1']));
$d = envelope($r);
eq(200, $r->get_status(), 'status write (dry_run) -> HTTP 200');
ok($d['ok'] === true, 'dry_run envelope ok:true');
eq('dry_run', $d['data']['mode'] ?? null, 'dry_run result carries mode=dry_run');
ok(isset($d['data']['would']) && $d['data']['would']['status'] === 'cancelled', 'dry_run returns the would-be effect without applying it');
eq($audit_before + 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_mha_audit"), 'dry_run still writes an audit row');
eq('confirmed', MHA_HBook::reservation(1)['status'], 'dry_run did NOT mutate the reservation (still confirmed)');

// dry_run block create -> would, no native
$r = $availController->create_block(req('POST', ['accom_id' => 101, 'accom_num' => 1, 'from' => '2026-09-01', 'to' => '2026-09-03'], ['idempotency-key' => 'w-dry-blk']));
$d = envelope($r);
ok($d['ok'] && ($d['data']['mode'] ?? '') === 'dry_run' && ($d['data']['would']['action'] ?? '') === 'create_block', 'block create (dry_run) returns would create_block');

// idempotent replay via the full mutate() path (same key + body -> verbatim replay)
$r1 = $resController->set_status(req('POST', ['id' => 1, 'status' => 'cancelled'], ['idempotency-key' => 'w-replay-1']));
$r2 = $resController->set_status(req('POST', ['id' => 1, 'status' => 'cancelled'], ['idempotency-key' => 'w-replay-1']));
eq('true', $r2->get_headers()['Idempotent-Replay'] ?? null, 'second identical mutation is served as an idempotent replay');
eq($r1->get_data(), $r2->get_data(), 'replayed mutation body is identical to the first');

// same idempotency key, different body -> conflict
$r3 = $resController->set_status(req('POST', ['id' => 1, 'status' => 'confirmed'], ['idempotency-key' => 'w-replay-1']));
eq(409, $r3->get_status(), 'same Idempotency-Key with a different body -> HTTP 409');
eq('conflict', envelope($r3)['error']['code'], 'idempotency conflict error.code');

// guest PATCH with no editable fields -> bad_request
update_option('mha_write_mode', ['guest' => 'dry_run']);
$r = $guestController->update(req('PATCH', ['id' => 2], ['idempotency-key' => 'w-guest-bad'], json_encode(['nonsense' => 'x'])));
eq('bad_request', envelope($r)['error']['code'], 'guest PATCH with no editable fields -> bad_request');

// guest PATCH dry_run with a valid field -> would, no mutation
$r = $guestController->update(req('PATCH', ['id' => 2], ['idempotency-key' => 'w-guest-ok'], json_encode(['phone' => '+420777000000'])));
$d = envelope($r);
ok($d['ok'] && ($d['data']['mode'] ?? '') === 'dry_run' && isset($d['data']['would']['fields']['phone']), 'guest PATCH (dry_run) returns would update of phone');
eq('+420600000002', MHA_HBook::customer(2)['phone'], 'guest dry_run did NOT mutate the customer');

// payment dry_run -> would (appends to payments_logs model, never flat paid)
update_option('mha_write_mode', ['payment' => 'dry_run']);
$r = (new MHA_Payments_Controller())->add_payment(req('POST', ['id' => 1, 'amount' => 500], ['idempotency-key' => 'w-pay-1']));
$d = envelope($r);
ok($d['ok'] && ($d['data']['would']['action'] ?? '') === 'add_payment', 'payment (dry_run) returns would add_payment');

// payment missing amount -> bad_request
$r = (new MHA_Payments_Controller())->add_payment(req('POST', ['id' => 1], ['idempotency-key' => 'w-pay-bad']));
eq('bad_request', envelope($r)['error']['code'], 'payment without amount -> bad_request');

/* ======================================= PAYMENT CONTRACT WITH MATOVA OS */
area('payment-contract');
{
    // Exactly the body hbook-write-proxy builds (supabase/functions/hbook-write-proxy/v2Writes.ts).
    update_option('mha_write_mode', ['payment' => 'dry_run']);
    $pc = new MHA_Payments_Controller();

    // Standalone: amount + received_on + payment_method (+ note).
    $d = envelope($pc->add_payment(req('POST', ['id' => 1, 'amount' => 1200.46, 'received_on' => '2026-11-11', 'payment_method' => 'cash', 'note' => 'doklad 12'], ['idempotency-key' => 'pc-1'])));
    $w = $d['data']['would'] ?? [];
    ok($d['ok'] && ($w['action'] ?? '') === 'add_payment', 'standalone payment accepted (dry_run)');
    eq(1, $w['id'] ?? null, 'standalone payment targets the reservation id');
    eq(false, $w['is_parent'] ?? null, 'standalone payment is not a parent payment');
    eq(1200.46, $w['payment']['amount'] ?? null, 'amount carried with cents');
    eq('cash', $w['payment']['payment_method'] ?? null, 'payment_method carried');
    eq('2026-11-11', $w['payment']['received_on'] ?? null, 'received_on carried');
    eq('doklad 12', $w['payment']['comment'] ?? null, 'note stored as HBook comment');
    eq('no', $w['payment']['online'] ?? null, 'local payment is never marked online');

    // Group: parent id + is_parent=true.
    $d = envelope($pc->add_payment(req('POST', ['id' => 100, 'is_parent' => true, 'amount' => 1500, 'received_on' => '2026-11-11', 'payment_method' => 'transfer'], ['idempotency-key' => 'pc-2'])));
    $w = $d['data']['would'] ?? [];
    ok($d['ok'] && ($w['is_parent'] ?? null) === true && ($w['id'] ?? null) === 100, 'group payment targets the PARENT with is_parent');

    // A parent id sent WITHOUT is_parent must not silently hit a same-numbered member.
    $d = envelope($pc->add_payment(req('POST', ['id' => 100, 'amount' => 1500, 'payment_method' => 'cash'], ['idempotency-key' => 'pc-3'])));
    eq('not_found', $d['error']['code'] ?? null, 'parent id without is_parent -> not_found (no member with that id)');
    // A member id sent WITH is_parent must not hit a parent.
    $d = envelope($pc->add_payment(req('POST', ['id' => 1, 'is_parent' => true, 'amount' => 10, 'payment_method' => 'cash'], ['idempotency-key' => 'pc-4'])));
    eq('not_found', $d['error']['code'] ?? null, 'member id with is_parent -> not_found (no parent with that id)');

    // Same Idempotency-Key + same body = one payment (replay), different body = conflict.
    $again = envelope($pc->add_payment(req('POST', ['id' => 1, 'amount' => 1200.46, 'received_on' => '2026-11-11', 'payment_method' => 'cash', 'note' => 'doklad 12'], ['idempotency-key' => 'pc-1'])));
    ok(!empty($again['ok']) || in_array($again['error']['code'] ?? '', ['conflict'], true), 'retry with the same key is a replay, never a second payment');
    $diff = envelope($pc->add_payment(req('POST', ['id' => 1, 'amount' => 999, 'payment_method' => 'cash'], ['idempotency-key' => 'pc-1'])));
    eq('conflict', $diff['error']['code'] ?? null, 'same key with a different amount -> conflict');

    // dry_run never mutates.
    eq(1000.0, MHA_HBook::payments_sum(MHA_HBook::parent_reservation(100)['payments_logs']), 'dry_run did not change the group\'s payments');

    // Group money read-back.
    $g = envelope($pc->group_payments(req('GET', ['id' => 100])));
    ok($g['ok'] && $g['data']['parent_id'] === 100, 'group payments readable');
    eq(5000.0, $g['data']['price'], 'group price from the parent');
    eq(1000.0, $g['data']['amount_paid'], 'group paid = sum of the parent\'s payments_logs');
    eq(1, count($g['data']['payments']), 'payment lines listed');
    eq('not_found', envelope($pc->group_payments(req('GET', ['id' => 424242])))['error']['code'] ?? null, 'unknown group -> not_found');
    delete_option('mha_write_mode');
}

/* ============================================ AVAILABILITY METRICS (CHANGE 1) */

area('availability-metrics');

// Window with the waiting_payment reservation r4 (accom 102 num1, 07-10..07-12).
$d = envelope($availController->availability(req('GET', ['from' => '2026-07-10', 'to' => '2026-07-13', 'accom_id' => 102])));
ok($d['ok'], 'availability metrics ok');
$a102 = null; foreach ($d['data']['by_accom'] as $b) { if ($b['accom_id'] === 102) { $a102 = $b; } }
ok($a102 && isset($a102['physical_occupied'], $a102['reserved'], $a102['bookable_free'], $a102['total_units']), 'by_accom carries the three metrics + total_units');
ok(isset($d['data']['totals']['bookable_free']) && isset($d['data']['occupied']), 'totals present AND legacy occupied list preserved (back-compat)');
eq(0, $a102['physical_occupied'], 'physical_occupied=0 (r4 is waiting_payment, not an in-house {confirmed,new} stay)');
eq(1, $a102['reserved'], 'reserved=1 (waiting_payment ties up inventory operationally)');
ok($a102['reserved'] > $a102['physical_occupied'], 'waiting_payment makes reserved > physical_occupied');

// bookable_free follows the hb_resa_paid_has_confirmation option (HBook rule).
update_option('hb_resa_paid_has_confirmation', 'no');
$d = envelope($availController->availability(req('GET', ['from' => '2026-07-10', 'to' => '2026-07-13', 'accom_id' => 102])));
$bf_no = null; foreach ($d['data']['by_accom'] as $b) { if ($b['accom_id'] === 102) { $bf_no = $b['bookable_free']; } }
eq(1, $bf_no, 'bookable_free=1 when hb_resa_paid_has_confirmation=no (waiting_payment counts as unavailable)');
ok($d['data']['bookable_free_config']['value'] === 'no' && $d['data']['bookable_free_config']['waiting_payment_frees_inventory'] === false, 'bookable_free_config reflects option=no');
update_option('hb_resa_paid_has_confirmation', 'yes');
$d = envelope($availController->availability(req('GET', ['from' => '2026-07-10', 'to' => '2026-07-13', 'accom_id' => 102])));
$bf_yes = null; foreach ($d['data']['by_accom'] as $b) { if ($b['accom_id'] === 102) { $bf_yes = $b['bookable_free']; } }
eq(2, $bf_yes, 'bookable_free=2 when hb_resa_paid_has_confirmation=yes (waiting_payment freed)');
ok($d['data']['bookable_free_config']['waiting_payment_frees_inventory'] === true, 'bookable_free_config reflects option=yes');
update_option('hb_resa_paid_has_confirmation', 'no'); // restore

/* ========================================= RESERVATION ENRICHMENT + PII (CHANGE 2) */

area('reservation-enrichment-pii');

// Default: non-PII enrichment ALWAYS present, guest PII OMITTED (customer_id only).
$def = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08'])));
$d3 = null; foreach ($def['data']['reservations'] as $r) { if ($r['id'] === 3) { $d3 = $r; } }
ok($d3 && array_key_exists('accommodation_type', $d3) && array_key_exists('nights', $d3) && array_key_exists('amount_paid', $d3), 'default /reservations always carries accommodation_type/nights/amount_paid');
eq(2, $d3['nights'], 'nights computed (r3 02->04 = 2)');
ok(!isset($d3['customer']) && !isset($d3['first_name']) && !isset($d3['email']) && !isset($d3['special_requests']), 'default OMITS guest PII and special_requests');
ok(array_key_exists('customer_id', $d3) && $d3['customer_id'] === 3, 'default carries customer_id only');
eq(false, $def['meta']['pii_included'], 'meta records pii_included=false by default');

// include=guest: PII appears; meta records it.
$g = envelope($resController->index(req('GET', ['from' => '2026-07-01', 'to' => '2026-07-08', 'include' => 'guest'])));
$g3 = null; foreach ($g['data']['reservations'] as $r) { if ($r['id'] === 3) { $g3 = $r; } }
ok($g3 && isset($g3['customer']) && $g3['customer']['first_name'] === 'Eva' && $g3['customer']['email'] === 'eva.dvorak@example.test', 'include=guest adds customer PII');
ok(array_key_exists('special_requests', $g3), 'include=guest adds special_requests');
eq(true, $g['meta']['pii_included'], 'meta records pii_included=true with include=guest');

// show() parity + include=payments.
$sg = envelope($resController->show(req('GET', ['id' => 1, 'include' => 'guest'])));
ok(isset($sg['data']['customer']) && $sg['data']['customer']['email'] === 'anna.svoboda@example.test', '/reservations/{id} include=guest adds PII');
$sp = envelope($resController->show(req('GET', ['id' => 1, 'include' => 'payments'])));
ok(isset($sp['data']['payments']) && is_array($sp['data']['payments']) && (float) $sp['data']['payments'][0]['amount'] === 2000.0, '/reservations/{id} include=payments adds payments_logs array');
ok((float) $sp['data']['amount_paid'] === 2000.0, 'amount_paid = payments_logs sum (r1 = 2000), not flat paid');

/* ============================================================ UPDATE SIGNING */
area('update-signing');
{
    $kp   = sodium_crypto_sign_keypair();
    $pub  = sodium_crypto_sign_publickey($kp);
    $sec  = sodium_crypto_sign_secretkey($kp);
    $zip  = random_bytes(4096);
    $msg  = MHA_Updater::signed_message('2.4.0', $zip);
    $sig  = base64_encode(sodium_crypto_sign_detached($msg, $sec));
    ok(MHA_Updater::signature_valid($msg, $sig, $pub) === true, 'valid signature accepted');
    ok(MHA_Updater::signature_valid(MHA_Updater::signed_message('2.4.0', $zip . 'x'), $sig, $pub) === false, 'one extra byte in the package rejected');
    $tampered = $zip; $tampered[10] = chr(ord($tampered[10]) ^ 1);
    ok(MHA_Updater::signature_valid(MHA_Updater::signed_message('2.4.0', $tampered), $sig, $pub) === false, 'one flipped bit in the package rejected');
    ok(MHA_Updater::signature_valid(MHA_Updater::signed_message('2.5.0', $zip), $sig, $pub) === false, 'same ZIP replayed under another version rejected');
    $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    ok(MHA_Updater::signature_valid($msg, $sig, $other) === false, 'signature from a different key rejected');
    ok(MHA_Updater::signature_valid($msg, '', $pub) === false, 'missing signature rejected');
    ok(MHA_Updater::signature_valid($msg, 'not-base64!!', $pub) === false, 'malformed signature rejected');
    ok(MHA_Updater::signature_valid($msg, base64_encode('short'), $pub) === false, 'wrong-length signature rejected');
    ok(MHA_Updater::signature_valid('', $sig, $pub) === false, 'empty message rejected');
    ok(MHA_Updater::signature_valid($msg, $sig, 'short-key') === false, 'wrong-length key rejected');
    // Rotation: a signature under EITHER trusted key verifies; an empty key set trusts nothing.
    $kp2 = sodium_crypto_sign_keypair(); $pub2 = sodium_crypto_sign_publickey($kp2);
    $sig2 = base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp2)));
    ok(MHA_Updater::signature_trusted($msg, $sig2, [$pub, $pub2]) === true, 'signature under the NEXT key accepted during rotation');
    ok(MHA_Updater::signature_trusted($msg, $sig, [$pub, $pub2]) === true, 'signature under the CURRENT key still accepted during rotation');
    ok(MHA_Updater::signature_trusted($msg, $sig2, [$pub]) === false, 'signature under an untrusted key refused');
    ok(MHA_Updater::signature_trusted($msg, $sig, []) === false, 'no trusted keys ⇒ nothing verifies');
    // The source carries no private material and, until the managed signer's key is set, trusts nothing.
    ok(MHA_Updater::pubkeys() === [] || count(MHA_Updater::pubkeys()) <= 2, 'trusted key list is bounded');

    $OURS = ['plugin' => 'matova-hbook-api/matova-hbook-api.php'];
    $code = function ($r) { return is_wp_error($r) ? $r->get_error_code() : $r; };

    // Other plugins are never touched.
    set_transient('mha_update_manifest', ['version' => '9.9.9', 'download_url' => 'https://example.test/ours.zip', 'signature' => 'x'], 60);
    eq('untouched', MHA_Updater::verified_download('untouched', 'https://example.test/other-plugin.zip', null, ['plugin' => 'other/other.php']), 'other plugins\' downloads pass through');

    // FAIL CLOSED for our plugin, whatever is wrong.
    eq('mha_update_wrong_package', $code(MHA_Updater::verified_download(false, 'https://evil.test/ours.zip', null, $OURS)), 'our plugin from a URL that is not the manifest\'s is refused');
    set_transient('mha_update_manifest', 'none', 60);
    eq('mha_update_no_manifest', $code(MHA_Updater::verified_download(false, 'https://example.test/ours.zip', null, $OURS)), 'our plugin with the manifest unavailable is refused (no fail-open)');
    set_transient('mha_update_manifest', ['version' => '9.9.9', 'download_url' => 'https://example.test/ours.zip'], 60);
    eq('mha_update_unsigned', $code(MHA_Updater::verified_download(false, 'https://example.test/ours.zip', null, $OURS)), 'our plugin without a signature is refused');
    eq('mha_update_unsigned', $code(MHA_Updater::verified_download(false, 'https://example.test/ours.zip')), 'identified by URL alone (no hook_extra) → still verified');
    delete_transient('mha_update_manifest');
}

/* ======================================================== RELAY + LEGACY */
area('relay-legacy');
{
    ok(MHA_Relay_Controller::comgate_path_allowed('/v2.0/transfer') === true, 'comgate: transfer list allowed');
    ok(MHA_Relay_Controller::comgate_path_allowed('/v2.0/payment/transId/AB12-CD34') === true, 'comgate: payment status allowed');
    foreach (['/v2.0/payment', '/v1.0/create', '/v1.0/refund', '/v2.0/payment/transId/../refund', '/v2.0/transfer/x/y', 'https://evil.test/v2.0/transfer', ''] as $bad) {
        ok(MHA_Relay_Controller::comgate_path_allowed($bad) === false, 'comgate: write/unknown path refused: ' . ($bad === '' ? '(empty)' : $bad));
    }
    $rc = new MHA_Relay_Controller();
    eq('forbidden', envelope($rc->comgate_call(req('POST', [], [], json_encode(['merchant' => 'm', 'secret' => 's', 'path' => '/v1.0/create']))))['error']['code'] ?? null, 'relay refuses a create call before any network use');
    eq('bad_request', envelope($rc->comgate_call(req('POST', [], [], json_encode(['path' => '/v2.0/transfer']))))['error']['code'] ?? null, 'relay requires merchant + secret');

    // Legacy switch.
    delete_option(MHA_Relay_Controller::OPT_DISABLED);
    $eps = ['/matova/v2/health' => 1, '/matova-comgate/v1' => 1, '/matova-comgate/v1/call' => 1, '/matova-comgate/v10/x' => 1, '/matova-hbook/v1/_debug-auth' => 1, '/wp/v2/posts' => 1];
    eq($eps, MHA_Relay_Controller::filter_endpoints($eps), 'nothing switched off ⇒ every route stays');
    $d = envelope($rc->legacy_set(req('PUT', [], [], json_encode(['disabled' => ['matova-comgate/v1']]))));
    ok($d['ok'] && $d['data']['disabled'] === ['matova-comgate/v1'], 'legacy namespace switched off');
    $after = MHA_Relay_Controller::filter_endpoints($eps);
    ok(!isset($after['/matova-comgate/v1']) && !isset($after['/matova-comgate/v1/call']), 'its routes are gone');
    ok(isset($after['/matova/v2/health']) && isset($after['/wp/v2/posts']) && isset($after['/matova-hbook/v1/_debug-auth']) && isset($after['/matova-comgate/v10/x']), 'every other route untouched (prefix-safe)');
    eq('bad_request', envelope($rc->legacy_set(req('PUT', [], [], json_encode(['disabled' => ['matova/v2']]))))['error']['code'] ?? null, 'cannot switch off matova/v2');
    eq('bad_request', envelope($rc->legacy_set(req('PUT', [], [], json_encode(['disabled' => ['wp/v2']]))))['error']['code'] ?? null, 'cannot switch off WordPress core routes');
    eq(['matova-comgate/v1'], MHA_Relay_Controller::disabled_namespaces(), 'a refused request changes nothing');
    update_option(MHA_Relay_Controller::OPT_DISABLED, ['matova/v2', 'matova-hbook/v1']);
    eq(['matova-hbook/v1'], MHA_Relay_Controller::disabled_namespaces(), 'a tampered option can still never disable matova/v2');
    $d = envelope($rc->legacy_set(req('PUT', [], [], json_encode(['disabled' => []]))));
    eq($eps, MHA_Relay_Controller::filter_endpoints($eps), 'switching back on restores every route (reversible)');
    delete_option(MHA_Relay_Controller::OPT_DISABLED);
}

/* ============================================================ INVENTORY LOCK */
area('inventory-lock');
{
    $real = $GLOBALS['wpdb'];
    $mk = function ($getResult) {
        return new class($getResult) {
            public $prefix = 'wp_'; public $calls = []; private $r;
            public function __construct($r) { $this->r = $r; }
            public function prepare($q, ...$a) { return $q . '|' . implode(',', $a); }
            public function suppress_errors($v = true) { return false; }
            public function get_var($sql) {
                $this->calls[] = $sql;
                if (strpos($sql, 'RELEASE_LOCK') !== false) { return '1'; }
                if ($this->r === 'throw') { throw new \RuntimeException('no such function'); }
                return $this->r;
            }
        };
    };

    $GLOBALS['wpdb'] = $db = $mk('1');
    $ran = 0;
    $out = MHA_HBook::with_inventory_lock(function () use (&$ran) { $ran++; return 'done'; });
    eq('done', $out, 'lock acquired → callback result returned');
    eq(1, $ran, 'callback ran exactly once');
    ok(count($db->calls) === 2 && strpos($db->calls[1], 'RELEASE_LOCK') !== false, 'lock released after the write');

    $GLOBALS['wpdb'] = $db = $mk('1');
    try { MHA_HBook::with_inventory_lock(function () { throw new \RuntimeException('boom'); }); } catch (\RuntimeException $e) {}
    ok(count($db->calls) === 2 && strpos($db->calls[1], 'RELEASE_LOCK') !== false, 'lock released even when the write throws');

    $GLOBALS['wpdb'] = $db = $mk('0');
    $ran = 0;
    $out = MHA_HBook::with_inventory_lock(function () use (&$ran) { $ran++; return 'done'; });
    ok(is_wp_error($out) && $out->get_error_code() === 'busy', 'lock held elsewhere → busy error');
    eq(0, $ran, 'nothing is written while another write holds the lock');
    eq(1, count($db->calls), 'no release attempted for a lock we never held');

    foreach ([null, 'throw'] as $mode) {
        $GLOBALS['wpdb'] = $db = $mk($mode);
        $ran = 0;
        $out = MHA_HBook::with_inventory_lock(function () use (&$ran) { $ran++; return 'done'; });
        ok($out === 'done' && $ran === 1, 'database without named locks (' . var_export($mode, true) . ') → behaves as before');
    }
    $GLOBALS['wpdb'] = $real;
}

/* ================================================================= SUMMARY */

delete_option('mha_write_mode');

echo "\n";
echo str_repeat('=', 64) . "\n";
echo "RESULTS BY AREA\n";
echo str_repeat('-', 64) . "\n";
foreach ($GLOBALS['__area_counts'] as $name => $pc) {
    printf("  %-22s pass %-4d fail %-4d\n", $name, $pc[0], $pc[1]);
}
echo str_repeat('-', 64) . "\n";
$total = $GLOBALS['__pass'] + $GLOBALS['__fail'];
printf("  TOTAL assertions: %d   PASS: %d   FAIL: %d\n", $total, $GLOBALS['__pass'], $GLOBALS['__fail']);
echo str_repeat('=', 64) . "\n";

if ($GLOBALS['__fail'] > 0) {
    echo "\nFAILURES:\n";
    foreach ($GLOBALS['__fails'] as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "\nALL TESTS PASSED\n";
exit(0);
