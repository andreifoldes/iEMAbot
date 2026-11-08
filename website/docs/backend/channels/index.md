---
title: "Channels"
sidebar_label: "Channels"
description: "How a study reaches participants: the web app, Telegram and the Android app, and which features belong to which channel."
---

# Channels

A study is written once and delivered through whichever channels the researcher switches on. The questions,
the [schedule](../scheduling.md) and the stored data are the same everywhere. What differs is how a
participant is **reminded**, how the questionnaire is **shown**, and which extras are possible.

| Channel | Participant uses | Reminders | Status |
| --- | --- | --- | --- |
| **Web app (PWA)** | An invite link in any browser, optionally added to the home screen. See [PWA overview](../../pwa/overview.md) | [Web Push](./web-push.md) | <span className="status status--shipped">Shipped</span> |
| **Telegram** | A bot in the Telegram app the participant already has. See [Chat channels (Telegram)](./chat-channels.md) | Messages from the bot | <span className="status status--partial">Partial</span> |
| **Android app (Beta)** | A native shell around the same web app. See [Android app](./android-app.md) | Alarms planned on the phone | <span className="status status--partial">Partial</span> |

## What works where

| Feature | Web app | Telegram | Android app |
| --- | --- | --- | --- |
| All [question types](../question-types/index.md) | Yes | Most, as buttons and typed replies; some via a web handoff | Yes (same screens as the web app) |
| [Voice memos and typing fallback](../question-types/index.md#voice-memos) | Yes | Voice message; typing as a plain reply or in the handoff | Yes, with the keyboard's own timing |
| [Cognitive tasks](../question-types/cognitive-tasks.md) | Embedded | In a Telegram Mini App (handoff) | Embedded, cached for offline use |
| Reminders | [Web Push](./web-push.md) | Bot messages | On-device alarms, work offline |
| [Phone sensing](./phone-sensing.md) (light, activity, accelerometer, app usage) | Not possible | Not possible | **Android only** |

[Wearable](../wearables.md) data and wearable-triggered prompts are collected and worked out by the server, so
they are not tied to one channel.

## In this section

- [Web Push](./web-push.md): reminders for the web app, how they are scheduled, suppressed and monitored.
- [Chat channels (Telegram)](./chat-channels.md): running a study as a conversation, and how to add another
  chat platform.
- [Android app (Beta)](./android-app.md): the native shell around the web app, with on-device reminders and
  phone sensing.
- [Phone sensing](./phone-sensing.md): what the Android app collects around each prompt and how it reaches
  the server.
