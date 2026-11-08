---
title: "Android app (Beta)"
sidebar_label: "Android app (Beta)"
description: "The iEMAbot Android app: what it adds to the participant web app, how participants get and join it, the permissions it asks for, and what is still being tested."
---

# Android app (Beta)

<span className="status status--partial">Partial</span> **Beta** · Android 7.0 or newer · fork-only

The Android app is the third way a participant can take part in a study, next to the
[participant web app](../../pwa/overview.md) and the [Telegram bot](./chat-channels.md). It is the same
chat-style app, bundled into an Android package, plus native code for the things a browser cannot do reliably on a
phone: reminders that fire without a connection, sensors, and exact keyboard timing. Studies see its data like any
other: responses arrive with `appType` `AndroidApp`.

:::info[Beta]
The app is in a closed Beta. It is not on Google Play or in the main F-Droid catalogue. It is shared with
participants and testers through a private F-Droid repository, and the link is **given on request** by the study
team (it is not published on this site). Several parts are not yet verified on real devices, see
[What is still being tested](#beta-limits).
:::

## What it adds {/* #what-it-adds */}

| | Web app (PWA) | Android app |
| --- | --- | --- |
| Getting it | Open the invite link; optionally add to the home screen | Install the package (F-Droid client or direct download); the link comes from the study team |
| Reminders | Web Push from the server (needs the browser's permission and a working push service) | Alarms planned **on the phone** for the next 7 days, so they fire offline and on the minute; prompts that depend on a wearable are fetched from the server every 15 minutes. No Google push service is involved. See [Web push](./web-push.md) |
| Typing fallback timing | Browser event timestamps | The keyboard's own timing (`ime` rows), see [Keystroke fallback](../question-types/index.md#keystroke-fallback) |
| Phone sensing | Not possible | Ambient light, activity recognition, accelerometer windows and app usage, see [Phone sensing](./phone-sensing.md) |
| Cognitive tasks offline | Cached in the browser | Cached in the app's private storage, see [Cognitive tasks](../question-types/cognitive-tasks.md) |
| Updates | The page updates itself | The server ships the matching build; the app shows *A new version of the app is available* with an **Update** button, see [CI and release](../../deployment/ci-and-release.md#android-lockstep) |

The screens, questions, schedule engine, voice memos and offline queue are the same code as the web app, so
anything described under [Participant PWA](../../pwa/overview.md) applies, and a change to the web app reaches the
Android app with the next build.

## Joining a study {/* #joining */}

The app is built once for every server, so it asks which one to use.

1. Open the invite link the researcher sent, or scan the invite QR code. A link of the form
   `esmira://join?server=…&key=…&pid=…` opens the app and fills in the server, the study's access key and the
   participant ID.
2. Or open the app and type the details on its first screen: the **server address** (the app may offer a default),
   the study's **access code** and, if the study uses one, the participant ID.
3. The app then follows the usual [participant flow](../../pwa/participant-flow.md): consent, the permission steps
   below, and the questionnaire list.

Signing out tells the server the participant has left, cancels the alarms the app planned and stops its background
poll, exactly as in the web app.

## Permissions {/* #permissions */}

The app explains each permission before Android shows its own dialog. It only asks for what the study needs.

| Permission | Why | Asked when |
| --- | --- | --- |
| Notifications | Reminders | Joining a study that has reminders |
| Microphone | Voice memos | Joining a study with a `record_audio` item (unless the researcher turned the step off, see [Voice memos](../question-types/index.md#voice-memos)) |
| Physical activity | Activity recognition | A study with the activity sensor on |
| Usage access | Time per app and unlock counts | A study with app usage on; the participant switches it on in Android's settings |

Exact alarms (so reminders fire on the minute) need no dialog. For sensing, the app also runs a short foreground
service while it records accelerometer windows, and it restores its reminders after the phone restarts.

Android's *NonFreeDep* label in the F-Droid listing refers to one library, Google's activity recognition, which the
app uses only when a study turns that sensor on. It is the only Google library in the app.

## Beta limits {/* #beta-limits */}

- **Phone sensing** is implemented end to end but not yet verified on real devices. See
  [Phone sensing](./phone-sensing.md).
- **Background reminders** have not yet been verified across phone manufacturers. Some makers' battery savers delay
  or stop alarms of apps the participant has not opened for a while, and the 7-day plan rolls forward only when the
  app is opened. See [Web push](./web-push.md).
- **Needs a connection:** first sign-up (the study is fetched once), and setting up push or a wearable. Answers,
  voice memos and keystroke logs queue on the device and upload when the connection returns, see
  [Offline and uploads](../../pwa/offline-and-uploads.md).
- **Listen** (read-aloud) needs speech voices installed on the phone to work offline.

How the build is made, signed and shipped, and how the private F-Droid repository is produced, is on
[CI and release](../../deployment/ci-and-release.md#android-lockstep).
