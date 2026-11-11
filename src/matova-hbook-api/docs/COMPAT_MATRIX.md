# Matova HBook API — Per-resort × per-capability compatibility matrix

Cross-references: `docs/CAPABILITIES.md` (endpoint ↔ native method ↔ status),
`docs/CONTRACTS.md` §4 (native method map), §7 (write gate), §9 (per-site diffs).
This document is the **per-site** view: how each v2 capability group lands on each
resort given that resort's HBook version and which native method it actually has.

## Legend

- **supported-live** — native method exists on this site; can reach write-mode
  `live` after the staged rollout. Reads: served now.
- **gated** — native method exists (or read works), but the capability stays
  behind the write-mode gate (`off` → `dry_run` → `live` per `ROLLOUT.md`) and/or
  is untested against a live HBook. Enable per kind, per site.
- **blocked** — cannot run here until a named blocker clears (native method
  absent on this HBook version, or site stack unverified).

Evidence status (updated 2026-11-02): write paths are now **verified end-to-end** —
all mutating ops on real WP/MySQL + HBook 2.0.9 (Chatky Borný, Phase 4) and against
the real HBook **2.2.1** classes via the 151-assertion live harness. See
`VERSION_MATRIX.md` for the definitive per-version capability list.

## Per-site HBook baseline (CONTRACTS §9)

**Rollout stance (Jacob, 2026-11-02):** Sluníčko, Srdíčko and Passage are targeted
as **HBook 2.2.1** — Srdíčko already is; Sluníčko and Passage are being upgraded by
the developer shortly. Build the API as if 2.2.1 is in place on all three. The
plugin feature-detects per site, so 2.2-only capabilities (payments, pricing)
activate automatically at upgrade with no redeploy. Borný stays 2.0.9 (closed;
degraded reference only).

| Resort | HBook (target) | Prefix | Notes |
|---|---|---|---|
| **slunicko** | 2.2.1 (upgrading from 2.0.9) | `ar1w_` | full feature set once upgraded; degrades safely to 503 on payment/quote until then |
| **srdicko** | 2.2.1 (confirmed) | `jbsp_` | `payments_logs`, mark-as-paid, payment links present |
| **passage** | 2.2.1 (upgrading from 2.0.9) | `y9j6_` | already has `hbapi/v1` PATCH + block writes; full set once upgraded |
| **borny** | 2.0.9 (confirmed on-box) | `ar1w_` (separate DB) | closed resort; payment/quote correctly 503 here; used as the degraded reference |

## Matrix

Rows = resorts; columns = v2 capability groups. Cell = status + reason.

### Reads

| Capability | slunicko (2.0.9) | srdicko (2.2.x) | borny (≈2.0.9) | passage (2.0.9) |
|---|---|---|---|---|
| **Reservations read** (`GET /reservations`, `/reservations/{id}`) | supported-live — `$wpdb` on `hb_resa` | supported-live | supported-live | supported-live |
| **Search** (`GET /reservations/search`) | supported-live — `hb_resa`+`hb_customers` | supported-live | supported-live | supported-live |
| **Availability** (`GET /availability`) | supported-live — `get_unavailable_accom_num_per_date()` | supported-live | supported-live | supported-live |
| **Pricing quote** (`GET /pricing/quote`) | supported-live — `HbPriceCalc::get_price()` (read-only) | supported-live | supported-live | supported-live |
| **Events / outbox** (`GET /events`) | supported-live — `mha_events` fed by HBook hooks | supported-live | supported-live | supported-live |
| **Operations / stats** (`/day/{date}`, `/operations/occupancy`, `/stats/monthly`) | supported-live — `$wpdb` on `hb_resa` | supported-live | supported-live | supported-live |
| **Operations / outstanding** (`/operations/outstanding`) | gated-read — paid derived from flat `paid` column (less precise; no `payments_logs`) | supported-live — exact from `payments_logs` | gated-read — flat `paid` fallback | gated-read — flat `paid` fallback |

