# Matova HBook API — Legacy → v2 consolidation map

Cross-references: `docs/CONTRACTS.md` §6 (endpoint list), §4 (adapter), §7 (write
gate); `docs/MIGRATION.md` §4 (retirement order); `docs/CAPABILITIES.md` (per-site
readiness). This document maps **every legacy REST route** found in the deployed
mu-plugin source to its `matova/v2` destination, or marks it **keep** (not an API
concern) or **retire** (duplicate / debug / backdoor).

## 0. The single-API end state

One plugin, one namespace `matova/v2`, deployed identically to all four resorts,
per-site config from the registry (CONTRACTS §3/§9). HBook stays source of truth;
all writes go through HBook native methods (CONTRACTS §4) behind the per-kind
write-mode gate (§7). When migration completes, every consumer calls `matova/v2`
with a scoped per-consumer key, and the four legacy namespaces
(`resort-dashboard/v1`, `hbapi/v1`, the `matova-hbook-exec` routes, `ks/v1`) are
unregistered in the order of `MIGRATION.md` §4.

## 1. Legacy surface actually deployed (from source)

Discovered by `register_rest_route` across `slunicko/`, `srdicko/`, `passage/`,
`borny/` mu-plugins:

- **`resort-dashboard/v1`** — the main read API (`hbook-api-<resort>.php`) plus
  status writes (`hbook-api-write-status.php`), payment writes
  (`hbook-api-write-payment.php`), and the exec extension
  (`matova-hbook-exec.php`). Present on **all four** resorts.
- **`hbapi/v1`** — reservation/block mutations
  (`hbook-api-write-mutations.php`). **Passage only.** This is the only namespace
  carrying PATCH + block writes today.
- **`matova-hbook-exec` routes** — the task's "matova-hbook/v1"; in source these
  register **under `resort-dashboard/v1`** (`/health-matova`, `/reservation/{id}`,
  `/create-hold`, `/add-note`) and **re-implement reads/writes the main plugin
  already does** (see §3). All four resorts.
- **`ks/v1`** — Sluníčko-only last-minute widget. **Not in the mu-plugin
  snapshot**; it is a WPCode snippet (5276) serving `GET /wp-json/ks/v1/lastminute`
  in-process. Treated here as an in-process read consumer, not a mu-plugin route.
- **`matova-comgate/v1`** (slunicko) and **`matova-frontend/v1`** (passage) — not
  HBook data APIs; see §4 (keep).
- **`resort-dashboard/v1/diagnostic`** (slunicko + passage) — the burned-key
  backdoor; see §5 (retire).

## 2. Route-by-route map

### `resort-dashboard/v1` — reads (`hbook-api-<resort>.php`)

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `GET /reservations` | **map** | `GET /reservations?from&to&status&updated_since&limit&offset` |
| `GET /reservations-occupancy` | **map** | `GET /operations/occupancy?from&to` |
| `GET /blocked` | **map** | `GET /blocked?from&to` |
| `GET /booking-rules` | **map** | `GET /rules` |
| `GET /availability` | **map** | `GET /availability?from&to[&accom_id]` |
| `GET /availability-bundle` (raw merged for Matova OS) | **map** | `GET /availability` (v2 returns the computed+raw view; the bespoke "bundle" transport is dropped — consumer reads the v2 shape) |
| `GET /pricing` | **map** | `GET /pricing/quote?accom_id&check_in&check_out&adults&children` |
| `GET /customers` | **map** | `GET /guests/{id}` + `GET /reservations/search?q` |
| `GET /accom-units` | **map** | `GET /units` |
| `GET /accom-list` | **retire (duplicate)** | folded into `GET /units` — `accom-units` and `accom-list` are two reads of the same unit catalogue |
| `GET /proxy-pricing` | **retire (duplicate)** | duplicate of `/pricing` → `GET /pricing/quote` |
| `GET /proxy-reservations` | **retire (duplicate)** | duplicate of `/reservations` → `GET /reservations` |
| `GET /extras-config` | **map** | `GET /extras` |
| `GET /health` (public) | **map** | `GET /health` (public) |
| `GET /discover` (off by default) | **retire (debug)** | no v2 equivalent; discovery is `GET /meta` |

