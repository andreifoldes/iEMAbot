---
title: "Fork divergence from upstream"
sidebar_label: "Fork divergence"
description: "Everything this fork adds or changes relative to upstream ESMira-web, grouped by area, with the sync status against upstream."
---

# Fork divergence from upstream

This page is the map of *what is different* from
[`KL-Psychological-Methodology/ESMira-web`](https://github.com/KL-Psychological-Methodology/ESMira-web).
Each row links to the page that documents the feature.

## The numbers (as of 2026-10-06)

| Measure | Value |
| --- | --- |
| Fork base (last common upstream commit) | upstream `3.6.2`, 2026-06-05 |
| Fork commits since base | 136 (59 of them `feat`) |
| First / latest fork commit | 2026-06-17 / 2026-09-08 |
| Size of the change | about 163 files, +29.6k / −0.5k lines |
| Fork version at this date | `3.6.16` |
| Upstream at this date | `3.7.2` (24 commits ahead of the fork base) |

:::warning[The fork has not merged upstream since forking]
Upstream moved from `3.6.2` to `3.7.2`. The upstream delta is small (about 17 files) but touches files the fork
also edits (`Study.ts`, `Questionnaire.ts`, `qEdit.tsx`, `filterTrigger.tsx`, `triggerEdit.tsx`,
`inputEdit.tsx`, locales), so expect merge conflicts.
Upstream changes the fork **lacks**: reward-amount calculation with currency setting, AM/PM time-input
fixes, chart-variable cleanup when deleting inputs, and some translation fixes.
:::

## What the fork adds

### Participant PWA (entirely new)

Upstream's participant UI is a Mithril web client with no scheduling. The fork adds a separate React app in
`web-pwa/`, served at `/pwa/`.

| Feature | Status | Docs |
| --- | --- | --- |
| Installable chat-style PWA with offline queue | <span className="status status--shipped">Shipped</span> | [PWA overview](./pwa/overview.md) |
| Install-first invite funnel, browser-aware install help | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md) |
| Smartphone-only studies: study-editor setting, QR invite page with enlarge | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md#smartphone-only-studies) |
| Participant ID next to the invite code, so iOS participants can enrol with the details from their link | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md) |
| Availability engine (windows, once-per-day, per-notification) | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md#availability) |
| Voice memos (`record_audio`) with review and playback | <span className="status status--shipped">Shipped</span> | [Question types](./pwa/question-types.md#voice-memos) |
| Keystroke-dynamics typing fallback (`record_keystrokes`) | <span className="status status--shipped">Shipped</span> | [Question types](./pwa/question-types.md#keystroke-fallback) |
| "Other, please specify" for single choice | <span className="status status--shipped">Shipped</span> | [Question types](./pwa/question-types.md#other-please-specify) |
| Relevance conditions mapped to show-if | <span className="status status--partial">Partial</span> (single comparison only) | [Question types](./pwa/question-types.md#relevance-conditions) |
| Image questions and tap-to-enlarge lightbox | <span className="status status--shipped">Shipped</span> | [Question types](./pwa/question-types.md#images-and-the-lightbox) |
| Embedded cognitive tasks (`webapp`) | <span className="status status--shipped">Shipped</span> | [Cognitive tasks](./pwa/cognitive-tasks.md) |
| Practice (tutorial) mode | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md) |
| WCAG 2.2 automated audit, deploy gate | <span className="status status--shipped">Shipped</span> | [Accessibility](./pwa/accessibility.md) |

### Backend and API

| Feature | Status | Docs |
| --- | --- | --- |
| Web Push with VAPID: subscribe, schedule, send, analytics | <span className="status status--shipped">Shipped</span> | [Web Push](./backend/web-push.md) |
| Reminder suppression for already-completed surveys | <span className="status status--shipped">Shipped</span> | [Web Push](./backend/web-push.md#suppressing-reminders) |
| Telegram delivery through a channel-adapter layer (one server-wide bot, Mini App handoff) | <span className="status status--partial">Partial</span> | [Chat channels](./backend/chat-channels.md) |
| Server statistics, and statistics of studies without an access key, need a researcher login | <span className="status status--shipped">Shipped</span> | This page |
| Wearables: Withings and Google Health over OAuth 2.0 | <span className="status status--shipped">Shipped</span> | [Wearables](./backend/wearables.md) |
| New endpoints: `push_*`, `wearables_*`, `client_info` | <span className="status status--shipped">Shipped</span> | [API reference](./backend/api-reference.md) |
| New response type columns for keystroke capture | <span className="status status--shipped">Shipped</span> | [Study model](./backend/study-model.md) |
| `Keystrokes` upload type in `file_uploads.php` | <span className="status status--shipped">Shipped</span> | [API reference](./backend/api-reference.md) |
| Server config: `webOnlyMode` | <span className="status status--shipped">Shipped</span> | [Study model](./backend/study-model.md#web-only-mode) |
| `SERVER_VERSION` raised from 11 to 12 | <span className="status status--shipped">Shipped</span> | [API reference](./backend/api-reference.md) |

### Researcher designer (admin UI)

| Feature | Status |
| --- | --- |
| Push panel: subscribers, installed vs browser, sender heartbeat, delivery funnel, per-participant test | <span className="status status--shipped">Shipped</span> |
| Wearables panel: enable, choose providers, connected counts, redirect URI, `wearables.zip` download | <span className="status status--shipped">Shipped</span> |
| Questionnaire filters: once-per-day, include deadline in notification, change-response mode | <span className="status status--shipped">Shipped</span> |
| Per-question "Explanation" field | <span className="status status--shipped">Shipped</span> |
| Study artwork (generated or uploaded) on the invite page | <span className="status status--shipped">Shipped</span> |
| Tutorial mode, personal-chart options, "Reset study" button | <span className="status status--shipped">Shipped</span> |
| iEMAbot branding on invite pages and header | <span className="status status--shipped">Shipped</span> |

### Operations

| Feature | Status | Docs |
| --- | --- | --- |
| Dockerfile with PHP extensions, Composer, cron jobs | <span className="status status--shipped">Shipped</span> | [Docker and cron](./deployment/docker-and-cron.md) |
| `deploy.sh` / `ship.sh` build-gate-deploy flow | <span className="status status--shipped">Shipped</span> | [CI and release](./deployment/ci-and-release.md) |
| Accessibility workflow | <span className="status status--shipped">Shipped</span> | [CI and release](./deployment/ci-and-release.md) |
| Security audit workflow | <span className="status status--shipped">Shipped</span> | [Security audit](./deployment/security-audit.md) |

## Known documentation drift in the repo

- `web-pwa/README.md` is **out of date**. It still says the PWA makes "no backend changes" and lists push,
  scheduled prompts, wearables and voice as not included. All of those now exist. This site supersedes it.
- The root `README.md` and `CHANGELOG.md` are still upstream's. The fork does not maintain a changelog;
  its history is only in `git log`.

## Inherited, unchanged

Everything not listed above is upstream ESMira: the data model, the designer, statistics and charts,
accounts and permissions, the `datasets.php` ingest endpoint, rewards, messaging, the plugin API and the
fallback-server system. The [backend overview](./backend/overview.md) summarises these basics.
