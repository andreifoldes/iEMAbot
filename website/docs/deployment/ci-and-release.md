---
title: "CI and release"
sidebar_label: "CI and release"
description: "The GitHub Actions workflows (inherited and fork-added), the deploy script's gates, and how versions are bumped and released."
---

# CI and release

## Workflows

| Workflow | Trigger | Origin | Purpose |
| --- | --- | --- | --- |
| `auto_release.yml` | push to `main`, manual | upstream (arm64 edit) | Builds the legacy ESMira bundle (`npm run prod`), **auto-tags** `vX.Y.Z` from root `package.json`, zips `dist/`, creates a GitHub release, builds and pushes a multi-arch Docker image. Release and Docker steps run only when a **new tag** is created and need Docker Hub secrets. |
| `auto_prerelease.yml` | push to `develop` | upstream | Same, with the `pre.` tag prefix. The fork has no `develop` branch. |
| `update_parent.yml` | push to `main` | upstream | Bumps submodules in the upstream parent repository. **Fails on every fork push** because the required secret does not exist; harmless but noisy. |
| `accessibility.yml` | release published, manual | fork | WCAG 2.2 axe audit of the PWA. See [Accessibility](../pwa/accessibility.md). |
| `docs.yml` | `website/**` changes, manual | fork | Builds and deploys this site to GitHub Pages; scans the built HTML for private details. |
| `security-audit.yml` | push, pull request, weekly, manual | fork | gitleaks plus the private-detail scanner. See [Security audit](./security-audit.md). |
| `android.yml` | pull request touching the app or PWA, deploy, manual | fork | Builds the Android app (`android-app/scripts/build.sh`): a debug build on pull requests, a signed release on every deploy (served by the server, and as an F-Droid repository, see below), and on demand (a release run is also published on the Releases page). |
| `demo-smoke.yml` | manual only | fork | Builds the image, boots the demo stack and runs the server and browser smoke tests. See [Demo and verification](./demo-and-verification.md). |

The new workflows use their own concurrency groups (`docs-*`, `pages`, `security-audit-*`, `demo-smoke-*`) and never touch
`dist/` or `package.json`, so they cannot trigger an auto-tag.

## Deployment pipeline

All code changes go through a private staging repository; there are no manual deploy scripts. Merging to the
staging `main` branch triggers a workflow that:

1. **Builds** everything: `npm run build:all` (ESMira webpack, then the PWA).
2. **Accessibility gate:** the axe audit must pass.
3. **Builds the Android app** from the same commit, alongside steps 1 and 2 (`android.yml`, signed release; a
   missing signing key fails the deploy).
