# Matova HBook API — Staged rollout

Cross-references: `docs/DEPLOY_ROLLBACK.md` (SFTP deploy mechanics, smoke tests,
rollback — **not repeated here**), `docs/COMPAT_MATRIX.md` (per-site feasibility),
`docs/CREDENTIAL_MIGRATION.md` (keys), `docs/CONTRACTS.md` §7 (write gate). This is
the **sequence and the gates**: which resort first, which capability next, and the
go/no-go + rollback at each step.

**Control surface (no CLI or snippets).** Write modes and keys are managed from
**wp-admin → Settings → Matova API** (`MHA_Admin`, manage_options, nonce-checked):
per-kind toggles (`off`/`dry_run`/`live`), a one-click **kill switch** (all→off),
and issue/revoke keys. `wp mha set-write-mode …` is equivalent where WP-CLI exists,
but the admin page is the supported path on hosting without SSH/WP-CLI (WEDOS). No
production file-writer snippet is used — install is a one-time upload
(INSTALL_RUNBOOK), everything else is wp-admin.

**Version stance (2026-11-02):** Sluníčko, Srdíčko, Passage target HBook **2.2.1**
(Srdíčko already; the other two upgrading shortly). The plugin feature-detects per
site, so payments + pricing activate automatically at upgrade — no redeploy. Borný
(closed, 2.0.9) is the degraded reference; its write-test stage is **complete
(Phase 4)**.

## Principles

- **Additive, so rollback is cheap.** Legacy never stops serving; deactivating the
  v2 plugin removes all `matova/v2` routes and legacy keeps answering
  (DEPLOY_ROLLBACK §5). Every stage's rollback is "flip the kind back to its prior
  mode" or, worst case, "deactivate the plugin".
- **Borný first.** Chatky Borný is a **closed resort** — no live guests, no
  payment traffic — so a mistake there cannot harm a real booking. It is the
  rollout pathfinder for write modes. (Caveat: Borný's HBook version is
  unconfirmed on-box; verify it before enabling any write — COMPAT_MATRIX.)
- **Reads before writes; one write-kind at a time; never bulk-flip to `live`.**
- **Prod stays read-only until approved.** Dev (`dev.kempslunicko.cz`) exercises
  write modes first (per project rule: dev-only until Jacob approves a deploy).

## Stage 0 — Dev shakedown (before any resort)

Deploy to `dev.kempslunicko.cz` by SFTP (DEPLOY_ROLLBACK §1), write-mode all
`off`. Run the §2 read smoke tests there, then exercise each write kind
`off → dry_run → live` against dev's HBook. Gate to leave Stage 0: read envelopes
correct, `mha_audit` writing, idempotency replay + `409` on changed body verified,
and at least one `dry_run` write returning `would_*` without a state change.

## Stage 1 — Borný: deploy + reads

1. **Deploy plugin, write-mode = off.** SFTP the tree to Borný,
   `wp-content/plugins/matova-hbook-api/`, activate (creates `mha_*` tables).
   Confirm every kind is `off`: `wp mha show-config` (Write modes table all `off`).
2. **Issue read keys** for the consumers that serve Borný
   (CREDENTIAL_MIGRATION §2): `wp mha issue-key --consumer=… --scope=read
   --resort=borny`.
3. **Smoke reads** (DEPLOY_ROLLBACK §2): `GET /health` (public, `hbook_available`),
   `GET /meta` (prefix `ar1w_`, resort `borny`), `GET /units`,
   `GET /availability?from&to`, `GET /day/{date}`, `GET /reservations?from&to`.
   Negative checks: query-string key → `403`, short/unknown key → `401`, `read`
   key on a write route → `403`.

- **Go/no-go:** all reads return the correct envelope and match legacy output for
  the soak sample; `mha_audit` rows written with matching `request_id`; negatives
  behave. **No-go →** investigate (suspect stale opcache per DEPLOY_ROLLBACK §4
  before any browser workaround); do not proceed to writes.
- **Rollback:** deactivate the plugin (routes vanish, legacy unaffected).

## Stage 2 — Borný: verify HBook version, then enable writes per kind

Precondition: confirm Borný's HBook version on-box. If it is **not** 2.2.x, treat
payments as `write_disabled` (leave `off`) and create/modify as blocked.

For **each** ops-write kind in this order — **status → note → block → guest** —
do the `dry_run` then `live` gate before moving to the next kind:

1. `wp mha set-write-mode --kind=<k> --mode=dry_run`. Exercise the write; verify it
   returns the computed `would_*` effect, writes an `mha_audit` row, and the native
   HBook method is **not** called (no state change). Confirm `Idempotency-Key`
   replay returns the stored response and a changed body → `409`.
   - **Go/no-go:** `would_*` correct, audit written, no state change. **No-go →**
     set the kind back to `off`; fix; retry.
