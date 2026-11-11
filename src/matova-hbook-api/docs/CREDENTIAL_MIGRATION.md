# Matova HBook API — Credential migration & key rotation

Cross-references: `docs/SECURITY.md` §2 (keys at rest), §3 (burned keys,
consumer inventory, rotation rule), `docs/CONTRACTS.md` §2 (auth/scopes),
`includes/cli/class-mha-cli.php` (`wp mha` tooling). This document is the
operational runbook: who holds which credential today, the scoped v2 key each
should get, the switch step, the rotation sequence, and the proof that old
credentials are dead. **No key values appear here**; raw keys are produced once
by `wp mha issue-key` and shown only in that terminal.

## 1. Credential reality today

Legacy namespaces authenticate with a **single shared secret per resort** —
`HBAPI_SECRET` (a.k.a. `WP_API_KEY` / probed as `MATOVA_HBAPI_SECRET` /
`RESORT_DASHBOARD_SECRET` by `matova-hbook-exec.php`). One secret per site unlocks
reads **and** writes for **every** consumer, with no scoping and no per-consumer
revocation. Plus three **burned diagnostic keys** (see §4).

v2 replaces this with **per consumer × per resort × scope** keys (SECURITY §2), so
a leak is narrow and individually revocable.

## 2. Consumer inventory → target v2 keys

Scopes: `read` (all GET), `ops-write` (status/note/block/payment/guest),
`admin` (create/modify). Issue one key per **consumer × resort × scope** actually
used. Keys are minted with:

```
wp mha issue-key --consumer=<c> --scope=<s> --resort=<r> [--label="…"]
```

| # | Consumer | Credential today | Target v2 key(s) | Switch step |
|---|---|---|---|---|
| 1 | **Lovable / Supabase edge functions** | shared `HBAPI_SECRET` per resort (reads + some ops writes) | `read` per resort it serves; add `ops-write` per resort only where it performs status/payment writes. Keep read and ops-write as **separate** keys so reads can migrate first. | In the Supabase project, replace the stored secret (env var / vault) with the new v2 `read` key and point the base URL at `/wp-json/matova/v2`; redeploy the edge functions. Add the `ops-write` key only when write cutover for that resort begins. |
| 2 | **Make scenario** | shared `HBAPI_SECRET` | `read` for reservation pulls; `ops-write` only if it pushes status/notes. One key per resort it touches. | Edit the HTTP/connection module's `Authorization: Bearer` to the new key and the base URL to v2. If the scenario's response-mapping cannot be rewired in the same window, point it at the optional legacy-shape shim route first (`GET /matova/v2/compat/resort-dashboard/reservations`, enable `wp option update mha_legacy_shim on`), then rewire and turn the shim off. |
| 3 | **Matova OPS bridge** (on the Mac) | shared `HBAPI_SECRET` (the unified-API bridge; uses exec `/add-note`, `/create-hold`, reads) | `read` + `ops-write` per resort; **not** `admin` until create/modify is unblocked. The bridge's hold-create currently hits legacy `/create-hold` (raw-SQL) — it moves to v2 `POST /reservations` only once create is verified (stays gated). | Update the bridge config on the Mac with the per-resort v2 keys and the v2 base URL; switch `/add-note` calls to `POST /reservations/{id}/notes`. Leave hold-create on legacy until v2 create is verified, or disable it. |
| 4 | **Telegram bot** (resort chatbot) | shared `HBAPI_SECRET` | `read` per resort; `ops-write` only if the bot performs staff writes (status/notes). | Update the bot's stored secret + base URL to v2; restart the bot. |
| 5 | **In-process WP widgets** — `ks-last-minute` (WPCode 5276, `ks/v1/lastminute`), weather/availability promo (`ks-resort-features`), resort map | call HBook **in-process** (no network key) today; `ks/v1` is a WPCode snippet, not a keyed API | either **keep in-process** (no key; lowest latency) **or**, if repointed to v2 over HTTP, a `read` key per resort. | Preferred: leave them in-process. If moved to v2 HTTP reads, store a `read` key in the snippet/plugin config. Account for them before retiring any shared key (they may not use it, but `ks/v1` retirement depends on them). |

**Admin-scope keys** are issued only to the human/CLI operator doing create/modify
work, and only once those capabilities leave BLOCKED. The `manage_options`
Application-Password fallback (SECURITY §2) already covers ad-hoc admin tooling,
so a standing `admin` bearer key is usually unnecessary.

## 3. Rotation sequence (per resort)

Follows SECURITY §3's rule — **do not rotate the shared key until every consumer
is migrated** — and the cutover order in `MIGRATION.md` §3 (widgets → Lovable →
Make → Telegram → OPS bridge), reads before writes.

1. **Issue v2 keys.** For each consumer above, mint its scoped key(s):
   `wp mha issue-key --consumer=… --scope=… --resort=…`. Capture each raw key
   once. Verify with `wp mha list-keys --resort=<r>` (shows id/consumer/scope/
   active — never the hash).
