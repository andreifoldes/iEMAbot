---
title: "Participant flow"
sidebar_label: "Participant flow"
description: "What a participant sees, from install and invite code through consent, notifications, practice, questionnaires and settings, plus the availability rules."
---

# Participant flow

<span className="status status--shipped">Shipped</span>

```mermaid
flowchart TD
  A[Open link] --> B{Installed as app?}
  B -- no --> C["Install funnel<br/>1 Install · 2 Open app · 3 Enter code"]
  B -- yes --> D[Enter invite code]
  A -->|"link contains ?key="| E
  C --> D --> E[Welcome + study description]
  E --> F[Consent]
  F --> G[Display name]
  G --> H{Web Push enabled?}
  H -- yes --> I["Notifications step<br/>+ welcome push check"]
  H -- no --> J
  I --> J{Tutorial?}
  J -- yes --> K[Practice runs]
  J --> L
  K --> L[Questionnaire list]
  L --> M[Survey: one card at a time]
  M --> N[Thank you / offline notice]
  N --> L
```

## Invite and install

An invite is a URL with `?key=` (or `access_key`). Without one, the app asks for a code.

**Install-first funnel.** In a plain browser tab the code box is **hidden** and a three-step panel is shown:
*Install the app → Open the installed app → Enter your invite code*. The input appears only in standalone
display mode. A `?key=` URL bypasses the gate. The point is to get participants onto the installed app,
where push works reliably.

`InstallPrompt.tsx` adapts to the browser, using the detection in `lib/pwaInstall.ts`:

| Situation | Guidance |
| --- | --- |
| Install prompt captured (Chromium) | A real **Install app** button. |
| Inside an in-app browser (WhatsApp, Instagram, Facebook, Mail…) | "Open this page in your browser": **Copy link**, then open it in Safari (iOS) or *Open in Chrome* (Android). Web-views can't install a PWA. |
| iOS / iPadOS Safari (16.4+) | Share → **Add to Home Screen**, worded for Safari. |
| iOS / iPadOS Chrome (v113+, iOS 16.4+) | The same, worded for Chrome (Share beside the address bar → *View More*). |
| iOS older than 16.4, Chrome older than v113, or another iOS browser | An amber alert instead of steps: update iOS / Chrome, or open the page in Safari or Chrome. The check **fails open** when a version can't be read (e.g. iPadOS posing as a Mac). |
| macOS Safari | File → **Add to Dock**. |
| Android without a captured prompt | Browser menu → *Install app* / *Add to Home screen*. |
| Desktop Chromium | Address-bar install icon or menu. |
| Unsupported (e.g. desktop Firefox) | "Open in Chrome, Edge or Safari", a **Copy link** button, and the QR code below. |

Around that:

- **Phone handoff.** On a desktop or laptop, *Use your phone instead* reveals a QR code (plus **Copy link**) so
  the participant can carry on from their phone; it is open by default where the desktop browser can't install.
  The QR code is a separate lazy-loaded chunk.
- **After installing.** Once the browser reports the install finished, the install card is replaced by
  *"Added to your Home Screen! Now open iEMAbot from there"*, step 1 of the funnel is ticked and step 2
  becomes the active one. Installing does not turn the current tab into the app, so the participant must open
  the installed icon. A *"Can't see the app after adding it?"* hint explains where iOS and Android put the icon.
- **Invite code and participant ID on iOS.** The iOS home-screen app starts with empty storage, so a code that
  arrived as a `?key=` link, and the participant ID of a personalised `?key=&pid=` link, don't carry over. When the
  notifications step sends an iOS participant to install first, both are shown ("Write these down"). The installed
  app's code-entry screen takes the invite code and an optional **Participant ID**, and sends the participant to
  `?key=…&pid=…`, so enrolling by typing behaves exactly like opening the link. (Android and desktop-Chromium installs
  share storage with the browser, which restores the last working code and ID.)
- **Early prompt capture.** Chromium fires `beforeinstallprompt` once, early. `main.tsx` calls
  `initInstallCapture()` before React mounts and the prompt is held at module level, so screens that mount later
  still get the **Install app** button. The same module owns the single `standalone` check that the funnel,
  the study load and `InstallPrompt` share.