4. **Builds the F-Droid repository** from that APK (optional, see [below](#fdroid)).
5. **Deploys** from a self-hosted runner on the server: syncs the build, puts the app at `app/iemabot.apk` with
   `app/android.json` and the F-Droid repository at `fdroid/repo/`, rebuilds and restarts the container (data and config volumes are untouched), generates VAPID
   keys if missing, and records the deployed revision.

### The Android app and the server ship together {/* #android-lockstep */}

The Android app is a Capacitor wrapper around the PWA with its own native code (reminders, server nudges, phone
sensing), so it is a build of its own. Every deploy builds it from the deployed commit and the server serves it, so
there is always an app that matches the server. The app asks the server (`api/app_version.php`, which reads
`app/android.json`) on start and whenever it returns to the foreground; when the server's build is newer, it shows
*A new version of the app is available* with an **Update** button, which opens the download, and Android offers to
install it over the current app (the app's data stays). It also posts one notification per new build. It never
blocks: an older app keeps working (the server keeps accepting its uploads) until the participant updates.

Version codes are minutes since 1970 at build time, so every build, from a deploy or a manual run of `android.yml`,
installs over the ones before it. Every build must be signed with the same key (the `ANDROID_KEYSTORE_*` repository
secrets), or Android refuses the update. A manual release run of `android.yml` still publishes the APK on the
Releases page; deploy builds are not published there.

### F-Droid repository {/* #fdroid */}

Participants who would rather not sideload each APK can follow the app through F-Droid (or any F-Droid client, such as
Droid-ify or Neo Store). Every deploy also turns its APK into a one-app F-Droid repository
(`android-app/fdroid/build-repo.sh`, using `fdroidserver`), and the server serves it at `fdroid/repo/` next to
`app/`. A participant adds the repository once; from then on the client lists the app, installs it, and offers each
deploy as an update. It is the same signed APK the app's own update banner offers, so the two never conflict and a
participant can switch between them without reinstalling.

- **Adding it on a phone:** open `https://<server>/esmira/fdroid/repo/` in the phone's browser and tap the link (or
  scan its QR code from another screen). The link carries the signing-key fingerprint, so the client can check the
  repository. The deploy run's summary shows the same link.
- **Listing:** name, summary and description come from `android-app/fdroid/metadata/org.iemabot.app.yml`; the icon
  is the PWA's 512 px icon (`web-pwa/public/pwa-512x512.png`, the launcher icon's artwork), added by `build-repo.sh`
  because F-Droid extracts no icon from the release APK. The listing carries the *NonFreeDep* anti-feature for
  Google Play services (activity recognition).
- **Only the current build** is in the repository; it is rebuilt from scratch on every deploy.
- **Signing key:** the repository index is signed with the `FDROID_KEYSTORE_BASE64`, `FDROID_KEYSTORE_PASSWORD`,
  `FDROID_KEY_ALIAS` and `FDROID_KEY_PASSWORD` secrets when they are set, and with the app's signing key
  (`ANDROID_KEYSTORE_*`) otherwise. Clients pin the key's fingerprint when the repository is added, so choose one
  before handing out the link: switching later means everyone who added it removes and re-adds the repository.
  Without any key the step is skipped with a warning.
- **URL:** `https://iemabot.surrey.ac.uk/esmira/fdroid/repo` unless the `FDROID_REPO_URL` repository variable says
  otherwise (it must end in `/repo` and match where the server really serves it, since the index records it).
- **Never blocks the deploy:** if the repository build fails, the deploy goes ahead and that release serves no
  repository; the next deploy brings it back. Pull requests that touch the app build a throwaway repository from
  their debug APK (`android.yml`), so broken metadata shows up before merge.

Pull requests run the build on GitHub-hosted runners only. The accessibility gate is **opt-in on pull
requests** to keep checks fast: add the `a11y` label to the PR (it takes effect on the next push), or run *CI
(staging)* from the Actions tab with the *a11y* box ticked. It still runs on every deploy, so a WCAG failure that
skipped the PR check blocks the deploy after merge rather than the merge itself. Label PRs that change the
participant PWA's interface. A separate manual workflow
promotes a reviewed snapshot of staging to this public repository after a private-detail scan.

The host, remote directory and credentials are configured outside the public docs; they are deliberately not
described here.

## Versioning

- The root `package.json` version is the release version (`3.6.16` at 2026-10-06), shown on the PWA's About
  screen.
- Patch bumps are made in the pull request that ships the change (14 `chore(release)` commits from `v3.6.3` to `v3.6.16`).
  Minor and major bumps are manual.
- A push to `main` that changes the version makes `auto_release.yml` create the tag and release.
- `CHANGELOG.md` is upstream's stub and is **not maintained** by the fork. Release history lives in
  `git log`.

## Conventions

Commits follow Conventional Commits (`feat`, `fix`, `docs`, `chore`, `test`, with scopes such as `pwa`,
`push`, `study`, `invite`, `deploy`).

## Tests

| Suite | Command |
| --- | --- |
| PWA unit tests (availability, relevance, keystrokes, other-specify) | `cd web-pwa && npm test` |
| PWA type check | `cd web-pwa && npm run lint` |
| Accessibility audit | `npm run a11y` |
| Security scanner rules | `node --test scripts/security/scan-private-details.test.mjs` |
| Docs build | `cd website && npm run build` |
| Backend (PHP) | `test/` (not covered by CI) |

<span className="status status--tbc">TBC</span> The PWA service worker, `esmiraApi.ts` and UI components have
no automated tests, and the fork's backend additions have no PHP tests.
