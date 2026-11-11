# Pre-deploy checklist — Borný (chatkyborny.cz), read-only stage

All three gates below MUST be green before the plugin is activated. If any cannot be confirmed, stop.

## Gate 1 — Recoverable backup (BLOCKER: needs server access I don't have)
- [ ] A full file + DB backup of chatkyborny.cz taken within 24h, **downloaded off-server and confirmed openable** (UpdraftPlus "Backup now" → download both zips, OR WEDOS panel snapshot, OR phpMyAdmin export of all `hb_*` + `wp_options` + `wp_mha_*`).
- Why: even a read-only plugin runs activation (which creates the `mha_*` tables via dbDelta). Activation touching the DB is the only write it performs; a backup makes it reversible.
- I cannot take or verify this backup — no filesystem/DB access to Borný. **Jacob confirms this gate.**

## Gate 2 — HBook version compatibility
- [ ] Record Borný's installed HBook version (wp-admin → Plugins, or `{prefix}` of its tables). Expected: 2.0.x (old stack), NOT 2.2.1.
- The plugin is built against 2.2.1 but **feature-detects** every native write method (`method_exists`) and degrades to `write_disabled` when a 2.2-only method is absent. Reads use only columns present since 2.0.x. So read-only operation is compatible with 2.0.x; writes stay off regardless.
- Confirm the live `{prefix}hb_resa` columns match CONTRACTS §8 (the `/meta` endpoint reports table row counts once installed; a one-time column check via the read API confirms shape).

## Gate 3 — Disable-without-impact (additive by construction)
- Evidence the plugin cannot affect existing integrations:
  - New namespace `matova/v2` only — it registers **no** route under `resort-dashboard/v1`, `hbapi/v1`, `matova-hbook/v1`, `ks/v1` (grep the plugin: zero `register_rest_route` outside `matova/v2`).
  - Its own tables `wp_mha_idempotency/mha_audit/mha_events` — touches no `hb_*` table on activation.
  - Write-mode defaults `off` for every kind → all mutating endpoints return `write_disabled` and call no native method.
  - Deactivation removes all its routes; legacy mu-plugins are untouched and keep serving. No DB rollback needed (optionally drop `mha_*` tables).
- [ ] Confirmed by code review (done) + a post-install smoke test (below) showing legacy endpoints still 200.

## Post-install smoke (read-only, run immediately after activation)
1. `GET /wp-json/` lists `matova/v2` **and** all legacy namespaces unchanged.
2. `GET /wp-json/matova/v2/health` → 200 (public).
3. With a freshly issued **read** key (Bearer): `GET /matova/v2/meta`, `/reservations?from&to`, `/availability?from&to`, `/units`, `/pricing/quote` → 200, envelope correct.
4. Legacy still works: `GET /wp-json/resort-dashboard/v1/health` 200, `/accom-units` (with legacy key) 200 — proves no interference.
5. Any mutation endpoint → `write_disabled` 503 (proves writes are off).
6. `GET /matova/v2/events?since=0` → empty or existing — proves outbox table created.
