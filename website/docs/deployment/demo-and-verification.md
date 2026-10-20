---
title: "Demo and container verification"
sidebar_label: "Demo and verification"
description: "One command that builds the fork's image, creates an admin and a demo study, and proves both the participant PWA and the researcher dashboard work, locally, on a cloud container instance, and in CI."
---

# Demo and container verification

<span className="status status--shipped">Shipped</span>

A fresh container is not a working study: it starts at the first-run screen with no admin and no studies. The
`scripts/demo/` toolkit closes that gap and doubles as the check that the image is correctly built.

```bash
scripts/demo/demo-up.sh          # build, start, bootstrap, test  ->  http://localhost:8080
scripts/demo/demo-up.sh --down   # stop and delete containers AND volumes
```

You need Docker with the Compose plugin. `dist/` is built automatically when missing (`DEMO_REBUILD=1` forces a
rebuild). The admin password is random and printed once; the demo study's invite code is `demo`.

## What is in the toolkit

| File | Purpose |
| --- | --- |
| `docker-compose.demo.yml` | Builds **this repository's** image (the root `docker-compose.yml` pulls upstream's) and sets `ESMIRA_PATH_ALIAS=/esmira`. |
| `demo/demo-study.json` | A one-questionnaire demo study (a 5-point mood item and an optional free-text note). |
| `scripts/demo/bootstrap.sh` | Creates the admin and imports the study through the real HTTP API. Safe to re-run. |
| `scripts/demo/smoke.sh` | Black-box server checks (curl). |
| `scripts/demo/ui-smoke.py` | Headless-browser test of **both** front-ends (Playwright). |
| `scripts/demo/azure-verify.sh` | Clean-room run on a throwaway cloud container instance. |

## What the bootstrap does

1. `InitESMira` creates the admin account (skipped if the server is already initialised).
2. Logs in, then calls `SaveStudy` with the demo study: the same path the admin UI uses, so access-key indexes,
   statistics metadata and the SQLite store are built exactly as for a real study.
3. Stamps `serverVersion` (from the live server) and `packageVersion` (from `package.json`) into the study at
   import time. Without them the researcher dashboard treats the study as a legacy v2 study, runs its migrations
   and crashes on load. They are stamped rather than stored so the fixture never goes stale.

## What is checked

**Server checks (`smoke.sh`)**: the admin UI and the PWA are served; the service worker file exists; the demo
study is listed by access key, both at `/api/` and at `/esmira/api/` (the path the PWA uses); a response is
accepted by `datasets.php`. Locally it also checks that cron is running, both cron files are installed, the
response reached the study CSV, and the SQLite store has rows.

**Browser checks (`ui-smoke.py`)**:

| Front-end | Journey |
| --- | --- |
| Participant PWA | invite code, consent, name, pick the questionnaire, answer, "recorded", and the server **accepts** the upload |
| Researcher dashboard | log in, the study is listed, the data page lists the questionnaire, and the response CSV contains the participant's answer |

Both pages must also finish with no console errors. The curl checks alone cannot see a study the dashboard fails
to parse or a PWA that cannot reach its API; the browser test found both while this toolkit was being built.

## Gotchas this toolkit encodes

- **API prefix.** The participant PWA calls `/esmira/api/…` (build-time `VITE_API_ROOT`, default `/esmira/`).
  Production gets that prefix from the reverse proxy. A bare container only serves `/api/…`, so the PWA could not
  look up a study. Setting `ESMIRA_PATH_ALIAS=/esmira` makes the entrypoint add an Apache `Alias` for the prefix.
  It is opt-in and changes nothing when unset.
- **Standalone only.** The PWA hides invite-code entry in a plain browser tab until it is installed
  ([participant flow](../pwa/participant-flow.md)). The browser test emulates standalone display mode.
- **Upload rate limit.** The server rejects uploads from one participant that arrive in quick succession
  (`Too many requests in succession`), reported inside an HTTP 200 response, and the PWA still shows "recorded".
  The browser test paces its submit like a person and requires the server's own acceptance, not just a 200.

## Clean-room check on a cloud container instance

`scripts/demo/azure-verify.sh` proves the image works on a machine with none of your local state. It creates one
resource group, a Basic container registry and one container instance, runs `bootstrap.sh`, `smoke.sh` and
`ui-smoke.sh` against the public URL, and **deletes the resource group on exit** (`KEEP=1` keeps it).

- **Prerequisites:** a fresh `az login`, the `Microsoft.ContainerRegistry` and `Microsoft.ContainerInstance`
  providers registered, and a built `dist/`.
- **Region:** `AZ_LOCATION` (default `swedencentral`). Some subscriptions restrict deployment regions by policy;
  pick one that your policy allows.
- **Build:** it tries `az acr build` first. Some subscription types block registry build tasks, so it falls back
  to a local `docker buildx` build **forced to `linux/amd64`** and a push (an image built on an ARM laptop will
  not start on x86 instances).
- **Transport:** the instance serves plain HTTP. That is enough for the checks above but not for service-worker
  registration or Web Push, which need HTTPS.

## CI

`.github/workflows/demo-smoke.yml` runs `demo-up.sh` with the browser test required. It is **manual only**: open the
Actions tab, choose "Container demo smoke test" and press Run workflow. It is slow (image build plus a browser
install), so it is deliberately not tied to pushes or releases. See [CI and release](./ci-and-release.md).

## Not covered

Installing the PWA to a home screen, push delivery, wearable provider OAuth, HTTPS behaviour, and anything that
needs real participants or provider credentials. The demo data is ephemeral: `--down` deletes the volumes.
