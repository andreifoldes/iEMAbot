---
title: "Cognitive tasks"
sidebar_label: "Cognitive tasks"
description: "How browser-based cognitive tasks (m2c2kit and similar) are embedded in surveys, how results return, and the cache-busting rules."
---

# Cognitive tasks

<span className="status status--shipped">Shipped</span> embedding and capture ·
<span className="status status--partial">Partial</span> the task pages themselves are **not in this repo**

Diurnal variation in cognition is a core use case. The PWA embeds web-based tasks directly in a survey so a
participant never leaves the app.

## The `webapp` input

A first-class `webapp` response type carries a launch `url`, a title (`text`) and a `webappDescription`.
(Legacy: a plain text item containing an `<a href>` is also treated as a cognitive task.)

```mermaid
sequenceDiagram
  participant S as Survey card
  participant F as Full-screen iframe
  participant T as Hosted task page
  S->>F: open url + "embed=1&v=N"
  F->>T: load task
  T-->>S: postMessage {type: "m2c2:complete", summary, data}
  S->>S: check origin matches iframe URL
  S->>S: store summary in item column, trial rows in hidden questionnaire
```

- The launch URL gets **`embed=1&v=N`** appended. Keep the stored URL **clean**: the adapter adds those
  parameters.
- The iframe is full-screen with `autoplay`, `fullscreen`, `accelerometer` and `gyroscope` allowed.
- Completion arrives as `postMessage` `{type: 'm2c2:complete', summary, data}`; the origin is checked against
  the iframe URL. The participant sees only **"✓ Completed"**, with no performance feedback.
- Each task offers **Start** and **Skip**.

## What is stored

| Where | Content |
| --- | --- |
| The item's own column | Summary JSON `{session, n_trials, duration_s, correct_count}` |
| A hidden questionnaire titled **"Cognitive Trials"** | One row per trial: `cogAssessment`, `cogSource`, `cogInputName`, `cogSession`, `cogTrialIndex`, `cogRt`, `cogCorrect`, `cogResponse`, `cogStimulus`, `cogRaw` |

If the hidden questionnaire does not exist in the study, no per-trial rows are written. Whether it exists in
a given deployment is something to confirm per study.

## Tasks used in the fixtures

Color Shapes, Symbol Search, Prices, a brief Psychomotor Vigilance Test (PVT-BA) and the Affective Slider.
The wrapper pages are served from a separate host (under `/webapp/…`); this repository holds only the
integration.

## Compatible task libraries

Two companion repositories hold the task pages that iEMAbot embeds. Each task is a static page, so any of
them can be used as the `url` of a `webapp` item. A task works inside the PWA when it posts
`m2c2:complete` to the parent frame (see the sequence above).

| Repository | Contents | Notes |
| --- | --- | --- |
| [`m2c2-assessments`](https://github.com/andreifoldes/m2c2-assessments) | Cognitive tasks built on [m2c2kit](https://github.com/m2c2-project/m2c2kit): PVT-BA, SART2, Color Dots, Symbol Search, Grid Memory, Color Shapes, Prices, FNAME, FNAME-Pairs, mVLT. | Every task accepts `token`, `callback_url` and `show_end_screen`. Most accept optional `webcam` and `webgazer` flags. A [URL parameter reference](https://andreifoldes.github.io/m2c2-assessments/docs/) is generated from the task source. |
| [`novel-assessments`](https://github.com/andreifoldes/novel-assessments) | Affective Slider (pleasure × arousal, built on m2c2kit), Reflection (voice memo or free text with a keystroke-timing log) and MoodLine-OS (mood rating with cartoon faces). | Affective Slider and Reflection post `m2c2:complete`. |

:::caution[MoodLine-OS is not drop-in]
MoodLine-OS posts `NOVEL_COMPLETE` rather than `m2c2:complete`, so the PWA does not capture its result when
it is embedded as a `webapp` item. The tasks used in the fixtures (above) are the ones verified end to end;
check that any other task posts `m2c2:complete` before putting it in a live study.
:::

The repositories' READMEs are the source of truth for the current task list and each task's parameters;
this page does not duplicate them.

## Cache-busting rule

The wrapper chain loads `index.html?v=N` → `index.js?v=N` → `task.js?v=N`. The `v=N` value is set **in
`esmiraAdapter.ts`**, and the PWA emits `embed=1&v=N` itself. Consequences:

:::warning[A committed version bump does nothing on its own]
Changing `N` has no effect until the **PWA is rebuilt and redeployed**, because the value is baked into the
bundle. Participants with an installed PWA also need the service worker to update.
:::

Related: the PVT instruction screen defaults `show_tutorial` to true.

## Offline use

<span className="status status--shipped">Shipped</span> in the browser PWA and the Android app, per task once its host publishes a manifest.

A participant who signed up while online can run the interactive tasks with no connection. After sign-up (and on every app start and
reconnect) the app downloads each task listed in the study, then opens it from the local copy:

- **Browser PWA:** files go into the Cache API and the iframe runs at `/pwa/t/<server path>`, which the service worker maps back to the cache
  (the task pages sit outside the worker's `/pwa/` scope, so it cannot answer for them directly).
- **Android app:** files go into the app's private storage and are served same-origin by the Capacitor WebView.

A task is only used offline when a **complete** copy exists; otherwise it loads from the network as before. Library files shared by several
tasks (m2c2kit `core`, about 7.6 MB) are stored and fetched once.

### Publishing the manifest

Each task folder needs a `task-manifest.json` listing its files (and the module folders its import map points at). Generate them on a copy of
the host's webapp tree and publish them next to the tasks; re-run after **every** task deploy:

```bash
node tools/cognitive-tasks/build-task-manifest.mjs <webroot>   # e.g. the m2c2 folder, then the novel-assessments folder
```

A folder with no manifest simply stays online-only. Check the result with
`python3 tools/e2e/offline_real_tasks_e2e.py <webapp-dir> <task-path>…`, which generates manifests for a local copy of the tree, signs up in
Chromium, relaunches the app **offline** for each task and fails on any task that does not render from the cache.

:::note[What still needs a connection]
Signing up (the first study fetch), push and wearable set-up, the participant-ID device lock, and the Listen button on devices without offline
speech voices. Answers, voice memos and keystroke logs are queued on the device and upload when the connection returns.
:::

## Accessibility note

The cognitive iframe is deliberately **excluded** from the automated accessibility audit, since the content
is third-party task code. See [Accessibility](./accessibility.md).