### Smartphone-only studies

A researcher can tick **Only allow participation on a smartphone (web)** in the study settings (the
`webPhoneOnly` field; it needs **Web** ticked under study availability). On any other device the app does not
start the study. It shows an **invite page** instead, laid out top to bottom as:

1. what to do: this is a smartphone study, open the link on your phone;
2. the **invite code and participant ID to write down** (see *Invite code and participant ID on iOS* above);
3. the **QR code**, centred, for the same link (invite code and any participant ID included). Tap it to enlarge
   it in a lightbox; Esc, the X or a tap outside closes it;
4. **Other ways to open it**: *Copy the link to send it to yourself* and, on touch devices, *I'm on my phone,
   continue here*;
5. the tip *open it in Safari or Chrome (iPhone), or Chrome (Android)*.

The hosted study page (`/<access key>` on the ESMira server) shows the same layout on desktop, with *On your
phone? Tap to open directly* among the other ways, and says "smartphone" instead of "smartphone or tablet" for
these studies.

- **Nothing is recorded first.** The check runs as soon as the study loads, before the user id, the participant-ID
  lock, consent and the join event. Scanning the code on a phone therefore starts from a clean slate and never hits
  "already active on another device". The invite page also hides the menu, whose actions (contact, error report,
  notifications, wearables) all need a participant id.
- **What counts as a phone.** A touch device whose shorter physical screen edge is under 600 px (`isPhone()` in
  `lib/pwaInstall.ts`). It reads `screen.*`, so orientation and the iOS "Request Desktop Website" mode don't matter.
  Tablets, 7-inch tablets (Android treats 600 px as a tablet) and the inner screen of an unfolded foldable count as
  larger devices.
- **Escape hatch.** Touch devices that are not phones (tablets, foldables, a large phone we misread) also see
  *I'm on my phone, continue here*. It is remembered for that study on that device. Computers never see it.
- **Nobody is locked out mid-study.** A device that already consented to the study is never gated, so switching
  the setting on later doesn't turn existing participants away.
- **Off by default.** Only an explicit `true` gates, so existing studies behave as before.
- **A convenience, not a lock.** The check runs in the participant's browser, so it protects data quality, not access.

## Consent, name and join time

The study description (HTML) and consent text are shown, then *I consent* / *I do not consent*. The display
name is cosmetic; the participant ID is assigned automatically. **The consent time is the enrolment anchor**
(`esmira_joined_*`, event `joined`), from which `durationStartingAfterDays` and similar fields count.

A researcher can tick **Do not ask participants for a name (web)** in the study settings (the `webSkipName`
field). The name step is then skipped everywhere it would appear (first visit, after consent, after transferring
a participant ID), and the Details panel shows no username.

## End-of-questionnaire snapshot

A study with `webSnapshot` set to `esrs2026` (source-edit only) adds three messages after a check-in, mirroring
the Telegram bot's esrs2026 study:

1. **Snapshot**: a mood line from the I-PANAS-SF items (`alert`, `inspired`, `active` against `nervous`, `upset`,
   `afraid`) and a sleepiness line from the `kss` item.
2. **Your answers**: the participant's own answers in question order, with short labels for a few items, `/5` on
   scales and, for the reaction-time game, a line such as *17 taps, average 555 ms, 7 lapses* (false starts are
   not taps). Like the bot, only questions that have an answer appear, so one hidden by its `show_if`
   (`relevance`) or skipped is left out.
3. **Thank-you**.

It is computed in the browser by `lib/snapshot.ts`.

## Local-only studies

A study with `webLocalOnly` (source-edit only) sends nothing to the server. One switch in `lib/esmiraApi.ts`
(`setLocalOnly`, set as soon as the study loads) turns every writing call into a no-op: questionnaire and joined
datasets, other events, client telemetry, push subscription and test, wearables and the participant-ID lock. The
study itself is still fetched, and the *contact the research team* message is still sent if the participant
writes one. After a check-in the app says nothing was sent instead of "your responses have been recorded". The
ESRS 2026 mini check-in uses it, and its end screen says so. Server access logs of the study request remain.

