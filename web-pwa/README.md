# iEMAbot participant PWA

The participant web app for iEMAbot, a fork of ESMira. It is a chat-style interface (Material Design 3, Inter,
one question card at a time, animated progress, WhatsApp-style thread) built with React 19, Vite and Tailwind 4,
installable as a PWA. The same code is also bundled into the Android app (`android-app/`).

**Full documentation is on the project site:** <https://andreifoldes.github.io/iEMAbot/>. Start with the
[PWA overview](https://andreifoldes.github.io/iEMAbot/pwa/overview). This file only covers building and
running the app.

## How it talks to the server

The app uses ESMira's public API plus the endpoints this fork added to the server (`src/api/`):

- **Read** the study: `GET /esmira/api/studies.php?access_key=KEY`.
- **Write** responses: `POST /esmira/api/datasets.php`, the endpoint the native ESMira apps use, so the server
  writes the same data as for any other client.
- **Fork endpoints** for web push, the schedule engine, wearable linking, participant messages, client
  telemetry and file uploads (voice memos, keystroke logs).

The API root is pinned to `/esmira/` (override at build time with `VITE_API_ROOT`; the Android bundle takes
it as a runtime setting). The app itself is served at `/pwa/`.

## What it supports

Every ESMira question type that works on the web, plus voice memos with a typing fallback, "other, please
specify", relevance conditions, image lightbox and embedded cognitive tasks. Reminders use Web Push, scheduled
from the study's schedule, and the app handles wearable linking and offline use (queued uploads, cached
studies and tasks). The researcher-facing reference is
[Question types](https://andreifoldes.github.io/iEMAbot/backend/question-types).

Not rendered: `photo` and `video` items, and the native-only sensor types.

## Develop

```bash
cd web-pwa
npm install
# Proxies /esmira/api/* to a running ESMira (default: the live instance).
# Point at a local container with ESMIRA_PROXY=http://localhost:8081
npm run dev
# open http://localhost:5174/pwa/?key=YOUR_ACCESS_KEY
```

| Command | What it does |
| --- | --- |
| `npm test` | Unit tests (Node's built-in runner) |
| `npm run lint` | Type check for the app and the service worker |
| `npm run build` | Production build into `../dist/pwa/` |
| `npx vite build --mode native` | The bundle shipped inside the Android app |

## Build and deploy

The app builds into the repo's `dist/pwa/`, and the Docker image copies `dist/` into the web root. ESMira's
webpack build cleans `dist/`, so the PWA must build **after** it. Use the root script:

```bash
npm run build:all     # = npm run prod (ESMira) && npm run build:pwa (this app)
```

Builds and deploys run through CI. See
[CI and release](https://andreifoldes.github.io/iEMAbot/deployment/ci-and-release).
