# Install runbook — matova-hbook-api on Borný (read-only stage)

Target: `chatkyborny.cz` → `wp-content/plugins/matova-hbook-api/`. Keep it additive and writes-off.

## The deployment-channel problem (why this needs you)
This hosting + my sandbox leave no channel I can drive to write the plugin files to the server:
- SFTP/FTP: WEDOS hosts don't resolve from my container or the Mac's connected shell (network-restricted). Your real Terminal can reach them; the sandbox can't.
- wp-admin "Upload Plugin": the zip is >5KB and the FPM-wedge rule bans installer uploads on this hosting.
- Chrome isn't logged into chatkyborny.cz, and a multi-file plugin can't go through a Code-Snippet.

So the install itself is a one-time action on your side. Pick whichever is least friction:

### Option A — SFTP/WebFTP upload (recommended, ~2 min)
1. Backup first (Gate 1 in PRE_DEPLOY_CHECKLIST.md).
2. Unzip `matova-hbook-api-deploy.zip` locally → folder `matova-hbook-api/`.
3. Upload the whole folder to `wp-content/plugins/` using the **WEDOS web File Manager / WebFTP** (webftp.wedos.net) — the browser upload, not a desktop FTP client (per your no-FTP-drops rule). Keep the folder name exactly `matova-hbook-api`.
4. wp-admin → Plugins → activate **Matova HBook API**. Activation creates only the `mha_*` tables.
5. Tell me "installed" — I'll run the post-install smoke + compatibility probe from the browser/API against the live site (read-only).

### Option B — temporary file-writer snippet (TEST RESORTS ONLY — not for production)
Used once on Borný (closed resort) to bootstrap the install: a temporary,
admin-authenticated, token-gated Code-Snippet that extracts the plugin zip into
`wp-content/plugins/`, then is deleted. **Do not use this for Sluníčko, Srdíčko or
Passage** — production install is Option A (hosting File Manager / WebFTP upload).
The snippet was fully removed from Borný after Phase 4 and Code Snippets returned
to inactive.

## Config + control after activation — all from wp-admin (no CLI, no snippets)
Everything is managed at **wp-admin → Settings → Matova API** (admin-only,
nonce-protected):
- Confirm resort auto-detected by host and the plugin version.
- **Issue a read-only key** (consumer + scope `read` + resort); the raw key is shown
  **once** — copy it to your secret manager, never into a browser bundle.
- Leave every write mode at **off** (default). The page has a one-click **kill
  switch** (all→off) for instant whole-surface disable.
- `wp mha show-config` / `wp mha issue-key …` are the equivalents where WP-CLI
  exists, but are not required.

## Rollback (instant, no DB restore needed)
- wp-admin → Plugins → Deactivate Matova HBook API → routes vanish, legacy untouched.
- If abandoning: delete the folder via SFTP and optionally `DROP TABLE wp_mha_idempotency, wp_mha_audit, wp_mha_events`.
- The plugin never wrote an `hb_*` row in this stage, so there is nothing in HBook to undo.

## Deploy artifact
`_source/matova-hbook-api-deploy.zip` (runtime only — `matova-hbook-api.php`, `includes/`, `openapi/`, `docs/`; tests and tests-live excluded). Folder + main file name are stable for drop-replace on future updates (per your FTP-overwrite workflow).
