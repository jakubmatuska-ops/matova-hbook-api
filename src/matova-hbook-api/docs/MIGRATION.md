# Matova HBook API — Migration & rollout (matova/v2)

Authoritative spec: `docs/CONTRACTS.md` (intro, §6, §9). This describes the
**additive** rollout of `matova/v2` alongside the four legacy namespaces, and the
criteria for retiring each one.

## 1. Principle: additive, legacy untouched

v2 ships as one new versioned namespace (`matova/v2`) deployed identically to all
four resorts. The legacy namespaces keep serving unchanged during the whole
migration:

- `resort-dashboard/v1`
- `hbapi/v1`
- `matova-hbook/v1`
- `ks/v1`

Nothing in v2 modifies, unregisters, or re-routes a legacy route. A consumer is
moved to v2 only by changing the base URL/key it calls; rollback is "point it
back" (see §4).

## 2. Compatibility adapters

For each legacy response shape a consumer depends on, provide a thin adapter
rather than forcing an immediate schema change on the consumer:

- **Envelope shim.** Legacy routes returned bespoke shapes; v2 always returns the
  standard envelope (`ok`/`data`/`meta` or `ok:false`/`error`/`meta`). Where a
  consumer cannot yet parse the envelope, unwrap `data` client-side or add a
  temporary consumer-side shim — do not denormalize v2.
- **Field-name mapping.** v2 field names mirror the HBook §8 columns. Where a
  legacy consumer expected a renamed/flattened field (e.g. `hbapi/v1` block
  shapes, `ks/v1` last-minute payloads), map in the consumer or in a per-consumer
  adapter layer, keeping v2 canonical.
- **Capability parity first.** A consumer is not asked to cut over until the v2
  endpoints it needs are COMPLETE for its resort (see CAPABILITIES.md); PARTIAL or
  BLOCKED capabilities keep running on legacy until v2 reaches parity.

## 3. Cutover order

1. **Reads first.** Switch every consumer's **read** traffic to v2
   (`GET /reservations`, `/availability`, `/day/{date}`, `/events`, etc.) and
   verify against legacy output for a soak period. Reads are side-effect-free, so
   a wrong shape is caught without risk to bookings.
2. **Then writes.** Only after reads are stable, switch **ops-write** traffic
   (status, notes, blocks, payments, guest update), one kind at a time, moving the
   write-mode gate `off → dry_run → live` per kind (see DEPLOY_ROLLBACK.md).
3. **Admin writes last / optional.** Create & modify reservation stay `off` and
   untested until a local HBook env or Borny verification exists; do not cut any
   consumer's create/modify traffic to v2 until then.

### Per-consumer switch (order within each)
Cut over in this order so the highest-volume, lowest-risk consumers validate the
path first: in-process WP widgets (reads) → Lovable edge functions (reads) →
Make scenario → Telegram bot → Matova OPS bridge. Each keeps its legacy key
active during an overlap window, then revokes it once `mha_audit` confirms no
further legacy calls.

## 4. Retiring a legacy namespace

Retire a namespace only when **all** of the following hold for it, on **all four**
resorts:

- Every consumer of that namespace has cut over to v2 and run clean for the soak
  period (reads verified byte-equivalent after adapter mapping; writes verified in
  `live`).
- `mha_audit` (and/or legacy access logs) show zero traffic to the namespace for
  the soak window.
- The burned/shared keys tied to it are rotated and revoked (see SECURITY.md §3).

Then unregister that namespace's routes (or deactivate its mu-plugin). Suggested
order, least-coupled first:

1. `ks/v1` — site-specific Slunicko widgets; smallest surface.
2. `resort-dashboard/v1` — dashboard reads; retire once OPS bridge + Lovable are
   on v2 reads. **Note:** this namespace hosted the diagnostic backdoor on
   Slunicko/Passage — confirm the diagnostic mu-plugin is gone and its keys
   rotated before relying on its logs as "zero traffic".
3. `matova-hbook/v1` — general HBook reads/writes; retire after ops writes are
   live on v2.
4. `hbapi/v1` — retire last; it is the only namespace that today carries
   PATCH/block writes (Passage only), so it stays until v2 block/modify writes are
   live and verified on Passage.

## 5. Per-site HBook-version caveat

v2 is one codebase over heterogeneous HBook versions; migration readiness differs
per site because the adapter feature-detects native methods (`method_exists`)
and falls back to `write_disabled` where a 2.2 API is absent:

- **srdicko** — HBook **2.2.x** (2.2.1 confirmed in source): `payments_logs`,
  mark-as-paid and payment links available → payment/write capabilities can reach
  `live` first here.
- **slunicko / passage** — HBook **2.0.9**: 2.2-only methods (e.g. the
  `payments_logs` payment path) are absent, so those writes return
  `write_disabled` with a version note until HBook is upgraded. Reads migrate
  normally.
- **borny** — HBook **2.0.9 confirmed on-box** (Phase 4). Closed resort; reads +
  ops-writes verified live, payment/pricing correctly `503` (2.2-only). Not part of
  the production consumer cutover; used as the degraded reference. Sluníčko/Srdíčko/
  Passage target 2.2.1.

Prefix differences the adapter resolves from the registry: slunicko `ar1w_`,
borny `ar1w_` (separate DB), srdicko `jbsp_`, passage `y9j6_`.

## Integration-specific notes (consumer mapping — finalized)

| Consumer | v2 role | Migration | Key scope |
|---|---|---|---|
| **Matova OPS** (Lovable + Claude builds) | reads (availability, reservations, occupancy) + ops-writes (status, note, block, guest) | reads first, soak, then ops-writes one kind at a time; keep legacy key until its reads show zero traffic | `read` → later `ops-write` |
| **Lovable edge functions** | server-side reads; proxy for OPS | swap base URL + bearer key to `matova/v2`; response envelope is `{ok,data,meta}` — adapters map legacy fields (see COMPAT_MATRIX) | `read` |
| **Make scenarios** | reads + scheduled ops-writes | point HTTP modules at v2; set `Idempotency-Key` per action (Make run id works well) to make retries safe | `read` → `ops-write` |
| **Telegram bot (Matova OS)** | reads; occasional status writes | low surface; migrate after OPS | `read` (+`ops-write` if it writes) |
| **Comgate** (HBook payment plugin) | **not a v2 consumer** — remains native | Leave as-is. Comgate's callback writes payments into HBook's `payments_logs` directly. v2 only **reads** those (payment read) and, on 2.2.1, can **append** API-initiated payments via `add_resa_payment`. **Do not record a Comgate-settled payment through v2 too** — both append to `payments_logs` and would double-count. Rule: a payment is written by exactly one path (Comgate callback *or* a v2 `payment` write), never both. | n/a |
| **Meridian** (future Android assistant) | reads + ops-writes | onboard like OPS: issue a `read` key, soak, then `ops-write`; its own key per device/app, never shared with OPS | `read` → `ops-write` |

**Payments, double-write safety.** Across all consumers, reservation and payment
writes carry an `Idempotency-Key`; the store now rejects a concurrent duplicate
(`409`, retry) and replays a completed one, so a client retry or two consumers
racing the same key cannot double-apply. Comgate is the one payment path that does
**not** go through v2 — keep it the sole writer for gateway-settled payments.
