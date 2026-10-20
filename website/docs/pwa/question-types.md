---
title: "Question types and input features"
sidebar_label: "Question types"
description: "How each ESMira response type renders in the PWA, plus the fork-added inputs: voice memos, keystroke fallback, other-specify, relevance, images and explanations."
---

# Question types and input features

## Standard types

| ESMira `responseType` | PWA control | Stored value |
| --- | --- | --- |
| `likert` | Round buttons (40 × 40 px) with end labels | `1..N` |
| `binary` | Yes/No from left/right labels | `0` / `1` |
| `list_single` | Full-height choice list | The choice label |
| `list_multiple` | Choices with *Done* | `name~i` booleans |
| `number` | Numeric (step 0.5) | The number |
| `date` | Date picker | `YYYY-MM-DD` |
| `time` | Hour/minute selects, 5-minute steps | `HH:MM` |
| `duration` | Hour/minute selects, 5-minute steps | Total minutes |
| `va_scale` | Slider (default max 100, starts at midpoint) | The value |
| `text_input` / `text` | Footer text box / info bubble | Text / none |
| `image` | Info bubble with the `url` image | none |

A `time` question whose text matches *"how long"* is rendered as a **duration picker** (maximum 16 h).
Durations need at least 1 minute. Item `description` (the **Explanation** field) renders as muted subtext.

## Voice memos {/* #voice-memos */}

<span className="status status--shipped">Shipped</span> `record_audio`

- The microphone is requested when the modal opens and recording starts immediately.
- Controls: pause/resume, **Stop**, review playback, **Save**, **Redo**, close.
- **Silent cap:** `maxLength` seconds, default 300 (5 min). The timer counts up and the maximum is not shown;
  at the cap, recording auto-stops into review.
- The "Tap Stop when you are done" hint appears only when there is no prompt image.
- **Picture tasks:** text before the first image shows on the chat card; the image and later text appear only
  in the modal, which is 94 dvh high with the image maximised, so older participants can see it clearly.
- Optional memos can be skipped. The container is WebM or MP4 depending on the browser.

## Keystroke fallback {/* #keystroke-fallback */}

<span className="status status--shipped">Shipped</span> `record_keystrokes`

A typing alternative for participants who cannot or will not speak.

- **Arming rule:** the `record_keystrokes` item must sit **immediately after** the `record_audio` item, on the
  same non-randomised page. It is then hidden unless the memo is skipped, and inherits the memo's prompt if
  its own is blank.
- A modal with a textarea and a soft target of about **2 minutes of active writing** (gaps over 8 s do not
  count; an idle hint appears after 12 s) shown as a progress bar.
- `minLength` is a **soft floor** that holds the bar below 100% until met. Saving only needs non-empty text:
  the floor never blocks submission.
- **What is stored:** a CSV `class,hold,release,press` where letters are bucketed into classes and **never
  stored as characters**, uploaded as `Keystrokes` media; plus the typed text in `<name>~text` and the
  capture mode (physical vs soft keyboard) in `<name>~capture_mode`. Paste and focus loss are marked.

## "Other, please specify" {/* #other-please-specify */}

<span className="status status--shipped">Shipped</span> `list_single`

Set `other: true` **and** make the free-text option the **last** choice. Picking it opens a modal, *"Please
describe your answer"*, which requires non-empty text. The answer is stored in `<name>~other`.

## Relevance conditions {/* #relevance-conditions */}

<span className="status status--partial">Partial</span>

The adapter maps an item's `relevance` to a `show_if` rule, with strict limits:

- **One comparison only**, of the form `name == v`, `name != v` or `name >= v` (values may be quoted;
  `>=` compares the leading integer, so "5+" counts as 5).
- Anything else is **ignored and the question always shows** (fail-open).
- The controlling question must come **earlier** and be answered, otherwise the dependent question stays
  hidden.
- Stale answers are pruned when the condition flips.

Compound or Merlin-script logic is not supported; see [Study model](../backend/study-model.md#merlin-scripting).

## Images and the lightbox {/* #images-and-the-lightbox */}

<span className="status status--shipped">Shipped</span>

Any `<img>` inside rich question text opens a **lightbox** on tap: pinch 1–5×, double-tap 2.5×, wheel zoom,
pan, and close with Esc, the close button or the backdrop. A hint reads "Pinch or double-tap to zoom".

## Not rendered

`photo` and `video` are in the data model but **dropped by the PWA** (`RENDERABLE` filter in the adapter).
Sensor and native-only types are not available on the web. See the full list in
[Study model](../backend/study-model.md#input-types).