### `resort-dashboard/v1` — status writes (`hbook-api-write-status.php`)

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `POST /update-reservation-status` | **map** | `POST /reservations/{id}/status` (confirmed/cancelled; gated) |
| `GET /reservation-status-state` | **map** | `GET /reservations/{id}` (status is a field of the row) |
| `GET /status-write-log` | **retire (duplicate)** | superseded by `GET /events?since` + `mha_audit` |

### `resort-dashboard/v1` — payment writes (`hbook-api-write-payment.php`)

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `POST /update-reservation-paid` | **map** | `POST /reservations/{id}/payments` (appends to `payments_logs` via `add_resa_payment`; never the flat `paid` column) |
| `POST /update-group-paid` | **map** | `POST /reservations/{id}/payments` on the parent/group id |
| `GET /reservation-payment-state` | **map** | `GET /reservations/{id}` + `GET /operations/outstanding` |
| `GET /group-payment-state` | **map** | `GET /reservations/{id}` (parent) |
| `GET /payment-write-log` | **retire (duplicate)** | superseded by `GET /events?since` + `mha_audit` |

### `resort-dashboard/v1` — exec extension (`matova-hbook-exec.php`, the "matova-hbook/v1")

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `GET /health-matova` | **retire (duplicate)** | duplicate liveness probe → `GET /health` / `GET /meta` |
| `GET /reservation/{id}` | **retire (duplicate)** | re-implements a single-reservation read → `GET /reservations/{id}` |
| `POST /add-note` | **map** | `POST /reservations/{id}/notes` (via `update_resa_comment`) |
| `POST /create-hold` | **map (hardened)** | `POST /reservations` (admin, gated). Legacy `/create-hold` does a **direct `$wpdb->insert`** on `hb_resa`/`hb_customers`, deliberately bypassing HBook hooks. v2 forbids raw-SQL writes (CONTRACTS §4): the native-method create path replaces it, so hold-creation stays `off`/gated until the create path is verified (CAPABILITIES: create = BLOCKED). |

### `hbapi/v1` — mutations (`hbook-api-write-mutations.php`, Passage only)

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `POST /create-reservation` | **map** | `POST /reservations` (admin, gated) |
| `PATCH /reservation/{id}` | **map** | `PATCH /reservations/{id}` (admin, gated; dates/accom) |
| `POST /blocked` | **map** | `POST /blocked` (ops-write, gated) |
| `DELETE /blocked/{id}` | **map** | `DELETE /blocked/{id}` (refuses when `linked_resa_id > 0`) |

### `resort-dashboard/v1/create-reservation` (alias/override)

Slunicko registers `/create-reservation` via `hbook-api-write-slunicko.php` and a
priority-99 override in the main file; Passage adds a priority-20 alias reusing
`hbapi_mut_create_reservation`; Srdicko via `hbook-api-write-srdicko.php`.

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `POST /create-reservation` (all variants/aliases) | **map** | single `POST /reservations` (admin, gated). The three per-site handlers + aliases collapse to one native-method create path. |

### `ks/v1` (Sluníčko WPCode snippet 5276)

| Legacy route | Disposition | matova/v2 |
|---|---|---|
| `GET /lastminute` | **keep in-process, or map** | the on-site widget may keep calling HBook in-process (lowest latency, no network hop) **or** repoint to `GET /availability` with a `read` key. Either way it must hold a v2 `read` key or stay in-process before any shared key is retired (SECURITY §3). |

## 3. The duplication, stated explicitly

v2 collapses **four overlapping copies of the same reads**:

- **`/reservations` vs `/proxy-reservations`** — identical reservation lists; the
  `proxy-*` variants were a transport workaround. `proxy-reservations` **retires**.
- **`/pricing` vs `/proxy-pricing`** — same price read; `proxy-pricing` **retires**.
- **Three copies of the accommodation read** — `/accom-units`, `/accom-list`
  (same catalogue) and the unit data embedded in `/availability`; v2 has **one**
  `GET /units`. `/accom-list` **retires**.
- **`matova-hbook-exec` re-implements `resort-dashboard/v1` reads** — its
  `/reservation/{id}` and `/health-matova` duplicate the main plugin's single
  read + health; both **retire**, and its only non-duplicate surface (`/add-note`,
  `/create-hold`) maps onto v2 `notes` / `create`.
