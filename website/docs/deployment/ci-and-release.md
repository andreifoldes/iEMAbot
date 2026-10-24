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
| `demo-smoke.yml` | manual only | fork | Builds the image, boots the demo stack and runs the server and browser smoke tests. See [Demo and verification](./demo-and-verification.md). |

The new workflows use their own concurrency groups (`docs-*`, `pages`, `security-audit-*`, `demo-smoke-*`) and never touch
`dist/` or `package.json`, so they cannot trigger an auto-tag.

## Deployment pipeline

All code changes go through a private staging repository; there are no manual deploy scripts. Merging to the
staging `main` branch triggers a workflow that:

1. **Builds** everything: `npm run build:all` (ESMira webpack, then the PWA).
2. **Accessibility gate:** the axe audit must pass.
3. **Deploys** from a self-hosted runner on the server: syncs the build, rebuilds and restarts the container
   (data and config volumes are untouched), generates VAPID keys if missing, and records the deployed revision.

Pull requests run the build and accessibility gate on GitHub-hosted runners only. A separate manual workflow
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
