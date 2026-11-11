# Matova HBook API — Security model (matova/v2)

Authoritative spec: `docs/CONTRACTS.md` §2, §5, §7. Implementation:
`includes/class-mha-auth.php`, `includes/class-mha-response.php`.

## 1. What is implemented today

### Transport & credential presentation
- **Bearer only.** Credentials arrive as `Authorization: Bearer <key>`.
  `MHA_Auth::bearer()` reads the `Authorization` header (falling back to
  `$_SERVER['HTTP_AUTHORIZATION']`) and nothing else.
- **No query-string / body keys.** Every scoped route's permission callback
  rejects a request carrying `?api_key=` or `?key=` with `403 forbidden` before
  any lookup (`require_scope()`), as defense in depth on top of header-only
  parsing. TLS is assumed at the edge.

### Keys at rest
- Keys are **per consumer × per resort × scope**. Record shape in option
  `mha_keys`: `{ id, label, consumer, scope, resort, hash, created, last_used, active }`.
- The **raw key is never stored.** Only a **salted SHA-256 hash** is persisted:
  `hash('sha256', salt . '|' . raw)`. The salt is a per-site 32-byte random value
  in option `mha_key_salt`, created on first use with autoload `no`.
- Issued keys are opaque random tokens: `mha_` + 24 random bytes (hex), ≥ 32
  chars (`MHA_MIN_KEY_LEN`). `issue_key()` returns the raw key **once**; it is not
  recoverable afterwards.

### Verification
- Presented token shorter than `MHA_MIN_KEY_LEN` (32) → `401 unauthorized`
  without a hash lookup.
- The presented hash is compared with **`hash_equals`** (constant-time) against
  **every active record**, with **no early break** on match, so timing does not
  reveal which record (if any) matched.
- Inactive (`active:false`) or hash-less records are skipped. No active match →
  `401 unauthorized`.
- On success the principal `{id, consumer, scope, resort}` is stashed as the
  request context and `last_used` is touched.

### Authorization (scopes)
- Scope ladder `read (1) < ops-write (2) < admin (3)` (`SCOPE_RANK`).
  `scope_allows(have, need)` is a numeric `>=` test.
- A key below the route's required scope → `403 forbidden` (e.g. a `read` key on
  any write route).
- Scope-per-operation: reads → `read`; status / note / block / payment / guest
  update → `ops-write`; create & modify reservation → `admin`.
- **Admin fallback:** a logged-in user with `manage_options` (e.g. a WordPress
  Application Password) resolves to `admin` scope for human/admin tooling.

### Rate limiting
- Per **key id**, token bucket in a transient (`mha_rl_<md5(key_id)>`), default
  **120/min** (`MHA_RATE_PER_MIN`; `0` disables). On exceed → `429 rate_limited`
  with a `Retry-After` header (`MHA_Response::rate_limited()`).

### Idempotency (write integrity)
- Every mutation requires an `Idempotency-Key` header. `mha_idempotency` stores
  `key_hash UNIQUE, route, resort_id, request_sha, response_json, http_status`.
  A replay with the same key returns the stored response verbatim; the same key
  with a different body → `409 conflict`.

### Audit
- Every authenticated call writes one `mha_audit` row: `request_id, key_id,
  consumer, scope, resort_id, route, method, http_status, target_type,
  target_id, before_json, after_json, ip`. Writes capture before/after around the
  native HBook call. `request_id` is also returned in every envelope's `meta`.

### Failure handling
- Error envelope carries a bounded `error.code` set
  (`bad_request|unauthorized|forbidden|not_found|conflict|rate_limited|write_disabled|upstream_error`),
  HTTP status mirrors the class, and a caught fatal becomes `upstream_error`
  rather than leaking a PHP notice/stack trace.

## 2. Remaining risks

- **No per-request payload signing / replay window beyond idempotency.** A
  leaked bearer key is fully usable until revoked; mitigation is short rotation
  and least-scope issuance, not crypto.
- **Transient-backed rate limit.** On hosts where transients fall back to the
  options table or are evicted under memory pressure, the bucket can reset early;
  treat the limit as best-effort throttling, not a hard quota.
- **`manage_options` fallback widens the admin surface** to any site admin
  session; keep admin accounts and Application Passwords tightly held.
