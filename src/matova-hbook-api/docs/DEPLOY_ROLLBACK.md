# Matova HBook API — Deploy & rollback (WEDOS)

Authoritative spec: `docs/CONTRACTS.md` §5, §7, §9. This plugin is **additive**:
legacy namespaces keep serving throughout, so deploy and rollback are low-risk.

## 0. Hosting constraints (WEDOS)
- **No wp-admin Theme/Plugin File Editor** — its save endpoint hangs PHP-FPM on
  this hosting. Banned.
- **No wp-admin installer uploads for anything above ~5 KB compressed** — the
  FPM-wedge envelope makes installer-driven deploys unreliable. This plugin is
  larger than that, so it goes up by **SFTP only**.
- Aliases share a docroot and PHP is per-hosting; dev is a separate hosting
  account (`dev.kempslunicko.cz`). Deploy to dev first, prod only on approval.

## 1. First deploy (per site)
1. **Stage the tree locally** exactly as it ships: `matova-hbook-api/` with
   `matova-hbook-api.php`, `includes/`, `openapi/`, `docs/`.
2. **SFTP upload** the whole folder to `wp-content/plugins/matova-hbook-api/`.
   Upload to a temp dir and move/rename into place to avoid a half-uploaded tree
   being loaded mid-transfer. Do not use wp-admin upload.
3. **Confirm write-mode is `off`.** On first deploy every kind in
   `mha_write_mode` must be `off` (the default). Do not enable any write kind yet.
4. **Activate** the plugin (activation runs `MHA_Store::install()` → `dbDelta`
   creates `mha_idempotency`, `mha_audit`, `mha_events`). Activation is additive;
   it does not touch legacy routes.
5. **Issue keys** with the admin/CLI helper (`MHA_Auth::issue_key()`), one per
   consumer × resort × scope; capture each raw key once (not recoverable).

## 2. Smoke-test reads (before any write)
- `GET /wp-json/matova/v2/health` (public) → `ok:true`, `hbook_available:true`.
- `GET /meta` with a `read` key → correct `resort`, prefix-derived registry,
  `hbook_version`.
- A few `read` routes (`/units`, `/availability?from&to`, `/day/{date}`,
  `/reservations?from&to`) → envelope shape correct, data matches legacy.
- Confirm `mha_audit` rows are being written and `request_id` matches the
  envelope `meta.request_id`.
- Negative checks: query-string key rejected (`403`), unknown/short key (`401`),
  `read` key on a write route (`403`).

## 3. Enable writes gradually (per kind, per site)
Flip `mha_write_mode[kind]` one kind at a time, with approval, following
MIGRATION.md cutover order (status/notes/blocks/guest first; payments only on
2.2.x; create/modify stay `off`):
1. `off → dry_run`: exercise the write; verify it returns the computed
   `would_*` effect, writes an audit row, and **does not** call the native HBook
   method (no state change). Confirm `Idempotency-Key` behavior (replay returns
   stored response; changed body → `409`).
2. `dry_run → live`: enable for that one kind, run a single real mutation, and
   verify in HBook (status/comment/block/payment actually changed) plus a clean
   `mha_audit` before/after pair.
3. Leave the other kinds at their current mode. Never bulk-flip to `live`.

Payments: keep `off`/`dry_run` on slunicko/passage (2.0.9 → `write_disabled`);
only srdicko (2.2.x) should reach `live` for payments. Create/modify reservation
stay `off` everywhere until a local HBook env or Borny verification exists.

## 4. Updating an existing deploy
- Re-upload the changed files by **SFTP** (never wp-admin). Keep the folder and
  main-file names identical so it is a drop-replace.
- If code appears deployed but hooks/routes do not register or an old version
  keeps running, suspect **stale opcache** — reset PHP-FPM / opcache before
  scripting any browser workaround. Do not re-upload plugin zips through wp-admin.
- Re-run the §2 read smoke test after every update.

## 5. Rollback
Because v2 is additive, rollback does **not** restore booking functionality
(legacy never stopped serving) — it only removes v2:
1. **Deactivate** the `matova-hbook-api` plugin from wp-admin (or
   `wp plugin deactivate`). This unregisters all `matova/v2` routes immediately;
   legacy namespaces continue serving. This is the fast, safe rollback.
2. **Revoke keys** if a key is suspected compromised: set the record
   `active:false` in `mha_keys` (immediate) rather than deleting, to preserve the
   audit trail.
3. **Full removal** (optional): delete the plugin folder by SFTP after
   deactivation.
4. **Drop `mha_*` tables only if abandoning v2 entirely** (`mha_idempotency`,
   `mha_audit`, `mha_events`) and dropping options `mha_keys`, `mha_key_salt`,
   `mha_config`, `mha_write_mode`. Keep them if you intend to re-deploy, so audit
   history and idempotency records survive. Note the tables hold guest PII in
   `before_json`/`after_json`; drop (or prune) them if the data must not persist.

## 6. Safety defaults recap
- First deploy: all write kinds `off`.
- Writes go `off → dry_run → live`, one kind at a time, with approval.
- Prod stays read-only until explicitly approved; dev (`dev.kempslunicko.cz`) is
  where write modes are exercised first.