2. **Migrate each consumer** (overlap window: the shared key stays `active`
   alongside the new v2 keys). Switch reads first and soak, then ops-writes one
   kind at a time (`ROLLOUT.md`). The shim (if used) is a per-consumer bridge,
   turned off as soon as that consumer parses v2.
3. **Verify the legacy/shared key is no longer needed.** In `mha_audit`, confirm
   every consumer now authenticates with its own v2 key id and that no traffic
   still depends on the shared secret. Cross-check legacy access logs for
   `resort-dashboard/v1`/`hbapi/v1` hits (should trend to zero for migrated
   consumers over the soak window).
4. **Rotate / revoke the shared key.** Once (3) holds for every consumer on that
   resort, rotate `HBAPI_SECRET` (new value in wp-config via SFTP) or retire it.
   Any v2 key is revoked immediately with `wp mha revoke-key <id>` (sets
   `active:false`, keeps the audit trail).
5. **Confirm the burned diagnostic keys are dead** (§4).

Repeat per resort. Because v2 is additive, a consumer that misbehaves after
cutover is rolled back by pointing its base URL + key back to legacy
(`MIGRATION.md` §4 / `DEPLOY_ROLLBACK.md` §5).

## 4. Burned diagnostic keys — grep result & kill confirmation

The three diagnostic keys `KSapi2026` (Sluníčko), `CBapi2026` (Borný),
`SRapi2026` (Srdíčko) are known-compromised (SECURITY §3). **Grep of the deployed
mu-plugin source for those literal strings:**

```
$ grep -rn "KSapi2026\|CBapi2026\|SRapi2026" slunicko srdicko passage borny
slunicko/mu-plugins/hbook-diagnostic.php:12:  *   /diagnostic?api_key=KSapi2026
slunicko/mu-plugins/hbook-diagnostic.php:29:  return in_array($key, ['KSapi2026','CBapi2026','SRapi2026'], true);
passage/mu-plugins/hbook-diagnostic.php:12:   *   /diagnostic?api_key=KSapi2026
passage/mu-plugins/hbook-diagnostic.php:29:   return in_array($key, ['KSapi2026','CBapi2026','SRapi2026'], true);
```

**Finding:** the only code that accepts these keys is `hbook-diagnostic.php`, and
it is present **only on slunicko and passage** in this snapshot; the strings do
**not** appear in `srdicko/` or `borny/`, nor in any other file (read API, write
plugins, exec, mutations). So the entire attack surface for the burned keys is the
one diagnostic mu-plugin.

**Kill confirmation checklist (per site):**
- [ ] `hbook-diagnostic.php` is deleted from `wp-content/mu-plugins/` on **every**
  site (per memory, removed from live slunicko + passage on 2026-11-01; this
  source snapshot predates that and still contains it — verify on-box it is gone
  on all four, not just the two).
- [ ] `GET /wp-json/resort-dashboard/v1/diagnostic?api_key=KSapi2026` (and the
  `CB`/`SR` variants) returns **404** on each resort (route unregistered).
- [ ] Re-run the grep against the **live** docroot of each site (not just this
  snapshot) to confirm no other plugin/snippet/`mu-plugin` references the three
  strings. Expected: zero matches outside a deleted `hbook-diagnostic.php`.
- [ ] Because a burned key may have **equalled or been derived from** a live
  shared secret, also complete §3 rotation of `HBAPI_SECRET` on each site —
  deleting the diagnostic plugin stops new exposure but does not invalidate a
  secret the key mirrored.

## 5. Post-migration verification — old credentials unusable

Run per resort after rotation; every check must pass before declaring the resort
migrated:

1. **Shared key rejected.** A request with the old `HBAPI_SECRET` as
   `Authorization: Bearer <old>` against a v2 route → `401 unauthorized` (it was
   never a v2 key). Against legacy routes after `HBAPI_SECRET` is rotated/retired →
   `401`/`403` or the namespace is gone.
2. **Burned keys rejected.** `…/diagnostic?api_key=KSapi2026|CBapi2026|SRapi2026`
   → `404` (plugin deleted) on all four sites.
3. **Query-string keys rejected on v2.** `?api_key=…`/`?key=…` on any scoped v2
   route → `403 forbidden` before any lookup (SECURITY §2).
4. **Scope enforced.** A `read` key on a write route → `403`; a short/unknown key
   → `401`.
5. **Each consumer on its own key.** `wp mha list-keys --resort=<r>` shows a
   `last_used` timestamp on each migrated consumer's key; `mha_audit` attributes
   recent calls to those key ids and **none** to a shared/legacy id.
6. **Revocation works.** Revoke a throwaway test key with
   `wp mha revoke-key <id>`; a follow-up call with it → `401`.
7. **Zero legacy traffic.** For the soak window, legacy-namespace access logs show
   no hits from migrated consumers (remaining hits, if any, identify a consumer
   not yet migrated — do not retire the namespace until zero).