- **Audit/idempotency tables grow unbounded** without a retention job; plan a
  prune (see DEPLOY_ROLLBACK.md) so `before_json`/`after_json` do not accumulate
  guest PII indefinitely.
- **Hashing is SHA-256, not a slow KDF.** Acceptable only because keys are
  high-entropy random tokens (not user passwords); do not reuse this scheme for
  anything guessable.

## 3. Migration from the legacy single shared key

Legacy namespaces authenticated with a **single shared key per resort** (or,
worse, hardcoded keys). v2 replaces that with **per-consumer, per-resort, scoped**
keys so a single leak is narrow and individually revocable.

### Burned keys that must still be rotated
These diagnostic keys are known-compromised and must be rotated on every site
where they equalled or were derived from a shared key, **even though the
diagnostic plugin is now deleted on Slunicko and Passage** (deletion stops new
exposure but does not invalidate a key that still equals a live shared secret):

- `KSapi2026` (Slunicko)
- `CBapi2026` (Borny)
- `SRapi2026` (Srdicko)

No other key values are recorded here; never invent or write key material into
docs or the repo. New keys are minted via the issue helper and shown once.

### Rotation plan — "do not rotate until all consumers migrated"
Rotating a shared key breaks every consumer still presenting it. Rotate only
after each consumer below is cut over to its own scoped v2 key (overlap window:
old and new keys both `active` until the switch completes, then the old key is
set `active:false` to revoke immediately).

**Consumer inventory (must each migrate before the shared key is retired):**
1. **Lovable edge functions** — consume reads (and some ops writes) per resort.
2. **Make scenario** — automation workflow pulling/pushing reservation data.
3. **Matova OPS bridge** — the unified-API bridge for Matova OPS.
4. **Telegram bot** — the resort chatbot assistant.
5. **In-process WP widgets** — on-site widgets (e.g. last-minute availability)
   that currently call HBook in-process; give them a `read` key or keep them
   in-process, but account for them before retiring any shared key.

Procedure per site: issue scoped keys for all five consumers → switch each
consumer to its new key → confirm via `mha_audit` that the burned/shared key id
has no recent `last_used` → set the burned/shared key `active:false`.

---

## Phase 5 reliability addendum

- **Retry / concurrent duplicate prevention (payments & reservations).**
  `MHA_Store::idempotency_begin()` reserves the key with `INSERT IGNORE` and now
  proceeds **only if this request won the insert** (`rows_affected === 1`). A
  concurrent request with the same key is told `in_progress` → controller returns
  `409` + `Retry-After`, never a second native mutation. A completed key replays
  the stored response. An abandoned in-flight reservation (crash mid-write) is
  reclaimed after `IDEM_INFLIGHT_TTL` (120 s) so a key can't lock forever.
  Covered by live-harness tests (`run-extra.php` §1a–1e + stale-reclaim).
- **Control surface without CLI/snippets.** `MHA_Admin` (Settings → Matova API,
  `manage_options`, nonce-checked) manages write modes, the kill switch, and key
  issue/revoke. No temporary file-writer snippet runs in production.
- **Boot resilience.** Route registration isolates each controller and the outbox
  subscription in try/catch, so a single failing component degrades one route group
  instead of dropping the whole `matova/v2` namespace (which would surface as
  `rest_no_route`). Schema creation never runs on front-end requests.
- **Audit retention (ops recommendation, not a code change).** `mha_audit` and
  `mha_events` grow unbounded. Recommend a scheduled prune (e.g. keep 180 days of
  audit, drain events past the consumers' min cursor) once consumers are live. No
  PII beyond what HBook already stores is written to these tables.
- **Intermittent `rest_no_route` (investigated Phase 5).** Classified as
  host-side load-shedding, NOT a plugin defect: under gentle and mild-concurrency
  probing the legacy mu-plugin namespace (`resort-dashboard/v1`) and `matova/v2`
  are always both present in the REST root; failures only appeared under heavy
  single-client burst. OPcache is off/restricted on the box, so this is not stale
  bytecode. Mitigation: consumer retry-with-backoff (already specified); recommend
  confirming PHP-FPM `pm.max_requests`/`pm.max_children` headroom with the host.
