---
title: "Fork divergence from upstream"
sidebar_label: "Fork divergence"
description: "Everything this fork adds or changes relative to upstream ESMira-web, grouped by area, with the sync status against upstream."
---

import {ForkNumbers, ForkStat} from '@site/src/components/ForkStats';

# Fork divergence from upstream

This page is the map of *what is different* from
[`KL-Psychological-Methodology/ESMira-web`](https://github.com/KL-Psychological-Methodology/ESMira-web).
Each row links to the page that documents the feature.

## The numbers {/* #numbers */}

These figures are computed from git when the site is built, not typed in: the fork's own history, and a fresh
look at upstream's repository. The site is rebuilt on every change to the docs or the version, and weekly, so
they follow the repository. As of <ForkStat name="asOf" />:

<ForkNumbers />

Commit counts are left out on purpose: the repository history was condensed in October 2026, so they no longer
measure the work.

:::warning[The fork has not merged upstream since forking]
Upstream moved from `3.6.2` to <code><ForkStat name="upstreamVersion" /></code>. The upstream delta is small (<ForkStat name="upstreamFiles" /> files) but touches files the fork
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
| Voice memos (`record_audio`) with review and playback | <span className="status status--shipped">Shipped</span> | [Question types](./backend/question-types/index.md#voice-memos) |
| Keystroke-dynamics typing fallback (`record_keystrokes`) | <span className="status status--shipped">Shipped</span> | [Question types](./backend/question-types/index.md#keystroke-fallback) |
| "Other, please specify" for single choice | <span className="status status--shipped">Shipped</span> | [Question types](./backend/question-types/index.md#other-please-specify) |
| Relevance conditions mapped to show-if | <span className="status status--partial">Partial</span> (single comparison only) | [Question types](./backend/question-types/index.md#relevance-conditions) |
| Image questions and tap-to-enlarge lightbox | <span className="status status--shipped">Shipped</span> | [Question types](./backend/question-types/index.md#images-and-the-lightbox) |
| Embedded cognitive tasks (`webapp`) | <span className="status status--shipped">Shipped</span> | [Cognitive tasks](./backend/question-types/cognitive-tasks.md) |
| Practice (tutorial) mode | <span className="status status--shipped">Shipped</span> | [Participant flow](./pwa/participant-flow.md) |
| WCAG 2.2 automated audit, deploy gate | <span className="status status--shipped">Shipped</span> | [Accessibility](./pwa/accessibility.md) |

### Android app (Beta)

| Feature | Status | Docs |
| --- | --- | --- |
| Android app: the PWA in a Capacitor shell, joined by server address, invite link or QR code | <span className="status status--partial">Partial</span> Beta | [Android app](./backend/channels/android-app.md) |
| Reminders as on-device alarms (offline, on the minute) and server-driven nudges for wearable prompts | <span className="status status--partial">Partial</span> Beta | [Web push](./backend/channels/web-push.md) |
| Phone sensing: light, activity recognition, accelerometer windows, app usage | <span className="status status--partial">Partial</span> not verified on real devices | [Phone sensing](./backend/channels/phone-sensing.md) |
| Native keyboard timing for the typing fallback (`ime` rows) | <span className="status status--partial">Partial</span> Beta | [Question types](./backend/question-types/index.md#keystroke-fallback) |
| Offline copies of interactive cognitive tasks | <span className="status status--shipped">Shipped</span> | [Cognitive tasks](./backend/question-types/cognitive-tasks.md) |
| App build shipped with every deploy, in-app update prompt, private F-Droid repository | <span className="status status--shipped">Shipped</span> | [CI and release](./deployment/ci-and-release.md#android-lockstep) |
| Endpoints `native_pending.php`, `phone_sensing.php`, `app_version.php` | <span className="status status--shipped">Shipped</span> | [API reference](./backend/api-reference.md) |

### Backend and API

| Feature | Status | Docs |
| --- | --- | --- |
| One schedule engine and config for every study and channel (3.7.0): check-ins, windows, fixed / random / wake-up / wearable prompts | <span className="status status--shipped">Shipped</span> | [Scheduling](./backend/scheduling.md) |
| Web Push with VAPID: subscribe, schedule, send, analytics | <span className="status status--shipped">Shipped</span> | [Web Push](./backend/channels/web-push.md) |
| Reminder suppression for already-completed surveys | <span className="status status--shipped">Shipped</span> | [Web Push](./backend/channels/web-push.md#suppressing-reminders) |
| Telegram delivery through a channel-adapter layer (one server-wide bot, Mini App handoff) | <span className="status status--partial">Partial</span> | [Chat channels](./backend/channels/chat-channels.md) |
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
| Build-gate-deploy pipeline (staging repo, self-hosted runner) | <span className="status status--shipped">Shipped</span> | [CI and release](./deployment/ci-and-release.md) |
| Accessibility workflow | <span className="status status--shipped">Shipped</span> | [CI and release](./deployment/ci-and-release.md) |
| Security audit workflow | <span className="status status--shipped">Shipped</span> | [Security audit](./deployment/security-audit.md) |

## Where the repo's own docs stand

- This site is the reference. `web-pwa/README.md` is a short build-and-run guide that points here.
- The root `README.md` describes the fork. `CHANGELOG.md` is the fork's running list of changes (under *Added*
  and *Changed*, without per-version headings); the exact history is in `git log`.

## Inherited, unchanged

Everything not listed above is upstream ESMira: the data model, the designer, statistics and charts,
accounts and permissions, the `datasets.php` ingest endpoint, rewards, messaging, the plugin API and the
fallback-server system. The [backend overview](./backend/overview.md) summarises these basics.
