# Controlled write-testing plan — Borný (NEXT stage, needs separate approval)

Do not start until read-only verification on Borný passes and Jacob authorizes this stage explicitly. No real guest/reservation/payment/availability is touched: every test uses isolated, self-created records that are cleaned up.

## Guardrails
- Only on Borný (closed resort). Slunicko/Srdicko/Passage untouched.
- Test records only: future dates **2026-11-15 … 2027-03-01** (resort closed), guest name prefix `TEST-API `, email `*@test.matovagroup.cz`, `admin_comment` marker `[MHA-TEST run=<uuid>]`.
- Per-kind write-mode ladder, one kind at a time: `off → dry_run` (verify `would_*`, no DB change, audit written) → `live` for that kind only.
- Every mutation carries an `Idempotency-Key`. A teardown script cancels (never deletes) every `[MHA-TEST]` row and releases every test block, then asserts none remain.
- HBook email is suppressed for the window (drop `00-dev-kill-emails.php` mu-plugin, or point HBook mail at a sink), removed after.

## Sequence (each step: dry_run first, confirm, then live, then verify DB + teardown)
1. **block create/release** — create a maintenance block on an unoccupied test unit for a closed-season range; verify `hb_accom_blocked` row + `hb_blocked_accom_updated`; release it; verify gone; confirm a linked-reservation block is refused.
2. **note** — append a `[MHA-TEST]` note to a self-created test reservation; verify `admin_comment`.
3. **status** — create a test reservation `new`, confirm→`confirmed`, cancel→`cancelled`; verify status + that cancel released its blocks; verify idempotent replay.
4. **payment** — add a payment to a test reservation; verify it lands in `payments_logs` JSON and the flat `paid` column is untouched; verify balance recompute; add a second payment (distinct idempotency key) accumulates; replay does not double-post.
5. **guest update** — update a test customer's phone/email; verify `hb_customers.info` merged, other fields preserved.
6. **create reservation** — create a full test reservation via the native `create_resa` path; verify price computed by HBook, blocks auto-created, hooks fired, log row; cancel + teardown.
7. **modify (PATCH)** — remains **blocked**; confirm the endpoint returns `write_disabled` (no safe native path). Document only.

## Concurrency / failure (live, on test records)
- Two identical create/payment calls, same Idempotency-Key, near-simultaneous → one effect.
- Forced-failure (bad accom id, status out of set) → error envelope + audit row + DB unchanged.

## Exit criteria to promote writes beyond Borný
- Steps 1–6 green live on Borný, teardown leaves zero residue, legacy endpoints unaffected throughout, and `mha_audit` shows a clean before/after trail for every mutation. Then per-site rollout per ROLLOUT.md, each site gated on its HBook version (2.0.9 sites keep payment/create gated until upgraded or method-confirmed).