2. `wp mha set-write-mode --kind=<k> --mode=live`. Run **one** real mutation on a
   throwaway Borný reservation/block; verify in HBook that it actually changed and
   that `mha_audit` has a clean before/after pair.
   - **Go/no-go:** real effect verified + audit pair clean. **No-go →**
     `set-write-mode --kind=<k> --mode=off` (immediate; next request is gated off),
     then investigate.
3. **Soak** the kind in `live` on Borný for the agreed window before enabling the
   next kind. Leave the other kinds at their current mode.

**Payments on Borný:** only if Borný is confirmed 2.2.x. Otherwise leave
`payment` `off` (the adapter returns `write_disabled` regardless of the gate on
2.0.9-class stacks).

**Create / modify on Borný:** keep `off`. Borný is the intended **verification
site** for a safe native create path, but that path is untested (CAPABILITIES:
BLOCKED); do not flip `create`/`modify` to `live` until the orchestration is
verified against Borný's HBook and explicitly approved.

- **Rollback (any kind):** `wp mha set-write-mode --kind=<k> --mode=off` reverts
  that one kind instantly without touching the others; deactivating the plugin is
  the whole-surface rollback. No legacy impact either way.

## Stage 3 — Repeat per site, in order

After Borný soaks clean, repeat Stage 1 (deploy + reads) then Stage 2 (writes per
kind) on each remaining site. Order by risk/version:

1. **srdicko (2.2.x)** — next, because it is the **only** site where payments can
   reach `live` (`add_resa_payment`/`payments_logs`). Walk status → note → block →
   guest, then **payment** (`dry_run` → `live`) once the ops kinds soak clean.
2. **slunicko (2.0.9)** — status/note/block/guest can reach `live`; **payment
   stays `off`** (2.2-only method absent → `write_disabled`). Reads + ops-writes
   only.
3. **passage (2.0.9)** — same as slunicko for ops-writes + reads; **payment `off`**.
   Passage additionally has legacy `hbapi/v1` PATCH + blocks: v2 block writes reach
   `live` here via native `add_blocked_accom()` after the block `dry_run`/`live`
   gate, letting `hbapi/v1` be the **last** namespace retired (MIGRATION §4).

Per-site go/no-go and rollback are identical to Stages 1–2. Do not start a site's
write stages until its reads have soaked clean and the consumers serving it are on
v2 read keys (CREDENTIAL_MIGRATION §3).

## Stage 4 — Consumer cutover & legacy retirement

Interleave with Stage 3 per `MIGRATION.md` §3 (widgets → Lovable → Make → Telegram
→ OPS bridge) and `CREDENTIAL_MIGRATION.md` §3: move each consumer's reads to v2
and soak, then its ops-writes one kind at a time. Retire a legacy namespace only
when every consumer is on v2 and `mha_audit`/access logs show zero traffic for the
soak window on all four resorts (MIGRATION §4 order: `ks/v1` → `resort-dashboard/v1`
+ exec routes → `hbapi/v1` last).

## Capability × mode summary (what may be `live`, per site)

Rollout targets Sluníčko/Srdíčko/Passage on 2.2.1 (payments + pricing available).
Borný (2.0.9, closed) keeps payment/pricing at `write_disabled`/`unsupported`.

| Kind | srdicko (2.2.1) | slunicko (2.2.1*) | passage (2.2.1*) | borny (2.0.9, closed) |
|---|---|---|---|---|
| status / note / block / guest | live | live | live | verified (Phase 4); keep off until authorized |
| payment | **live** | **live** (after upgrade) | **live** (after upgrade) | `write_disabled` (no 2.2 method) |
| create / modify | gated — soak dry_run→live | gated | gated | create verified live (Phase 4); modify dry_run only |
| pricing quote (read) | live | live (after upgrade) | live (after upgrade) | `unsupported` (no HbPriceCalc) |

\* Sluníčko/Passage are 2.0.9 until the developer upgrades; until then payment/quote
return their structured 503 and the plugin auto-enables them once 2.2.1 is live.
All production writes stay at `off` until separately authorized.

## Go/no-go gate (reusable checklist per stage)

- Reads: envelope correct, data matches legacy sample, `mha_audit` + `request_id`
  consistent, negatives (`403`/`401`) correct.
- `dry_run`: `would_*` returned, audit written, **no** native call / no state
  change, idempotency replay + `409` correct.
- `live`: single real mutation verified in HBook, clean before/after audit pair.
- Version gates respected: payments `live` on 2.2.x only; create/modify `off`.
- Rollback rehearsed: `set-write-mode … off` reverts one kind; plugin deactivate
  reverts all; legacy keeps serving throughout.
