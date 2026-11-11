# Matova HBook API — v2 contracts (build reference)

Single WordPress plugin `matova-hbook-api`, one versioned namespace **`matova/v2`**, deployed identically to all four resorts with per-site config. HBook stays the source of truth. Legacy `resort-dashboard/v1`, `hbapi/v1`, `matova-hbook/v1`, `ks/v1` stay untouched during migration; v2 is additive. Target PHP 7.4+ (sites run 7.4/8.x), WordPress 5.9+, HBook 2.0.9–2.2.1.

## 1. Response envelope (every v2 route)
```json
{ "ok": true,  "data": <payload>, "meta": { "resort": "srdicko", "as_of": "2026-11-01T13:00:00+00:00", "api": "matova/v2", "request_id": "uuid" } }
{ "ok": false, "error": { "code": "forbidden|bad_request|not_found|conflict|rate_limited|upstream_error|write_disabled", "message": "human text", "details": {} }, "meta": {…} }
```
HTTP status mirrors the error class (401/400/404/409/429/502/503). `request_id` is generated per request and written to the audit log. Never leak PHP notices — a fatal is caught and returned as `upstream_error`.

## 2. Auth — backend-to-backend, no custom crypto
- Transport: HTTP `Authorization: Bearer <key>` **only**. Query-string keys are rejected (no `?api_key=`). TLS assumed.
- Keys are **per consumer × per resort × scope**. Stored server-side as salted SHA-256 **hashes** in option `mha_keys` (raw key never stored). Record: `{ id, label, consumer, scope, hash, created, last_used, active }`. Compare with `hash_equals` (constant-time) by iterating active records; reject if none match.
- Scopes: `read` (all GET), `ops-write` (status, note, block, payment, guest update), `admin` (everything incl. create/modify reservation). A write route requires its scope; a read key on a write route → 403 `forbidden`.
- Rotation/revocation: multiple active keys per consumer allowed (overlap window); setting `active:false` revokes immediately. A WP-CLI/admin helper issues a key (returns raw once, stores hash).
- Optional: accept a WordPress Application Password (manage_options) mapped to `admin` scope for human/admin tooling.
- Rate limit: per key id, token-bucket in a transient, default 120/min (config `MHA_RATE_PER_MIN`), `429` + `Retry-After` on exceed. 0 disables.
- Every authenticated call → one `mha_audit` row (see §5).

## 3. Resort registry (config)
Per-site config resolved at runtime (constants in wp-config or option `mha_config`), with safe auto-detection by `home_url()` host:
`{ resort_id, hb_prefix (e.g. ar1w_/jbsp_/y9j6_), timezone (Europe/Prague), vat_payer (bool), season: {open, close}, currency (CZK), units_total }`. Adding a resort = one registry row. `resort_id` ∈ {slunicko, srdicko, borny, passage}.

## 4. HBook adapter (the ONLY way v2 touches HBook)
Reads: query the `{prefix}hb_*` tables (see §8) with `$wpdb->prepare`. Writes: call HBook's **native** methods on the live instances, never raw SQL, so hooks/price/blocks/logs stay consistent. Resolve HBook objects via the globals/singletons HBook sets up (`$GLOBALS` / the `HbDataBaseActions`, `HbPriceCalc`, `HbUtils` instances created in `hbook.php`); if HBook or a method is unavailable, the operation returns `write_disabled` with a documented blocker — never a raw-SQL fallback.

