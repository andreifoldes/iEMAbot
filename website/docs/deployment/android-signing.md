---
title: "Android signing secrets and pinned builds"
sidebar_label: "Signing secrets and pinned builds"
description: "Where the Android signing keys live (a protected GitHub environment), how the build inputs are pinned, and how to bump them."
---

# Android signing secrets and pinned builds

Two parts of the build are set up so that a compromised branch, package or download cannot quietly change what is deployed.

## Signing keys live in the `android-signing` environment

The Android app is signed with a key kept in GitHub secrets (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`,
`ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`; and optionally `FDROID_KEYSTORE_BASE64`, `FDROID_KEYSTORE_PASSWORD`,
`FDROID_KEY_ALIAS`, `FDROID_KEY_PASSWORD` for the F-Droid repository). Repository-level secrets can be read by any workflow
run in the repository, including a workflow file on a branch that someone (or an automated session) has just edited. A
**GitHub environment** with a branch rule closes that gap: its secrets are only handed to a job that names the
environment, and only when the run is on an allowed branch.

What the workflows do:

- **Pull requests, branch builds and debug runs** (`android.yml`) never reference the secrets. They produce a debug APK signed
  with a throwaway key made on the runner. No environment is used.
- **Release builds** (every deploy, and a manual release run) run in the `android-signing` environment.
- The **F-Droid repository job** of `deploy.yml` also runs in `android-signing`, because it signs with the same keys.
- A release build without a key is unsigned. A deploy refuses to continue in that case (an app that cannot be installed
  must not be served); a manual release run only warns.

### Current key (rotated 2026-11-06)

The setup below is **done** on the staging repository. The original key's passwords were not recorded anywhere that could be read back
(GitHub never shows secret values), so on 2026-11-06 a **new** signing key was generated and put straight into the environment; the
repository-level copies were deleted. Public facts, so installs and F-Droid clients can be checked against them:

- Key alias `iemabot`, RSA 4096, valid until March 2054, `CN=iEMAbot, O=iEMAbot research app`.
- Certificate SHA-256: `1C:66:40:18:D5:71:AF:5E:F9:F6:7E:D1:05:22:72:64:7C:55:96:97:DF:4B:3B:B5:E8:EF:12:CC:13:BD:D7:3D`
  (check an APK with `apksigner verify --print-certs <apk>`).
- Because the key changed, an Android install signed with the old key cannot be updated in place (uninstall first), and an F-Droid
  client has to add the repository again (its fingerprint is the app key's unless `FDROID_*` secrets are set).
- **Keep a copy of the keystore and its passwords outside GitHub** (a password manager or an encrypted backup). If both are lost the
  key can only be replaced, with the same consequences as above.

### One-time setup (repository owner)

These need repository admin rights and cannot be done from a workflow. Until they are done everything keeps working with
the repository-level secrets, so there is no rush and no outage risk, but the protection only starts once step 4 is done.

1. GitHub, repository **Settings > Environments > New environment**, name it exactly `android-signing`. (GitHub also creates an
   empty one by itself the first time a deploy runs.)
2. In that environment: **Deployment branches and tags > Selected branches and tags**, add the branch `main`. No required
   reviewers are needed (the deploy already waits on the `production` environment).
3. In the same environment, **Environment secrets**, add the eight secrets above (the four `FDROID_*` ones only if they are in use).
4. Delete the same secrets from **Settings > Secrets and variables > Actions** (repository secrets).
5. Check it: run **Deploy to VPS** from the Actions tab on `main` (or merge the next change). The `apk` and `fdroid` jobs
   must be green, and the F-Droid summary must show the same key fingerprint as before. If `apk` fails with
   "ANDROID_KEYSTORE_BASE64 is not set", the environment secrets are not reaching the called workflow: add the repository
   secrets back and report it.
6. Check the negative case once: run **Android app (APK)** manually from a branch other than `main` with build type
   `release`. The job must be refused by the environment's branch rule. A `debug` run from the branch must still work.

Keep a copy of the keystore outside GitHub. The key cannot be read back from a secret, and a new key means every installed
app must be uninstalled first (Android refuses an update signed with a different key).

## Pinned build inputs

The server image is built on the VPS from `Dockerfile`. Everything it downloads is pinned:

| Input | Pinned as |
| --- | --- |
| Dockerfile syntax frontend | `docker/dockerfile:1.27@sha256:...` |
| Base image | `php:8.3.36-apache@sha256:...` |
| `install-php-extensions` | release `2.13.2`, `ADD --checksum=sha256:...` (the build fails if the file differs) |
| Composer binary | `composer:2.11.1@sha256:...` |
| PHP packages | `src/backend/composer.lock`, installed with `composer install` (never `update`) |
| Accessibility tool | `web-pwa/a11y/package-lock.json`, installed with `npm ci --ignore-scripts`; Chromium fetched by a separate, explicit step |

The deploy workflow also packs the build context **before** the accessibility tool is installed, so installing it cannot
change the files that are shipped.

### Bumping

- **Base image:** `docker buildx imagetools inspect php:<tag>` and copy the top-level `Digest:` next to the tag. Rebuild and run the container smoke test (`php -m`, the start page).
- **Installer:** pick the release on the project's GitHub releases page, download the asset, compute `sha256sum`, and compare it with the digest GitHub shows for that asset before pasting it.
- **Composer image:** same as the base image.
- **PHP packages:** edit `src/backend/composer.json`, then run `composer update` with the same PHP version and extensions as the image (run it inside a container built from the Dockerfile; a newer host PHP can resolve versions that need a newer PHP than production has), and commit `composer.lock`. Run `composer audit` afterwards.
- **Accessibility tool:** `npm install <pkg>@<version>` in `web-pwa/a11y`, commit `package-lock.json`.
