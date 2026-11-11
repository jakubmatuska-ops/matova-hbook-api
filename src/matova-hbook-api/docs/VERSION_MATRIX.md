# HBook capability matrix — 2.0.9 vs 2.2.1 (definitive)

Source of evidence:
- **2.0.9** — reflected live from Chatky Borný's on-box HBook source (`/_test/hbook-caps`, Phase 4) + behavioural tests on real WP/MySQL.
- **2.2.1** — bundled source (`hbook-plugins/hbook`, confirmed on Srdíčko) + 151-assertion live harness running the real 2.2.1 classes.

**Rollout stance (per Jacob, 2026-11-02):** Sluníčko, Srdíčko, Passage are targeted as **2.2.1** (developer upgrading Sluníčko/Passage shortly; Srdíčko already 2.2.1). The plugin **feature-detects per site at runtime** (`method_exists` / `class_exists`), so every capability below activates automatically the moment a site is on 2.2.1 — no code change or redeploy needed at upgrade time. Borný stays 2.0.9 and is the degraded reference only.

## Native surface

| Native method / class | 2.0.9 (Borný) | 2.2.1 (rollout) | v2 consumer |
|---|---|---|---|
| `create_resa` | ✅ | ✅ | POST /reservations |
| `automatic_block_accom` | ✅ | ✅ | reservation-create orchestration |
| `update_resa_status` | ✅ | ✅ | POST /reservations/{id}/status |
| `update_resa_comment` | ✅ | ✅ | POST /reservations/{id}/notes |
| `update_resa_price` | ✅ | ✅ | PATCH /reservations/{id} (modify) |
| `update_resa_dates` | ✅ | ✅ | PATCH /reservations/{id} (modify) |
| `add_blocked_accom` / `delete_blocked_accom` | ✅ | ✅ | /blocked |
| `update_customer` | ✅ | ✅ | PATCH /guests/{id} |
| `add_resa_payment` / `update_resa_payments` | ❌ absent | ✅ | POST /reservations/{id}/payments |
| `payments_logs` column | ❌ absent | ✅ | payment read + write |
| `HbPriceCalc::get_price` (class) | ❌ absent | ✅ | GET /pricing/quote |

## Capability status per version

| v2 capability | 2.0.9 | 2.2.1 | Notes |
|---|---|---|---|
| Reservations read/search | ✅ | ✅ | `$wpdb` over `hb_*`; identical |
| Availability / blocked / rules read | ✅ | ✅ | identical |
| Reservation create | ✅ | ✅ | native `create_resa` + auto-block; hooks fire on both |
| Status change (confirm/cancel/…) | ✅ | ✅ | identical |
| Notes / admin comment | ✅ | ✅ | identical |
| Block create/delete | ✅ | ✅ | create returns new `hb_accom_blocked` id on both |
| Guest update | ✅ | ✅ | read-merge-write of `info` JSON; preserves other fields |
| Reservation modify — price/dates | ✅ | ✅ | `update_resa_price` / `update_resa_dates` present on both |
| **Payments (append to `payments_logs`)** | ❌ **unavailable** → `503 write_disabled` | ✅ **supported** | 2.0.9 has no payment-log method; flat `paid` is never written. Verified on 2.2.1: appends to JSON log, fires `hb_reservation_updated('paid',id)`, never touches flat column. |
| **Pricing quote** | ❌ **unavailable** → `503 unsupported` | ✅ **supported** | 2.0.9 has no `HbPriceCalc`; no safe native server-side quote exists there. Verified on 2.2.1: real engine totals (e.g. 4×1000 = 4000). |
| Idempotency / audit / outbox events | ✅ | ✅ | plugin's own tables; version-independent |

## Degraded-mode behaviour on 2.0.9 (unchanged, documented)

- Payment and quote return a **structured 503** (`write_disabled` / `unsupported`) with a clear message — never a fake success, never a guessed price, never a flat-`paid` write. Consumers branch on the envelope.
- Everything else is at full parity with 2.2.1.

## Safe compatibility improvements considered (no invented logic)

- **Pricing on 2.0.9:** investigated — the only native price path is HBook's front-end AJAX calc, which is request/nonce-bound and not a safe server-side quote. **Not exposed.** Correct behaviour is the 503 until the site is on 2.2.1. No pricing invented.
- **Payments on 2.0.9:** no `payments_logs` structure exists to append to; writing the flat `paid` column would desync HBook's own accounting. **Not done.** 503 until 2.2.1. No payment logic invented.
- **Net:** because the three rollout sites move to 2.2.1, both gaps close by upgrade, not by code. The degraded paths remain only for Borný and as a safety net if an upgrade slips.