All reads are feasible on all four sites (reads never depend on 2.2 methods).
"gated-read" above means the read works but its **precision** differs by version,
not that it is blocked.

### Availability — three distinct metrics (all sites, all HBook versions)

`GET /availability` returns, **per accommodation (`by_accom`) and in `totals`**, three
clearly-named metrics plus the existing `occupied` list (kept for back-compat):

| Metric | Meaning | Rule |
|---|---|---|
| `physical_occupied` | Units with a guest physically staying in the window | reservations whose `status ∈ {confirmed, new}` and whose stay overlaps |
| `reserved` | Units tied up operationally | any **non-cancelled** reservation (incl. `waiting_payment`/`pending`/`multi_resa_pending`) ∪ blocks |
| `bookable_free` | Units actually bookable per HBook | `total_units − native-unavailable` — equals HBook's own `get_unavailable_accom_num_per_date()` under the site config |

- **`bookable_free` follows HBook config.** `native-unavailable` excludes
  `cancelled` and `pending` always, and excludes `waiting_payment` **only when
  `get_option('hb_resa_paid_has_confirmation') === 'yes'`**. So on a site with that
  option on, a `waiting_payment` reservation frees inventory and `bookable_free`
  rises; with it off, it ties inventory up. This matches HBook's native rule
  exactly and is the authority for what the booking form would allow. The response
  echoes the live value under `bookable_free_config` (option name, value, and
  `waiting_payment_frees_inventory`). `total_units` is HBook's `accom_quantity`
  postmeta (the native denominator), falling back to the unit-catalogue count.
- **Legacy resort-dashboard occupancy ({confirmed, new}) is preserved ONLY in the
  compat shim.** v2's three metrics supersede the old single `{confirmed,new}`
  occupancy count; a consumer that still needs the exact legacy occupancy semantics
  reads it via `mha_legacy_shim` (the `physical_occupied` metric reproduces the same
  `{confirmed,new}` set for parity).

### Reservation enrichment + PII gating (`GET /reservations`, `/reservations/{id}`)

- **Always-on, non-PII enrichment:** `accommodation_type` (the accommodation NAME
  resolved from `accom_id` via the `hb_accommodation` post / `hb_accom_num_name`),
  `nights` (`check_out − check_in`), `amount_paid` (sum of non-failed
  `payments_logs` amounts — HBook's real paid model, **never** the flat `paid`
  column), `payment_gateway`, `payment_status`.
- **PII is gated, OFF by default.** Customer `first_name`/`last_name`/`email`/
  `phone` and `special_requests` appear only with `?include=guest`; the full
  `payments_logs` array only with `?include=payments`. The default response carries
  `customer_id` only. `read` scope is sufficient (no higher scope required); the
  omission-by-default is the privacy default. The response `meta` records
  `pii_included` and the `include` list.
- **The compat shim is the sanctioned home of the full legacy flat shape (incl.
  inline PII):** `mha_legacy_shim=on` reproduces the exact legacy
  `resort-dashboard/v1` row (`customer_name`/`first_name`/`last_name`/`email`/
  `phone`, `accommodation_type`, `nights`, `amount_paid` [legacy flat `paid`],
  `special_requests`, `payment_gateway`, `admin_comment_source`, `extras`), so
  Lovable/OPS/bridge migrate without breakage.

### Writes (all honor the per-kind write-mode gate — default `off`)

