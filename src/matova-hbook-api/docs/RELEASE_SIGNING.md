# Release signing (from 2.4.0) — managed, no key on any computer

A resort site installs a plugin update **only if `update.json` carries a valid
signature** made by the Matova release signer. Changing files on `main`
(ZIP, manifest) is not enough to ship code to the sites: only a tagged release
run can obtain a signature.

**Required repository setting:** a tag ruleset that lets only the owner create
`v*` tags. Without it, anyone who can push a tag could run a modified release
workflow and get it signed.

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

1. Bump `MHA_VERSION` + the header version in `src/matova-hbook-api/`, merge.
2. Push tag `v<version>`.
3. `release.yml` runs the tests, checks that the public key in the source is
   the signer's key, builds the ZIP, asks the signer for a signature, and
   stages ZIP + `update.json` as a GitHub Release. Sites do not see it yet.
4. Install the staged ZIP on the test property (Borný) and check `/meta`.
5. Run the **Promote release** workflow with the version. It re-verifies the
   signature and commits ZIP + manifest to `main`.
6. Sites pick the update up on their next check and verify before installing.

## Key rotation (automated, no site ever stranded)

1. `rotate` on the signer creates the NEXT key and returns its public key.
2. Ship one release with `PUBKEY_NEXT_B64` set to it (signed by the current key).
3. When every site runs that release, `promote` makes NEXT current; the next
   release moves it into `PUBKEY_B64`.

## Creating the key (one time, automated)

Run the **Bootstrap signing key** workflow. It asks the signer to create the
key inside the Vault (if none exists) and opens a pull request that puts the
PUBLIC key into the source. No secret is shown, stored or copied anywhere.
`rotate` / `promote` are restricted to the backend itself or a signed-in admin;
`promote` keeps the previous key and requires naming the release that shipped
the next public key.

## One-time bootstrap

Sites on ≤ 2.3.3 have no verifier, so the 2.3.3 → 2.4.0 step itself installs
the old way. Do it deliberately (Borný first); every later release is verified.
