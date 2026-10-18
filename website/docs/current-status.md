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
Several statements below describe intended capabilities (Telegram delivery, event-contingent execution
for ESMira's built-in cues) that **do not exist in the code yet**. They are listed here as TBC on purpose.
Wearable webhook triggers exist in code but have not been tested against live Withings or Fitbit accounts, and Fitbit's legacy Web API (which ESMira's Fitbit support uses) is scheduled to be turned off on 2026-10-30. A Google Health API trigger path exists only as an untested placeholder, because Google is not onboarding new projects.
:::

## Summary table

| # | Claim | Status | What the code does today |
| --- | --- | --- | --- |
| 1 | Open-source, low-cost, user-friendly alternative | <span className="status status--shipped">Shipped</span> | AGPL-3.0 code; self-hosted. Cost figure: see row 7. |
| 2 | Flexible delivery via a **custom web-based interface** | <span className="status status--shipped">Shipped</span> | The installable participant PWA, see [PWA overview](./pwa/overview.md). |
| 3 | Delivery via the **Telegram Bot API** | <span className="status status--tbc">TBC</span> | No Telegram code exists. |
| 4 | Platform-agnostic architecture, extensible to **WhatsApp / SMS** | <span className="status status--tbc">TBC</span> | No channel-adapter layer. Web Push is the only server-initiated channel. |
| 5 | Passive data from **Withings and Fitbit** via **OAuth 2.0** | <span className="status status--shipped">Shipped</span> | Full authorization-code flow, encrypted token store, hourly sync, CSV export. See [Wearables](./backend/wearables.md). |
| 6 | …used to **trigger or contextualize prompts in real time** | <span className="status status--partial">Partial</span> | **Trigger:** a designer *sensor trigger* fires a prompt when a Withings or Fitbit webhook reports new sleep, activity, weight, blood-pressure or ECG data, with an optional time fallback. The webhook carries no measurement, so there is no value condition ("slept < 6 h") and no wake-detection logic beyond "new sleep data arrived". **Contextualize:** not done. Not yet verified against live provider accounts. See [Scheduling](./backend/scheduling.md#sensor-triggers) and [Wearables](./backend/wearables.md#webhooks-and-sensor-triggers). |
| 7 | Deployable on low-spec VPS for **under €10/month** | <span className="status status--partial">Partial</span> | One Docker container (PHP 8.3 + Apache), flat files plus an embedded SQLite file, no database server, two cron jobs. No resource benchmarks or cost breakdown have been measured, so the € figure is unverified. See [Docker and cron](./deployment/docker-and-cron.md). |
| 8 | **Self-hosted**, full data control | <span className="status status--shipped">Shipped</span> | All data lives in a mounted volume on your server. Two third-party hops exist by design (browser push services; wearable provider APIs). See [Data and privacy](./backend/data-and-privacy.md). |
| 9 | **GDPR compliance** via encrypted channels | <span className="status status--partial">Partial</span> | Mechanisms exist (wearable tokens encrypted at rest; push payloads encrypted by the Web Push protocol; consent form; researcher export and study reset). HTTPS is expected from a reverse proxy but **not enforced in code**; plaintext secrets sit in the server config; compliance documentation and a DPIA are **in progress** and not yet published. See [Data and privacy](./backend/data-and-privacy.md). |
| 10 | Web-based **management interface** for protocols | <span className="status status--shipped">Shipped</span> | ESMira's designer, plus fork-added Push and Wearables panels. See [Study model](./backend/study-model.md). |
| 11 | Flexible **"if-this-then-that" scheduling**, including **event-contingent**, **adaptive** and **wake-up-triggered** designs | <span className="status status--partial">Partial</span> | Time-based schedules (fixed and random signal times) are executed, see row 12. **Sensor triggers** (wearable webhook → prompt, with a fallback at a fixed time or at a participant variable + offset such as habitual wake-up time, daily cap and delay) are configurable in the designer and executed by the push sender. ESMira's built-in cue triggers (`joined`, `questionnaire`, …) can be configured but are **not executed** by the server push or the PWA. There is no adaptive scheduling logic: per-question show-if conditions branch within a questionnaire, not across prompts. See [Scheduling](./backend/scheduling.md). |
| 12 | **Time-contingent** EMA designs | <span className="status status--shipped">Shipped</span> | Daily/weekday/day-of-month schedules, reminders, completion windows. |
| 13 | **Multi-point daily sampling** for diurnal trajectories | <span className="status status--shipped">Shipped</span> | Multiple signal times per day, random windows with a minimum gap, availability windows. |
| 14 | Capture of **cognition** across the day | <span className="status status--shipped">Shipped</span> | Embedded cognitive tasks (iframe + `postMessage`). The task pages themselves are hosted outside this repo. See [Cognitive tasks](./pwa/cognitive-tasks.md). |
| 15 | Engagement for **older / cognitively vulnerable** users | <span className="status status--partial">Partial</span> | Chat-style one-question-per-screen UI, text-size and contrast settings, image lightbox, automated WCAG 2.2 audit. No manual screen-reader pass and no usability study are documented. See [Accessibility](./pwa/accessibility.md). |
| 16 | **Dyadic EMA** (participant + caregiver) | <span className="status status--tbc">TBC</span> | No dyad/caregiver linking exists in the code. ESMira's random-group feature is unrelated. |
| 17 | **Openly documented**, designed for community extension | <span className="status status--partial">Partial</span> | This site is the first documentation. Upstream's plugin API is present; the fork has no contributor guide and no stable extension API of its own. |

## Detail on the partial and TBC rows

### Scheduling ("if-this-then-that")

ESMira models a *trigger* as `schedules` (times) or `eventTriggers` (cues such as "questionnaire
completed"), each with an action (invitation, message, notification). In this fork:

- <span className="status status--shipped">Shipped</span> **Schedules** are executed by
  `PushScheduler.php` (server Web Push) and mirrored client-side in `availability.ts`.
- <span className="status status--partial">Partial</span> **Sensor triggers** (cue `wearable_event`) are
  executed by `SensorTriggerScheduler.php` from provider webhooks, with a time-contingent fallback. Not yet
  tested against live providers. They reach participants through Web Push only.
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
| Wearable token encryption at rest | Yes (libsodium `secretbox`), with a **plaintext fallback** if the key or extension is missing. |
| Provider client secrets / VAPID private key | Stored **in plaintext** in the server config file. |
| Informed consent | Yes, a study-level consent form is shown in the PWA. |
| Participant-initiated deletion of response data | **No.** Participants can disconnect wearables and unsubscribe push; researchers can reset or delete a study. |
| DPIA, retention policy, processor agreements | <span className="status status--tbc">TBC</span> In progress; not yet published. |

Compliance is a property of how a study is run, not of the software alone. This project provides
building blocks; it does not make a legal claim. See [Data and privacy](./backend/data-and-privacy.md).
