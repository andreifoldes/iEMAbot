---
title: "Platform architecture"
sidebar_label: "Platform architecture"
description: "Two product-level diagrams of iEMAbot: the core platform from study editor to data export, and the same platform with the optional voice-response and audio-biomarker route."
---

import useBaseUrl from '@docusaurus/useBaseUrl';

# Platform architecture

These diagrams show how the pieces fit together from a researcher's point of view. For the technical
view (API endpoints, storage, cron), see [Architecture at a glance](./index.md#architecture-at-a-glance)
and the [Backend overview](./backend/overview.md).

:::note[Badges are a snapshot]
The *Beta* and *Planned* badges are as drawn for the ESRS 2026 and Connectome 2026 posters, with the native
Android app updated to *Beta* in both diagrams. The [Current status](./current-status.md) page is the
authoritative claim-by-claim list, and the [Android app](./backend/channels/android-app.md) page says what Beta means.
:::

## Core platform

Researchers design a study in the web editor. The self-hosted server runs the rule engine, which decides when
to prompt, optionally using wearable and nearable events (Withings and Google Health, which also carries Fitbit and Pixel
Watch data, over OAuth 2.0). Prompts and reminders reach participants through the channel they use: the web app
(PWA), the Android app (Beta) and the Telegram bot today, with WhatsApp and SMS planned. Responses are stored on the server, can be monitored live from the researcher
dashboard, and are exported as CSV (wearable data as ZIP).

<div style={{textAlign: 'center'}}>
  <img
    src={useBaseUrl('/img/architecture-core.svg')}
    alt="Architecture diagram of the core platform. A researcher study editor feeds the iEMAbot server, which runs a rule engine and stores encrypted data on a VPS, and receives events from wearables and nearables via OAuth 2.0. The server sends prompts and reminders to the web app (PWA, beta), the Telegram bot (beta), WhatsApp and SMS (planned) and a native Android app (beta). Responses flow to a researcher dashboard and then to a researcher data export as CSV, with wearable data as ZIP."
    style={{maxWidth: 560, width: '100%'}}
  />
</div>

## With voice responses

When a study includes [voice memos](./backend/question-types/index.md#voice-memos), recordings are uploaded alongside the
other responses and collected like any other data. What happens next is the researcher's choice, because audio
**biomarker extraction runs outside iEMAbot**: use an open toolkit such as SpeechDETECT<sup>1</sup>, or your own
acoustic or linguistic feature pipeline, on the collected audio, then merge the features with the exported
responses.

<div style={{textAlign: 'center'}}>
  <img
    src={useBaseUrl('/img/architecture-voice.svg')}
    alt="Architecture diagram of the platform with voice responses. The same flow as the core platform, with the native Android app marked beta, continues from the researcher dashboard: audio responses go to an audio biomarker extraction step that is the researcher's choice, either the SpeechDETECT open toolkit or a custom pipeline, and the extracted features and responses then go to the researcher data export."
    style={{maxWidth: 560, width: '100%'}}
  />
</div>

<sup>1</sup> Zolnoori M, Esmaeili E, Naserian M, et al. SpeechDETECT: an explainable automated speech processing
pipeline for early detection of neurological and mental health changes. *Health Inf Sci Syst* 2026;14(1):77.
doi:[10.1007/s13755-026-00468-5](https://doi.org/10.1007/s13755-026-00468-5)