| Capability | Native method | slunicko (2.0.9) | srdicko (2.2.x) | borny (≈2.0.9) | passage (2.0.9) |
|---|---|---|---|---|---|
| **Status write** (`POST /reservations/{id}/status`) | `update_resa_status()`; group `cancel_parent_resa()` | gated — method present on 2.0.9; `off` until rollout | gated → first to `live` | gated — **first rollout site** (closed resort) but HBook version unconfirmed, keep `off` until verified | gated |
| **Notes** (`POST /reservations/{id}/notes`) | `update_resa_comment()` | gated | gated | gated (verify on-box) | gated |
| **Blocks create** (`POST /blocked`) | `add_blocked_accom()` | gated | gated | gated (verify on-box) | gated — Passage already does this via `hbapi/v1`; v2 brings it native |
| **Blocks release** (`DELETE /blocked/{id}`) | delete; refuse if `linked_resa_id>0` | gated | gated | gated (verify on-box) | gated — Passage parity via `hbapi/v1` today |
| **Payments add** (`POST /reservations/{id}/payments`) | `add_resa_payment()` → `payments_logs` | **blocked** — 2.2-only method absent → `write_disabled` (version note) | **supported-live** — `payments_logs`+mark-as-paid present; payments reach `live` here first | **blocked** — ≈2.0.9, method absent | **blocked** — 2.0.9, method absent |
| **Payments refund** (`POST /reservations/{id}/payments` negative) | `delete_resa_payment` / refund path | **blocked** (2.2-only) | **supported-live** (2.2.x) | **blocked** (2.2-only) | **blocked** (2.2-only) |
| **Guests update** (`PATCH /guests/{id}`) | `update_customer*` on `hb_customers.info` | gated | gated | gated (verify on-box) | gated |
| **Create** (`POST /reservations`) | `hb_create_resa()` orchestration + `HbPriceCalc` | **blocked** — gated `off`, complex orchestration, untested; also replaces legacy raw-SQL `/create-hold` | **blocked** — same, untested | **blocked** — intended **verification site** (closed resort) once a safe create path is proven | **blocked** — has `hbapi/v1 create` today; v2 create stays `off`/untested |
| **Modify** (dates/accom) (`PATCH /reservations/{id}`) | `update_resa_dates()` / `update_resa_accom()` | **blocked** — gated `off`, untested; accom change resets uid + re-blocks + must recompute price | **blocked** — same | **blocked** — verify on-box first | **blocked** — Passage has `hbapi/v1 PATCH` today; v2 modify stays `off`/untested until verified on Passage |

## Reading the matrix for rollout

- **Reads everywhere now.** Every resort can serve all v2 reads; only
  `/operations/outstanding` precision degrades on 2.0.9 (flat `paid`).
- **Ops-writes (status/notes/blocks/guests): gated, feasible on all four.** The
  underlying native methods exist on 2.0.9, so these can walk `off → dry_run →
  live` per site per `ROLLOUT.md`. Borny is the first rollout site because it is a
  closed resort — but its HBook version is unconfirmed on-box, so writes stay
  `off` there until the stack is inspected.
- **Payments: `live` on srdicko only.** `add_resa_payment()`/`payments_logs`
  exist only on 2.2.x. slunicko / passage / borny return `write_disabled` with a
  version note until HBook is upgraded. Never write the flat `paid` column.
- **Create / modify: blocked everywhere.** Gated `off` and untested against a live
  HBook; remain blocked until a local HBook env or a Borny verification exists.
  Passage having legacy `hbapi/v1 create`/`PATCH` does **not** unblock v2's
  native-method create/modify — those are separate, unverified paths.

## Blocker inventory (what clears a cell)

| Blocker | Cells it gates | Clears when |
|---|---|---|
| HBook 2.0.9 lacks `payments_logs` payment methods | payments add/refund on slunicko, borny, passage | HBook upgraded to 2.2.x on that site |
| No live HBook in build env | every write cell (test evidence) | writes exercised in `dry_run`/`live` on dev then a resort |
| Write-mode gate default `off` | every write cell | `set-write-mode --kind --mode` per `ROLLOUT.md` gates |
| Borny HBook version unconfirmed on-box | all Borny writes | Borny HBook version verified (reads already fine) |
| Create/modify orchestration untested | create/modify on all four | local HBook env or Borny verification of the create path |
