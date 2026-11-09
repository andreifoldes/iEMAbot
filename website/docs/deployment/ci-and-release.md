---
title: "CI and release"
sidebar_label: "CI and release"
description: "The GitHub Actions workflows (inherited and fork-added), the deploy script's gates, and how versions are bumped and released."
---

import {ForkStat} from '@site/src/components/ForkStats';

# CI and release

## Workflows

| Workflow | Trigger | Origin | Purpose |
| --- | --- | --- | --- |
| `auto_release.yml` | push to `main`, manual | upstream (arm64 edit) | Upstream's release job: builds the legacy ESMira bundle, auto-tags `vX.Y.Z` from root `package.json`, creates a GitHub release and pushes a Docker image. **Guarded to run only in the upstream repository**, so on this fork it is skipped. |
| `auto_prerelease.yml` | push to `develop` | upstream | Same, with the `pre.` tag prefix. Skipped here by the same guard (and the fork has no `develop` branch). |
| `update_parent.yml` | push to `main` | upstream | Bumps submodules in the upstream parent repository. Skipped here by the same guard (before it was added, it failed on every fork push for lack of a secret). |
| `accessibility.yml` | release published, manual | fork | WCAG 2.2 axe audit of the PWA. See [Accessibility](../pwa/accessibility.md). |
| `docs.yml` | `website/**` changes, manual | fork | Builds and deploys this site to GitHub Pages; scans the built HTML for private details. |
| `security-audit.yml` | push, pull request, weekly, manual | fork | gitleaks plus the private-detail scanner. See [Security audit](./security-audit.md). |
| `android.yml` | pull request touching the app or PWA, deploy, manual | fork | Builds the Android app (`android-app/scripts/build.sh`): a debug build on pull requests, a signed release on every deploy (served by the server, and as an F-Droid repository, see below), and on demand (a release run is also published on the Releases page). |
| `demo-smoke.yml` | manual only | fork | Builds the image, boots the demo stack and runs the server and browser smoke tests. See [Demo and verification](./demo-and-verification.md). |
| `selfhost-smoke.yml` | pull request touching the [self-host kit](./self-hosting.md) or the image, manual | fork | Builds the image and takes the kit through install, a browser journey, backup and restore, update, and moving servers (`scripts/demo/selfhost-e2e.sh`). |
| `publish-image.yml` | release published, manual | fork | Runs that test, then publishes the image the kit runs. Skipped in a private repository. See [below](#publishing-the-image). |

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
5. **Deploys** from a self-hosted runner on the server. It first removes Docker build cache older than a week and
   untagged images (never volumes, tagged images or study data) and stops with a clear error if less than 4 GB of
   disk is free. Then it syncs the build, puts the app at `app/iemabot.apk` with
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
secrets, kept in the protected `android-signing` environment, see [Signing secrets and pinned builds](./android-signing.md)), or Android refuses the update. A manual release run of `android.yml` still publishes the APK on the
Releases page; deploy builds are not published there.

### F-Droid repository {/* #fdroid */}

Participants who would rather not sideload each APK can follow the app through F-Droid (or any F-Droid client, such as
Droid-ify or Neo Store). Every deploy also turns its APK into a one-app F-Droid repository
(`android-app/fdroid/build-repo.sh`, using `fdroidserver`), and the server serves it at `fdroid/repo/` next to
`app/`. A participant adds the repository once; from then on the client lists the app, installs it, and offers each
deploy as an update. It is the same signed APK the app's own update banner offers, so the two never conflict and a
participant can switch between them without reinstalling.

- **Adding it on a phone:** the study team gives participants and testers the repository link on request; it is
  not published on this site. Open the link in the phone's browser and tap it (or scan its QR code from another
  screen). The link carries the signing-key fingerprint, so the client can check the repository. For operators,
  the deploy run's summary shows the same link.
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
- **URL:** the repository is served under the server's own address at `fdroid/repo`, unless the `FDROID_REPO_URL`
  repository variable says otherwise (it must end in `/repo` and match where the server really serves it, since the
  index records it).
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

- The root `package.json` version is the release version (currently <code><ForkStat name="forkVersion" /></code>), shown on the PWA's About
  screen. The Android app's `versionName` follows it, and its `versionCode` is the build time in minutes (see
  [above](#android-lockstep)).
- Patch bumps are made in the pull request that ships the change. Minor and major bumps are manual.
- A version bump does not create a tag: upstream's `auto_release.yml` is skipped on the fork. Tags and GitHub
  releases on the public repository are made by hand.
- `CHANGELOG.md` is the fork's running list of changes (*Added* and *Changed*), added to as features land. It has
  no per-version headings, so the exact history is in `git log`.

## Publishing the image {/* #publishing-the-image */}

Labs running the [self-host kit](./self-hosting.md) install and update from `ghcr.io/andreifoldes/iemabot`. Publishing
a release on the public repository builds it for amd64 and arm64 from that release's source, after the kit's end-to-end
test passes, and tags it with the `package.json` version and, unless it is a pre-release, `latest`.

So a new version reaches labs in three steps: merge to staging, run *Promote to public repo*, publish a release there.
The release's tag is its label, a plain `vMAJOR.MINOR.PATCH` such as `v0.3.0`, and it need not equal `package.json`'s version
(the project's labels are below 1.0, its `package.json` versions are 3.x). The image is named after the version it contains,
so release `v0.3.0` built from `package.json` 3.7.45 is published as `iemabot:3.7.45`, and labs can pin it. A pre-release
tag such as `v0.3.0-rc1` is refused. `latest` moves only for the repository's latest release, so a pre-release or a release
from an older tag never moves labs.

To publish the image of a release that already exists, run *Publish image* by hand from the default branch and give the
release's tag; the source it tests and builds is that tag's, and it stops if the checked-out commit is not the one the tag
points at.

The dashboard's update notice compares the installed `package.json` version with release tags, so a release labelled below
the installed version does not trigger it. Labs that follow `latest` get the new image on their next `./update.sh`
regardless.

The kit's scripts ship inside the image (`/opt/iemabot-kit`), and `update.sh` takes them from there, so a lab's scripts and
image always belong together. Only a first install reads the kit from the public `main` branch.

**Only once, after the first image is published:** make the package public, under *Packages → iemabot → Package
settings → Change visibility* on GitHub. Packages start out private, and labs cannot pull a private one.

## Telling other labs about a release

A lab that runs its own copy learns about a new release in two ways:

- **In the dashboard.** The admin home page shows an *A new version is available* banner linking to **Server
  settings**, which lists the changelog. Both read the public repository's GitHub releases, so the banner only appears
  once a release has been published there, and it stays silent when GitHub cannot be reached. A server running from an
  image is told to run `./update.sh`: updating in place would be undone by the next container, on top of data the
  update had already migrated. Any other install counts only releases with a downloadable asset, and gets the one-click
  update.
- **By notification.** Labs can use *Watch → Custom → Releases* on the GitHub repository, or subscribe to
  `https://github.com/andreifoldes/iEMAbot/releases.atom` in a feed reader.

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
