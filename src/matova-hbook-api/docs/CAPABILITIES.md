# Matova HBook API — Capability matrix (matova/v2)

Status per capability against `docs/CONTRACTS.md` §4, §6, §7, §9.
Legend: **COMPLETE** = read path or write path specified and unblocked on ≥ one
site; **PARTIAL** = works but constrained (gate/version/site); **BLOCKED** =
cannot run until a named blocker clears.

Build-environment caveat applying to every write row: there is **no live HBook in
the build env**, so all write paths are specified against HBook 2.2.1 source but
**untested**. Writes also honor the per-kind write-mode gate (default `off`).

| Capability | Endpoint | HBook native method | Scope | Status | Blocker / notes |
|---|---|---|---|---|---|
| Reservations — read list | `GET /reservations` | `$wpdb` on `hb_resa` | read | COMPLETE | Date/status/`updated_since` filters, paginated. |
| Reservations — read one | `GET /reservations/{id}` | `$wpdb` on `hb_resa` | read | COMPLETE | |
| Reservations — search | `GET /reservations/search?q` | `$wpdb` on `hb_resa`+`hb_customers` | read | COMPLETE | Name/email/alphanum_id/id. |
| Reservations — status (confirm/cancel) | `POST /reservations/{id}/status` | `update_resa_status()`; group → `cancel_parent_resa()` | ops-write | PARTIAL | Gate default `off`; only `confirmed`/`cancelled` writable; untested vs live HBook. |
| Reservations — create | `POST /reservations` | `hb_create_resa()` orchestration + `HbPriceCalc::get_price()` | admin | BLOCKED | Gated `off` by default (complex orchestration); untested until a local HBook env or Borny verification. Do not reimplement price. |
| Reservations — modify (dates/accom) | `PATCH /reservations/{id}` | `update_resa_dates()` / `update_resa_accom()` | admin | BLOCKED | Gated `off`; accom change resets uid + re-blocks and must recompute price via `HbPriceCalc`; untested until local HBook/Borny verification. |
| Reservations — notes | `POST /reservations/{id}/notes` | `update_resa_comment()` | ops-write | PARTIAL | Gate default `off`; untested vs live HBook. |
| Reservations — history | (included in events/audit) | `hb_resa_logs` read; `mha_events` outbox | read | PARTIAL | No dedicated `/reservations/{id}/history` route in §6; history is surfaced via `GET /events?since` (outbox) and `mha_audit`. Dedicated per-reservation log route not yet specified. |
| Availability — read | `GET /availability` | `get_unavailable_accom_num_per_date()` / `get_available_accom_occupancy_quantity_per_dates()` | read | COMPLETE | |
| Blocks — create | `POST /blocked` | `add_blocked_accom()` (fires `hb_blocked_accom_updated`) | ops-write | PARTIAL | Gate default `off`; untested vs live HBook. |
| Blocks — release | `DELETE /blocked/{id}` | delete block | ops-write | PARTIAL | Gate default `off`; **refuses when `linked_resa_id > 0`** (reservation-owned); untested vs live HBook. |
| Pricing — quote | `GET /pricing/quote` | `HbPriceCalc::get_price()` | read | COMPLETE | Read-only use of the price engine. |
| Guests — read | `GET /guests/{id}` | `$wpdb` on `hb_customers` | read | COMPLETE | `info` JSON flattened. |
| Guests — update | `PATCH /guests/{id}` | `update_customer*` on `hb_customers.info` | ops-write | PARTIAL | Gate default `off`; untested vs live HBook. |
| Payments — add | `POST /reservations/{id}/payments` | `HbDataBaseActions::add_resa_payment()` / `HbUtils::update_resa_payments()` → `payments_logs` | ops-write | PARTIAL | **Must use `add_resa_payment`, never the flat `paid` column.** `write_disabled` on 2.0.9 sites (slunicko/passage) — method absent; viable on srdicko 2.2.x. Gate default `off`. |
| Payments — refund | `POST /reservations/{id}/payments` (negative amount) | `delete_resa_payment` / refund path | ops-write | PARTIAL | Same 2.2.x dependency and gate as add. |
| Events — outbox | `GET /events?since` | `mha_events`, fed by HBook hooks | read | COMPLETE | Pull sync cursor; periodic full reconcile is the fallback. |
| Operations — day summary | `GET /day/{date}` | `$wpdb` on `hb_resa` | read | COMPLETE | Arrivals + departures + in-house. |
| Operations — occupancy | `GET /operations/occupancy` | `$wpdb` on `hb_resa` + units | read | COMPLETE | |
| Operations — outstanding | `GET /operations/outstanding` | `$wpdb` on `hb_resa` (+`payments_logs`) | read | PARTIAL | Amount-paid derivation is exact only where `payments_logs` exists (2.2.x); on 2.0.9 it falls back to the flat `paid` column (read-only), which can be less precise. |
| Operations — monthly stats | `GET /stats/monthly?year` | `$wpdb` on `hb_resa` | read | COMPLETE | |
| Units — catalogue | `GET /units` | `hb_accom_num_name` + accommodation postmeta | read | COMPLETE | |
| Rules / rates / seasons | `GET /rules` | `hb_booking_rules`, `hb_rates*`, `hb_seasons*` | read | COMPLETE | |
| Extras / options / fees | `GET /extras` | `hb_options`(+`_choices`), `hb_fees` | read | COMPLETE | |
| Meta / health | `GET /meta`, `GET /health` | registry / probe | read | COMPLETE | `/health` is public (no auth). |

