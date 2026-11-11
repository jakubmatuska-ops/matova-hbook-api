# Release signing (from 2.4.0) — managed, no key on any computer

A resort site installs a plugin update **only if `update.json` carries a valid
signature** made by the Matova release signer. Changing files on `main`
(ZIP, manifest) is not enough to ship code to the sites: only a tagged release
run can obtain a signature.

**Required repository setting:** protect `main` (only the owner or approved
pull requests). Whoever can push to `main` can release.

## How it works

- **Key:** Ed25519, generated inside the Matova OS backend on first use and
  stored only in the database Vault (encrypted at rest). It is never exported,
  never in GitHub, never on a laptop.
- **Who can sign:** only this repository's `release.yml` workflow, running on
  a pushed tag `v<version>`. The workflow proves that with GitHub's own
  short-lived OIDC token — there is **no signing secret in GitHub**.
- **What is signed:** `matova-hbook-api\n<version>\n<sha256 of the ZIP>` — an
  old signed ZIP cannot be replayed under a newer version number.
- **Where it is checked:** `MHA_Updater::verified_download`
  (`upgrader_pre_download`). No manifest, wrong package URL, no signature, bad
  signature, no trusted key or no libsodium ⇒ the update is aborted and the
  installed version stays.

## Releasing (fully automated)

1. Raise `MHA_VERSION` + the header version in `src/matova-hbook-api/` and
   merge to `main`. Nothing else is done by hand.
2. `release.yml` runs the tests, makes sure the source trusts the signer's
   public key (written automatically the first time), builds the ZIP, has it
   signed, publishes a GitHub Release and starts the staged rollout in
   `update.json` (`rollout.candidate`).
3. `rollout.yml` moves the release on, one small step per run:
   test property (Borný) → installed by the site's own verified installer →
   health checks → 2 h soak → Passage → Srdíčko → Sluníčko (each verified and
   soaked) → default for everyone.
4. A site installs only the release assigned to it (`rollout.by_site[host]`,
   else `rollout.default`), only if the signature verifies, and puts the
   previous version back by itself if a fresh request is not healthy afterwards.
5. Any failed install or failed verification halts the rollout, withdraws the
   release from sites not yet verified, rolls the failing site back and opens
   an issue. Fix forward with a new version, or run Rollout with `resume`.

Site checks and update triggers go through the Matova OS backend
(`plugin-rollout`), which holds each site's scoped API key. GitHub holds no
site credential and no signing key; it proves who it is with its OIDC token.

Sites on ≤ 2.3.x have no remote update: their first install of a staged build
is the normal "Update now" in WordPress; the rollout waits for it per site.

## Key rotation (automated, no site ever stranded)

1. `rotate` on the signer creates the NEXT key and returns its public key.
2. Ship one release with `PUBKEY_NEXT_B64` set to it (signed by the current key).
3. When every site runs that release, `promote` makes NEXT current; the next
   release moves it into `PUBKEY_B64`.

## Key handling

The key is created inside the Vault on the first release run; only its public
half is written to the source. `rotate` / `promote` are restricted to the
backend itself or a signed-in admin; `promote` keeps the previous key and
requires naming the release that shipped the next public key.

## One-time bootstrap

Sites on ≤ 2.3.3 have no verifier, so the 2.3.3 → 2.4.0 step itself installs
the old way. Do it deliberately (Borný first); every later release is verified.
