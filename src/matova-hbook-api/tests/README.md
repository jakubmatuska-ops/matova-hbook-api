# matova-hbook-api — automated test suite

Local, WordPress-free tests for the `matova-hbook-api` plugin. A lightweight WP
shim loads the real plugin code unmodified and runs it against an in-memory
**SQLite** `$wpdb`. Everything lives under `tests/`; no plugin file is touched.

## Run

```bash
php tests/run.php
```

Exit code `0` = all passed, `1` = at least one failure (failures print inline and
in a trailing list). Tested on PHP 8.3 (plugin targets 7.4+).

No external framework is required. (`composer require --dev phpunit/phpunit` is
allowed but unused — a hand-rolled runner keeps it dependency-free.)

## Files

- `bootstrap.php` — the WP shim: `ABSPATH`/plugin constants; stubs for
  `add_action`/`add_filter`/`do_action`/`apply_filters` (recorded + dispatched),
  `register_rest_route`/`register_activation_hook` (recorded), options,
  transients (TTL-aware), `wp_generate_uuid4`, `current_time`,
  `current_user_can` (toggle via `$GLOBALS['__caps']`), `sanitize_text_field`,
  `wp_json_encode`, `__()`, `esc_*`, `is_email`, etc.; classes `WP_Error`,
  `WP_REST_Request`, `WP_REST_Response`; a `$wpdb` backed by `pdo_sqlite`
  (`prepare` inlines `%s/%d/%f` with PDO quoting; `get_results/get_row/get_var/
  query/insert/update`; MySQL→SQLite gaps handled: backtick identifiers,
  `INSERT IGNORE`→`INSERT OR IGNORE`, ISO-date string comparison, `CAST AS CHAR`
  + `LIKE`, `MAX()`); a `dbDelta()` that translates the plugin's `CREATE TABLE`
  DDL to SQLite so `MHA_Store::install()` runs for real; and an `HbPriceCalc`
  price stub (HBook db/utils/ajax natives are intentionally absent so live
  writes resolve to `write_disabled`).
- `fixtures/seed.php` — SQLite seeder mirroring CONTRACTS §8 for one resort
  (prefix `wp_`): `wp_hb_resa`, `wp_hb_parents_resa`, `wp_hb_customers`,
  `wp_hb_accom_blocked`, `wp_hb_accom_num_name`, `wp_hb_resa_logs` (+
  `booking_rules`/`options`/`options_choices`). 8 reservations across statuses,
  a group parent (id 100) + 2 children, 2 blocks (one linked), 4 units, 7
  customers with JSON `info` and `payments_logs` JSON. All PII is invented.
- `run.php` — the assertions.
- `.wproot/` — throwaway dir so `require_once ABSPATH.'wp-admin/includes/upgrade.php'`
  resolves (auto-created by the bootstrap).

## Coverage (151 assertions)

- **auth** (23): valid issued key authorizes (issue→hash→authorize round-trip);
  unknown / short / revoked key → 401; read key on an ops-write route → 403;
  `?api_key=` and `?key=` rejected → 403; scope ladder (admin satisfies
  ops-write + read); **constant-time** — a matching record listed *after* a
  non-matching one still authorizes (no early-return); per-key rate limit → 429
  with `retry_after` after the configured count.
- **envelope** (34): `ok()`/`err()` shapes and meta; every error class → its HTTP
  status; `rate_limited()` sets `Retry-After`.
- **idempotency/store** (14): begin→finish→replay returns the stored response
  verbatim; same key + different `request_sha` → conflict; `events_since`
  ordered + payload decode; `last_event_id`; HBook hook → outbox event; audit
  insert.
- **read-controllers** (56): reservations list (window, status filter,
  pagination, bad-date validation); reservation by id incl. a group child +
  parent fetch; not-found; search by customer name; availability (occupied =
  active resas, cancelled/pending excluded; block-only window); units; blocked;
  pricing/quote (native price shaped + validation); `/day` arrivals/departures/
  in-house partition; `/operations/occupancy`; `/operations/outstanding`
  (price − Σ payments_logs); `/stats/monthly`; `/meta` (backtick COUNT,
  write-modes); `/events`; guest read.
- **writes** (24): missing `Idempotency-Key` on a mutation → bad_request; write
  mode `off` → `write_disabled` (503) with an audit row still written and the
  reservation unchanged; create (admin, gated) off → write_disabled; `dry_run`
  → `would_*` effect with mode=`dry_run`, audit row written, **no mutation** and
  **no native call**; dry_run block create; idempotent replay via the full
  `mutate()` path (replay header + identical body); same key + different body →
  409; guest PATCH with no editable fields → bad_request; guest/payment dry_run
  → would; payment without amount → bad_request.

## Not covered (and why)

- **Live write mode** (actual `update_resa_status`, `add_resa_payment`, etc.):
  requires a real HBook instance. By design the HBook db/utils/ajax natives are
  absent, so these resolve to `write_disabled`; only `off`/`dry_run` are tested,
  matching CONTRACTS §10 ("Mutations run only against the SQLite fixture").
- **`POST /reservations` / `PATCH /reservations/{id}` native paths**: gated OFF
  and documented as untested-against-HBook (CONTRACTS §4/§7); only the gated-off
  response is tested.
- **`accommodations()` postmeta enrichment**: depends on `get_posts`/postmeta
  (WP posts), stubbed empty; the unit catalogue (`hb_accom_num_name`) is tested.