Native method map (from HBook 2.2.1 source, `database-actions/database-actions.php` unless noted):
- create reservation → the orchestration in `HbFrontEndAjaxActions::hb_create_resa()` (`front-end/front-end-ajax-actions.php:81`); do NOT reimplement price — reuse `HbPriceCalc::get_price()`. **Gated OFF by default (complex).**
- status confirm/cancel → `update_resa_status($id,$status)` (fires hooks; cancel deletes linked blocks). Group → `cancel_parent_resa($id)`.
- note → `update_resa_comment($id,$text)`.
- dates → `update_resa_dates(...)`; accom → `update_resa_accom($id,$accom_id,$accom_num)` (resets uid, re-blocks). **Gated; must recompute price via HbPriceCalc.**
- block create/release → `add_blocked_accom(...)` / delete (refuse if `linked_resa_id>0`); fires `hb_blocked_accom_updated`.
- payment → `HbUtils::update_resa_payments()` / `HbDataBaseActions::add_resa_payment($resa_id,$is_parent,$payment)` — appends to `payments_logs` (HBook's real model). **Never** write the flat `paid` column. Refund → `delete_resa_payment`/refund path.
- guest update → `update_customer*` on `hb_customers.info` JSON.
- price quote → `HbPriceCalc::get_price($accom_id,$check_in,$check_out,$adults,$children,$breakdown)`.
- availability → `get_unavailable_accom_num_per_date()` / `get_available_accom_occupancy_quantity_per_dates()`.

## 5. Idempotency, audit, outbox (own tables, created on activation via dbDelta)
- `mha_idempotency`: `id, key_hash UNIQUE, route, resort_id, request_sha, response_json, http_status, created`. Every mutation requires header `Idempotency-Key`; a replay returns the stored response verbatim; a same-key different-body → 409 `conflict`.
- `mha_audit`: `id, created, request_id, key_id, consumer, scope, resort_id, route, method, http_status, target_type, target_id, before_json, after_json, ip`. Writes capture before/after (read target before calling native method, read after).
- `mha_events` (outbox): `id, created, type, resort_id, entity, entity_id, payload_json, cursor`. Populated by subscribing to HBook hooks: `hb_create_reservation`, `hb_reservations_updated`, `hb_reservation_updated`, `hb_parent_reservation_updated`, `hb_blocked_accom_updated`, `hb_parent_reservation_cancelled`. `GET /events?since=<id>` returns ordered rows for pull sync; periodic full reconcile is the fallback.

## 6. Endpoints (matova/v2)
Reads (scope read): `GET /meta`, `GET /health` (public), `GET /reservations?from&to&status&updated_since&limit&offset`, `GET /reservations/{id}`, `GET /reservations/search?q`, `GET /guests/{id}`, `GET /availability?from&to[&accom_id]`, `GET /units`, `GET /blocked?from&to`, `GET /rules`, `GET /pricing/quote?accom_id&check_in&check_out&adults&children`, `GET /extras`, `GET /day/{date}` (arrivals+departures+in-house), `GET /operations/occupancy?from&to`, `GET /operations/outstanding`, `GET /stats/monthly?year`, `GET /events?since`.
Writes: `POST /reservations/{id}/status`, `POST /reservations/{id}/notes`, `PATCH /guests/{id}`, `POST /blocked`, `DELETE /blocked/{id}`, `POST /reservations/{id}/payments`, `POST /reservations` (admin, gated), `PATCH /reservations/{id}` (admin, gated). All writes: scope-checked, `Idempotency-Key` required, native-method only, audited, honor write-mode gate.

## 7. Write-mode gate
Per-kind config `mha_write_mode[kind]` ∈ `off` (default) | `dry_run` (validate + audit, no native call) | `live`. Production stays `off` until approved. `dry_run` returns the computed effect (`would_*`) without mutating. No env/DB override of mode other than this config. Create/modify-reservation default `off` and are documented as untested-against-HBook until a local HBook env or Borny verification exists.

## 8. HBook 2.2.1 schema (confirmed from source)
Prefix `{wpdb->prefix}hb_`. Accommodations are `hb_accommodation` **posts** (accom_id = post ID; quantity/occupancy/prep-time/linked-accom in postmeta), not a table.
- `hb_resa` (single/child): id, check_in, check_out, accom_id, accom_num, adults, children, accom_price, discount(JSON), price, previous_price, deposit, paid, payment_gateway, currency, customer_id, status, options(JSON), fees(JSON), additional_info, admin_comment, lang, coupon, coupon_value, amount_to_pay, payment_type, payment_info, payment_token, payment_status, payment_status_reason, payment_delayed, payment_failed, payments_logs(JSON), received_on, updated_on, uid, alphanum_id, invoice_counter, nb_emails_sent, origin, origin_url, synchro_id, booking_form_num, parent_id, custom_info(JSON).
- `hb_parents_resa` (group): money/payment columns as above, no check_in/out/accom/status/parent_id.
- `hb_customers`: id, email, info(JSON: first_name,last_name,phone,street,city,zip,country,...), payment_id, nb_resa.
- `hb_accom_blocked`: id, accom_id, accom_all_ids, accom_num, accom_all_num, from_date, to_date, linked_resa_id, is_prepa_time, comment, uid.
- `hb_accom_num_name`: accom_id, accom_num, num_name (unit catalogue; PK accom_id+accom_num).
- `hb_resa_logs`: id, resa_id, is_parent, status, event, previous_status, msg, logged_on.
- rates/seasons/rules: `hb_rates`(id,type∈accom|extra_adults|extra_children, all_accom, all_seasons, amount, nights) + `hb_rates_accom/_seasons/_rules`; `hb_seasons`(id,name,priority∈high|''|low) + `hb_seasons_dates`(season_id,start_date,end_date,days); `hb_booking_rules`(type,name,check_in_days,check_out_days,minimum_stay,maximum_stay,...).
- `hb_coupons`, `hb_fees`, `hb_options`(+_choices), `hb_ical`, `hb_later_payments`, `hb_email_logs`.
Status set: `pending, new, confirmed, waiting_payment, cancelled, multi_resa_pending`. Writable via API: `confirmed, cancelled`. Availability frees status ∈ {cancelled, pending} (+waiting_payment when `hb_resa_paid_has_confirmation=yes`).

## 9. Per-site differences to honor
- Prefixes: slunicko `ar1w_`, borny `ar1w_` (separate DB), srdicko `jbsp_`, passage `y9j6_`.
- HBook: srdicko on 2.2.x (payments_logs, mark-as-paid, payment links); slunicko/passage on 2.0.9 — adapter must feature-detect method existence before calling (e.g. `method_exists`) and fall back to `write_disabled` with a version note rather than assuming 2.2 APIs.
- Only Passage currently has PATCH/block writes (hbapi/v1); v2 brings them everywhere via native methods.
- borny: old-stack like slunicko; its exact mu-plugins not yet retrieved.

## 10. Tests
Stub WP (`$wpdb`, `add_action/apply_filters`, `WP_REST_Request/Response`, `WP_Error`, `get_option`, transients) + a SQLite DB seeded with fixtures mirroring the §8 tables. Cover: auth (constant-time match, scope enforcement, query-string key rejected, unknown/revoked key rejected, rate limit), envelope shape, idempotency (replay returns same, conflicting body → 409), request validation, read controllers' SQL/shaping against fixtures, write controllers in `dry_run` (native method NOT called, audit written). Mutations run only against the SQLite fixture, never live.