- **Write logs** — `/status-write-log` and `/payment-write-log` are per-feature
  audit tails; v2 has one audit (`mha_audit`) + one outbox (`GET /events`), so
  both **retire**.

## 4. Keep — not an API concern

- **`matova-comgate/v1`** (`/outbound-ip`, `/keycheck`, `/call`, Sluníčko) — the
  Comgate payment-gateway proxy. Payment-gateway plumbing, not HBook data. **Keep.**
- **`matova-frontend/v1/router-state`** (Passage) — booking-form / front-end
  router state. **Keep.**
- The **HBook booking form** itself and HBook's own AJAX endpoints — **keep**;
  v2 never fronts the guest booking flow.

## 5. Retire — security

- **`resort-dashboard/v1/diagnostic`** (slunicko + passage, `hbook-diagnostic.php`)
  — the backdoor accepting the burned keys `KSapi2026`/`CBapi2026`/`SRapi2026`
  (`?api_key=`). **Retire / delete** (per memory it was removed from live on
  2026-11-01; the snapshot here still contains the file). See
  `docs/CREDENTIAL_MIGRATION.md` for the grep result and key rotation.

## 6. What retires when

Per `MIGRATION.md` §4, a namespace is unregistered only after every consumer of
it is on v2 and `mha_audit`/access logs show zero traffic for the soak window,
on all four resorts:

1. **`ks/v1`** first (smallest surface; Sluníčko only).
2. **`resort-dashboard/v1`** once OPS bridge + Lovable are on v2 reads. Confirm
   `/diagnostic` is gone and its keys rotated before trusting its logs.
3. The **`matova-hbook-exec`** routes together with (2) — they share the
   namespace; retire when `/add-note` + hold-create traffic is on v2.
4. **`hbapi/v1`** **last** — it is Passage's only PATCH/block path, so it stays
   until v2 block + modify writes are `live` and verified on Passage.

## 7. Tally

- **Distinct legacy route-shapes catalogued:** 36.
- **Map → `matova/v2`:** 24
  (`/reservations`, `/reservations-occupancy`, `/blocked` read, `/booking-rules`,
  `/availability`, `/availability-bundle`, `/pricing`, `/customers`,
  `/accom-units`, `/extras-config`, `/health`, `/update-reservation-status`,
  `/reservation-status-state`, `/update-reservation-paid`, `/update-group-paid`,
  `/reservation-payment-state`, `/group-payment-state`, `/add-note`,
  `/create-hold`, `/create-reservation`, hbapi `create`/`PATCH`/`blocked POST`/
  `blocked DELETE`).
- **Retire (duplicate / debug / backdoor):** 8
  (`/accom-list`, `/proxy-pricing`, `/proxy-reservations`, `/discover`,
  `/status-write-log`, `/payment-write-log`, exec `/health-matova`,
  exec `/reservation/{id}`) — plus the `/diagnostic` backdoor.
- **Keep (not an API concern):** 4 groups
  (`matova-comgate/v1` ×3 as one proxy, `matova-frontend/v1/router-state`, the
  HBook booking form, and the `ks/v1` widget which may stay in-process).

## 8. On the compatibility shim (`includes/compat/class-mha-legacy-shim.php`)

Direct migration is **preferred**: each consumer unwraps the v2 `data` envelope
(one line) and reads HBook-column field names — the mapping in `MIGRATION.md` §2.
A shim that re-emits legacy shapes is debt that has to be removed later and risks
becoming permanent.

The shim is therefore shipped **optional and default-OFF**: it registers nothing
unless option `mha_legacy_shim` = `on`, and even then only one high-traffic read
alias (`GET /matova/v2/compat/resort-dashboard/reservations`) that reshapes the v2
reservations read into the legacy `resort-dashboard/v1` flat-row shape. It uses
`MHA_Auth::require_scope('read')` — **no auth bypass**, no legacy/shared key — and
reads through the same `MHA_HBook::reservations_in_window()` the v2 controller
uses. It exists only so a consumer whose response-parsing cannot be edited in the
same window as its base-URL cutover (e.g. an expensive-to-rewire Make mapping) can
repoint first and update its parser later. Enable it for that one consumer, then
`wp option update mha_legacy_shim off` the moment it parses v2 natively. No write
aliases exist, so the write-mode gate, idempotency and audit are never bypassed.
