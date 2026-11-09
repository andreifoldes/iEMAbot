---
title: "Current status"
sidebar_label: "Current status"
description: "A claim-by-claim status of every feature promised for iEMAbot against what the code does today."
---

# Current status

This page takes each promise made for iEMAbot and states, from the code, whether it is
<span className="status status--shipped">Shipped</span>, <span className="status status--partial">Partial</span> or
<span className="status status--tbc">TBC</span>. It was written by reading the repository.

:::caution[Read this before citing these features]
Several statements below describe intended capabilities (WhatsApp/SMS delivery, event-contingent execution
for ESMira's built-in cues) that **do not exist in the code yet**. They are listed here as TBC on purpose. Telegram delivery exists but has not yet been used in a live study.
Wearable webhook triggers exist in code but have only been tried with a single Withings test participant. ESMira no longer supports Fitbit directly, because Fitbit's legacy Web API is turned off on 2026-10-30; Fitbit and Pixel Watch data come through Google Health, whose webhook path has not been verified against live Google traffic.
:::

## Summary table

| # | Claim | Status | What the code does today |
| --- | --- | --- | --- |
| 1 | Open-source, low-cost, user-friendly alternative | <span className="status status--shipped">Shipped</span> | AGPL-3.0 code; self-hosted. Cost figure: see row 7. |
| 2 | Flexible delivery via a **custom web-based interface** | <span className="status status--shipped">Shipped</span> | The installable participant PWA, see [PWA overview](./pwa/overview.md). |
| 3 | Delivery via the **Telegram Bot API** | <span className="status status--partial">Partial</span> | One server-wide bot; studies run as conversations with every question type mapped to chat or to a Mini App handoff; data saved with `appType` Telegram. Covered by unit and end-to-end tests against a fake Bot API, **not yet run with a live bot in a study**. See [Chat channels](./backend/channels/chat-channels.md). |
| 4 | Platform-agnostic architecture, extensible to **WhatsApp / SMS** | <span className="status status--partial">Partial</span> | Channel-adapter layer with capability profiles and a per-item fallback ladder; Telegram is the only adapter. No WhatsApp or SMS adapter exists yet. See [Adding another channel](./backend/channels/chat-channels.md#adding-another-channel). |
| 5 | Passive data from **Withings and Google Health (Fitbit / Pixel Watch)** via **OAuth 2.0** | <span className="status status--shipped">Shipped</span> | Full authorization-code flow, encrypted token store, hourly sync, CSV export. See [Wearables](./backend/wearables.md). |
| 6 | …used to **trigger or contextualize prompts in real time** | <span className="status status--partial">Partial</span> | **Trigger:** a check-in with a *wearable event* prompt fires when a Withings or Google Health webhook reports new sleep, activity, weight, blood-pressure or ECG data, with an optional time fallback. The webhook carries no measurement, so there is no value condition ("slept < 6 h") and no wake-detection logic beyond "new sleep data arrived". **Contextualize:** not done. Not yet verified against live provider accounts. See [Scheduling](./backend/scheduling.md#event-prompts) and [Wearables](./backend/wearables.md#webhooks-and-sensor-triggers). |
| 7 | Deployable on low-spec VPS for **under €10/month** | <span className="status status--shipped">Shipped</span> | An app container (PHP 8.5 + Apache) and a Caddy web server, with flat files plus an embedded SQLite file, no database server, and cron jobs inside the container. The minimum size (2 vCPU, 1 GB) costs about £7 a month at Azure's UK list price, excluding VAT, which is under €10. A simulated test of 200 simultaneous check-ins passed on it, but no real study has run at this size, and we recommend a larger size at about £28 a month, which is over €10. See [Getting started: for IT](./getting-started/for-it.md#requirements) and [Docker and cron](./deployment/docker-and-cron.md). |
| 8 | **Self-hosted**, full data control | <span className="status status--shipped">Shipped</span> | One command installs it: see [Self-host for your lab](./deployment/self-hosting.md). All data lives in a mounted volume on your server. Two third-party hops exist by design (browser push services; wearable provider APIs). See [Data and privacy](./backend/data-and-privacy.md). |
| 9 | **GDPR compliance** via encrypted channels | <span className="status status--partial">Partial</span> | Mechanisms exist (wearable tokens encrypted at rest; push payloads encrypted by the Web Push protocol; consent form; researcher export and study reset). HTTPS is expected from a reverse proxy but **not enforced in code**; plaintext secrets sit in the server config; compliance documentation and a DPIA are **in progress** and not yet published. See [Data and privacy](./backend/data-and-privacy.md). |
| 10 | Web-based **management interface** for protocols | <span className="status status--shipped">Shipped</span> | ESMira's designer, plus fork-added Push and Wearables panels. See [Study model](./backend/study-model.md). |
| 11 | Flexible **"if-this-then-that" scheduling**, including **event-contingent**, **adaptive** and **wake-up-triggered** designs | <span className="status status--partial">Partial</span> | **Wake-up-triggered** prompts are built: they fire when the participant gets up, from a wearable or their wake-up check-in, and fall back to their usual time or a fixed one. **Event-contingent** prompts from wearables are built, with a delay and a fallback, but have only been tried with a single Withings test participant. ESMira's built-in cue triggers (`joined`, `questionnaire`, …) can still be edited in the designer, but **nothing runs them**. **Adaptive** scheduling across prompts does not exist, so an answer cannot trigger a later prompt: answering a wake-up check-in re-plans the day, and show-if conditions branch only within a questionnaire. For time-based schedules, see row 12 and [Scheduling](./backend/scheduling.md). |
| 12 | **Time-contingent** EMA designs | <span className="status status--shipped">Shipped</span> | Daily/weekday/day-of-month schedules, reminders, completion windows. |
| 13 | **Multi-point daily sampling** for diurnal trajectories | <span className="status status--shipped">Shipped</span> | Multiple signal times per day, random windows with a minimum gap, availability windows. |
| 14 | Capture of **cognition** across the day | <span className="status status--shipped">Shipped</span> | Embedded cognitive tasks (iframe + `postMessage`). The task pages themselves are hosted outside this repo. See [Cognitive tasks](./backend/question-types/cognitive-tasks.md). |
| 15 | Engagement for **older / cognitively vulnerable** users | <span className="status status--partial">Partial</span> | Chat-style one-question-per-screen UI, text-size and contrast settings, image lightbox, automated WCAG 2.2 audit. No manual screen-reader pass and no usability study are documented. See [Accessibility](./pwa/accessibility.md). |
| 16 | **Dyadic EMA** (participant + caregiver) | <span className="status status--tbc">TBC</span> | No dyad/caregiver linking exists in the code. ESMira's random-group feature is unrelated. |
| 17 | **Openly documented**, designed for community extension | <span className="status status--partial">Partial</span> | This site is the first documentation, and the README has a [contributor guide](https://github.com/andreifoldes/iEMAbot#contributing-and-discussion). Upstream's plugin API is present, but the fork has no stable extension API of its own. |
| 18 | **Native Android app** | <span className="status status--partial">Partial</span> **Beta** | A [Capacitor](https://capacitorjs.com/) build of the participant app with its own native code: reminders planned as on-device alarms, server-driven nudges, optional phone sensing, native keyboard timing. Built and signed on every deploy and shared through a private F-Droid repository on request; not on Google Play or the main F-Droid catalogue. Tested on an emulator and in a closed Beta; **phone sensing is not yet verified on real devices**, and background reminders are not yet verified across phone manufacturers. See [Android app](./backend/channels/android-app.md). |
| 19 | **Voice responses** with optional audio-biomarker analysis | <span className="status status--shipped">Shipped</span> | `record_audio` records in the browser or the Android app with pause, review and redo, queues offline and uploads as `Audio` media; a typing fallback (`record_keystrokes`) covers participants who will not speak. **Biomarker extraction is not part of iEMAbot**: researchers run their own toolkit on the collected audio. See [Voice memos](./backend/question-types/index.md#voice-memos) and [Platform architecture](./architecture.md#with-voice-responses). |

## Detail on the partial and TBC rows

### Scheduling ("if-this-then-that")

ESMira models a *trigger* as `schedules` (times) or `eventTriggers` (cues such as "questionnaire
completed"), each with an action (invitation, message, notification). In this fork:

- <span className="status status--partial">Partial</span> Since 3.7.0 a study has **one schedule**
  (`Study.schedule`): check-ins with days, daily ranges (clock times, wake-up, usual wake-up, bedtime, split points),
  prompts (fixed, random, at wake-up, at a wearable event with a time fallback) and completion limits. One engine,
  `backend/schedule/Schedule.php` with its port `web-pwa/src/lib/schedule.ts`, decides what is open and what is
  prompted for web push, Telegram, the Android app and the app's own list. Older schedules and sensor triggers are
  upgraded into it. Wearable-event prompts are not yet tested against live providers.
- <span className="status status--tbc">TBC</span> **Other event triggers** (the built-in cues such as
  `joined` or `questionnaire`) are saved in the study JSON and editable in the designer, but no code in
  `src/backend`, `src/api`, `src/cli` or `web-pwa/src` fires them. Their execution is a behaviour of ESMira's
  *native* apps, which this repository does not contain.

Details and the exact cue list: [Scheduling](./backend/scheduling.md).

### Wearables

Measurement data still ends at storage: *provider → hourly sync → server CSV → researcher download*, a day
late. Prompts are driven by a separate, faster path: *provider webhook → `wearables_webhook.php` → sensor event
→ push sender*. That path only learns that new data of a category exists, never its value, so value
thresholds and real wake-detection would still need a data fetch on each event. See
[Wearables](./backend/wearables.md#webhooks-and-sensor-triggers).

### GDPR and encrypted channels

The project claims GDPR compliance "via encrypted channels". The honest reading of the code is:

| Mechanism | State |
| --- | --- |
| Data stays on the researcher's server | Yes (flat files and a SQLite database in a volume). |
| Transport encryption | Delegated to the reverse proxy / Apache SSL; **not enforced** by application code. |
| Push payload encryption | Yes, by the Web Push protocol (`minishlink/web-push`). |
| Wearable token encryption at rest | Yes (libsodium `secretbox`). Without the key or the extension no token is stored (older plaintext files are encrypted when read). |
| Provider client secrets / VAPID private key | Stored **in plaintext** in the server config file. |
| Informed consent | Yes, a study-level consent form is shown in the PWA. |
| Participant-initiated deletion of response data | **No.** Participants can disconnect wearables (collection stops, what was collected stays) and unsubscribe push; researchers can reset or delete a study. |
| DPIA, retention policy, processor agreements | <span className="status status--tbc">TBC</span> In progress; not yet published. |

Compliance is a property of how a study is run, not of the software alone. This project provides
building blocks; it does not make a legal claim. See [Data and privacy](./backend/data-and-privacy.md).