## Task actions and pausing

With `webTaskActions` (source-edit only), a cognitive task card shows four buttons in two rows, as under a task in
the Telegram bot: **Start task**, **Skip task** (drops only the task), **Remind me later** and **Skip check-in**
(drops the whole questionnaire; nothing is submitted).

**Remind me later** parks the check-in **on this device** (`lib/pausedCheckin.ts`, key `esmira_paused_<study>_<id>`):
answers so far, the position, and any recording or typing identifiers. No push is scheduled, so the app says the
answers are saved and does not promise a reminder. The questionnaire list then shows *Paused — tap to carry on*;
resuming replays the earlier answers into the chat and continues where it stopped. Finishing, skipping or resuming
clears the saved copy. One paused check-in is kept per study and participant.

## Notifications step

Shown only when the study has `webPushEnabled`. The participant chooses *Enable notifications* or
*Continue without notifications*. On enabling, the server sends a welcome push and the app asks
*"Did the notification arrive?"*. A denied permission shows how to unblock it with *Try again*; iOS Safari
before install shows the install card.

## Practice (tutorial) mode

If `enableTutorialMode` is set (and not yet seen, or `?tutorial=1`; `?tutorial=0` suppresses), the app offers
practice runs of every questionnaire. **Nothing is saved or sent** from a practice run.

## Questionnaire list and survey

Available questionnaires are buttons; others are disabled with a lock and a reason such as *Opens at 20:00*.
The header shows *Day N of M*, *Starts [date]*, or *Ended [date]*.

In a survey, one card appears at a time with a progress bar. The footer text box is used only for plain
`text` questions. Optional questions are marked *(Optional)*. After submitting, *"Would you like to complete
another questionnaire?"* appears only if another is actually available now.

**Change response:** by default only the last answer can be changed; `changeResponseMode` can allow any
(`any`) or none (`none`). Cognitive items are never changeable.

## Availability {/* #availability */}

`availability.ts` computes one of: *available, upcoming, ended, completed, locked*, re-evaluated every 60 s
while the app is open.

| Rule | Honoured fields |
| --- | --- |
| Study window | `durationStart` / `durationEnd`, `durationStartingAfterDays`, `durationPeriodDays` (anchored on enrolment, local-day boundaries) |
| Once | `completableOnce` |
| Once per day | `completableOncePerDay`: locks until the next scheduled day (passive questionnaires reopen at midnight) |
| Frequency | `limitCompletionFrequency` / `completionFrequencyMinutes` |
| Per notification | `completableOncePerNotification` with `completableMinutesAfterNotification` as window length |
| Fixed window | `completableAtSpecificTime` start/end |
| Signal windows | From `actionTriggers.schedules` (`dailyRepeatRate`, `weekdays`, `dayOfMonth`, `skipFirstInLoop`, `startDayOne`); a window stays open until end of day unless a timeout is set |
| Passive | No signals: gated only by the duration fields |

## Settings and other screens

| Screen | Content |
| --- | --- |
| Settings | Dark mode, high contrast, reduce motion (defaults to the OS setting), text size (Normal → XX-Large), send error report (with a preview; no survey answers), "Notifications not working?", connect wearables, update studies, About |
| About | App version (root `package.json`), server version, server URL, upstream ESMira link |
| Details | Username, user ID, server URL, joined at, completed questionnaires, next notification, wearables, study description, consent, **upload protocol** (sent / pending) |
| Contact | Free-text message to the researcher via `save_message.php` |
| Sign out | Full local reset, then reload |

:::note[Appearance settings do not persist]
Dark mode, contrast and text size are in-memory state and **reset on reload**.
:::

## Optional device lock

If the URL carries a participant ID parameter, the app calls a same-origin `/api/v1/esmira/pid-lock`
endpoint to keep one device per invite ID, offering *Transfer to this device* or *Use a different code*. That
endpoint belongs to a separate service and **is not part of this repository**; the call fails open on error.
Whether it is live in a given deployment is <span className="status status--tbc">TBC</span>.
