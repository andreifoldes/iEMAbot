# ESMira participant PWA

A chat-style participant web interface for ESMira, modelled on the
[iEMAbot](https://iemabot.surrey.ac.uk/pwa) PWA (Material Design 3, Inter,
single-question-per-screen cards, animated progress, WhatsApp-style thread).

It is a **drop-in front end**: it talks only to ESMira's existing public API and
makes **no backend changes**.

- **Read** the study: `GET {root}api/studies.php?access_key=KEY`
- **Write** responses: `POST {root}api/datasets.php` — the same endpoint the
  native apps use; the body is the exact shape `QuestionnaireSaver::saveDataset()`
  produces, so `CreateDataSet` writes byte-identical CSV. The researcher
  dashboard, file storage, study format and admin/designer are untouched.

`{root}` is derived from the served base path (`/esmira/pwa/` → `/esmira/`), so it
works under any sub-path.

## What it supports

Question types (ESMira `responseType` → UI): `likert`, `list_single` (choice),
`list_multiple` (multi-choice), `binary` (yes/no), `text_input`, `number`,
`time`/`duration`, `date`, `va_scale`, and static `text` (rendered as an info
bubble — this is how cognitive link-outs appear). Pages become chat "sections"
(the page header is shown as a section divider); `randomized` pages are shuffled
per session. Submitted values match the stock web client exactly (likert `1..N`,
binary `0`/`1`, choice = label, multi = `name~i` booleans, time = `HH:MM`, etc.).

## Not included (ESMira web has no backend for these)

Push notifications, scheduled prompts, WebSocket delivery, wearable linking. The
flow is pull-driven: the participant opens the link and the "conversation" plays
out locally. Photo/voice capture is deferred (would use `api/file_uploads.php`).

## Develop

```bash
cd web-pwa
npm install
# Proxies /esmira/api/* to a running ESMira (default: the live instance).
# Point at a local container with ESMIRA_PROXY=http://localhost:8081
npm run dev
# open http://localhost:5174/esmira/pwa/?key=YOUR_ACCESS_KEY
```

## Build & deploy

The app builds into the repo's `dist/pwa/` so ESMira's Docker image
(`COPY ./dist /var/www/html`) serves it at `/esmira/pwa/`.

ESMira's webpack build cleans `dist/`, so the PWA must build **after** it. Use the
root script:

```bash
npm run build:all     # = npm run prod (ESMira) && npm run build:pwa (this app)
```

Then build/deploy the Docker image as usual. No Apache/.htaccess change is needed
for the path-based rollout — `/esmira/pwa/` is a static SPA directory.

### Rollout

1. **Phase A (safe):** ship behind the explicit path `/esmira/pwa/?key=KEY`. The
   stock Mithril participant flow at `/esmira/?key=KEY` is unchanged. Give
   participants the `/pwa/` link.
2. **Phase B (flip default):** once validated, branch participant URLs in
   `src/index.php` so `/esmira/?key=KEY` serves this app while `?admin` keeps the
   Mithril designer. (Not done yet — deliberate, so the live flow is undisturbed.)