## Cross-cutting blockers (summary)
- **No live HBook in the build env** → every write path is unverified end-to-end.
- **Write-mode gate `off` by default** → all writes are no-ops until flipped
  `dry_run`/`live` per kind, per site.
- **Create/modify reservation `off` + untested** → remain BLOCKED pending a local
  HBook environment or Borny verification.
- **Payment writeback must append to `payments_logs`** via `add_resa_payment`,
  never the flat `paid` column; the 2.2.x method is absent on slunicko/passage
  (2.0.9) → `write_disabled` there.
- **Borny HBook version unknown** → treat all Borny writes as BLOCKED until its
  stack is retrieved.

---

## Phase 4 — live verification on Chatky Borný (HBook 2.0.9, WP 5.9.10, PHP 7.4.33)

Verified against the real WP/MySQL + native HBook on www.chatkyborny.cz, using
disposable far-future records with side-effect isolation ON (wp_mail + all
non-self outbound HTTP hard-blocked: proven blocking Comgate + Booking.com).
All disposable records removed afterward; write modes restored to `off`.

### Native method surface on 2.0.9 (reflected from the live HBook source)
| method | present | used by |
|---|---|---|
| create_resa | ✅ | POST /reservations |
| update_resa_status | ✅ | POST /reservations/{id}/status |
| update_resa_comment | ✅ | POST /reservations/{id}/notes |
| add_blocked_accom / delete_blocked_accom | ✅ | /blocked |
| automatic_block_accom | ✅ | reservation create orchestration |
| update_customer | ✅ | PATCH /guests/{id} |
| update_resa_price / update_resa_dates | ✅ | (modify; dry-run only so far) |
| add_resa_payment / update_resa_payments | ❌ | payments → `write_disabled` (503) |
| HbPriceCalc::get_price | ❌ (class absent) | quote → `unsupported` (503) |

### Write operations PASSED live on real WP/MySQL
- **Reservation create** — `create_resa` inserts row + `automatic_block_accom`; hooks fire.
- **Status change** (confirm / cancel) — `update_resa_status`.
- **Note / admin comment** — `update_resa_comment`.
- **Block create** — `add_blocked_accom`; now returns the inserted `hb_accom_blocked` id.
- **Block delete** — `delete_blocked_accom` (looked up by row id → deleted by date+accom).
- **Invalid input rejected** — unknown status → `400 bad_request`; missing `Idempotency-Key` → `400`.
- **Scope enforcement** — read-scoped key on a write → `403 forbidden`.
- **Idempotency + audit** — both own-tables populate on every live write (verified row counts).
- **Outbox events** — emit on HBook hooks and read back via `/events` with cursors (see fix below).
- **Guest update** — full path (auth→validate→read→merge→gate) verified live in dry-run; native
  `update_customer` present and is the same native-write pattern proven above. Not exercised with a
  live mutation to avoid touching a historical customer record (no disposable customer exists on 2.0.9).

### Version-limited on 2.0.9 (return structured 503, no data mutation)
- **Payments** — no `payments_logs` writeback method → `write_disabled`.
- **Pricing quote** — `HbPriceCalc` absent → `unsupported`. No safe native server-side quote on 2.0.9.

### Bugs found and FIXED this phase
1. **Outbox events never persisted on live.** The `mha_events` table was never created
   because its DDL used `cursor` (a reserved word) as a column name → that one `dbDelta`
   silently failed while idempotency/audit succeeded. Renamed column to `seq` (external
   API field stays `cursor` via `seq AS \`cursor\``), bumped `SCHEMA_VERSION`.
2. **Schema only created on activation.** Production deploys are zip-overwrite of an
   already-active plugin, which never fires `register_activation_hook` → tables absent,
   every store insert a silent no-op. Added version-gated `ensure_installed()` — run on
   `admin_init` only (NOT front-end `plugins_loaded`: loading `wp-admin/includes/upgrade.php`
   + `dbDelta` on front-end requests wedges PHP-FPM on this hosting), and `install()` now
   skips when all tables already exist.
3. **`create_block` returned no id.** Now returns the new `hb_accom_blocked` id so consumers
   can `DELETE /blocked/{id}`.

### Hosting constraints observed (relevant to rollout)
- **OPcache is off/restricted** on Borný's PHP 7.4 (`opcache_get_*` null, `opcache_reset()` → false).
- **REST GETs intermittently 404 (`rest_no_route`) under bursty single-client load** — the host/
  Wordfence sheds `/wp-json/*` for several seconds, then recovers. Steady, spaced traffic is 100%
  stable. Consumers must use retry-with-backoff; load tests will trip it. Not an API defect.
- `_test/*` endpoints self-gate behind `mha_test_isolation=on`; with it off they 404 (production-safe).
