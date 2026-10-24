---
title: "Accessibility"
sidebar_label: "Accessibility"
description: "The PWA's accessibility features, the automated WCAG 2.2 audit that gates every deploy, and what has not yet been tested."
---

# Accessibility

<span className="status status--partial">Partial</span> Strong automated coverage; manual assistive-technology
testing is still to do.

The project targets older and cognitively vulnerable participants. The design choices below target that,
but **no usability study with those groups is documented**.

## Design choices

- **Familiar interaction model:** a chat thread, one question per screen, a progress bar, plain wording.
- **Text size** up to *XX-Large*, a **high-contrast** theme, **dark mode**, and **reduce motion** (defaulting
  to the OS preference). Pinch-zoom is allowed (WCAG 1.4.4).
- **Image lightbox** and a **maximised prompt image** in recorder modals for picture tasks.
- **Fallbacks:** voice and typing alternatives, *Skip*, and *Change response*.
- **Semantics:** modal focus trap and Esc (`useDialogA11y`), an `aria-live` chat log, `lang="en"`.

## Researcher-supplied accessibility content

<span className="status status--shipped">Shipped</span> Each question has optional fields in the study
editor (translatable per language): **Image description (alt text)** for `image` questions, a
**Screen reader label** that becomes the announced name of the question, and **Read-aloud text** that
adds a *Listen* button. See [Study model](../backend/study-model.md) for the field names. Leaving them
empty changes nothing for existing studies.

## Automated audit

| Aspect | Detail |
| --- | --- |
| Tool | `axe-core` via `@axe-core/playwright`, Playwright Chromium (`web-pwa/a11y/audit.mjs`) |
| Standard | WCAG 2.0/2.1/2.2 **A and AA** tags |
| Viewport | 390 × 844, light and dark themes, reduced motion |
| Gate | Fails on **critical** or **serious** impacts (`A11Y_FAIL_ON`) |
| Colour vision | Simulates protanopia, deuteranopia, tritanopia and achromatopsia on the rendered colours; **fails on colour-only indicators** that clash under one of them (see below) |
| Fixture | `a11y/fixtures/study-all-types.json`: a sample study plus an "All Question Types" questionnaire (offline mode); a live mode also exists |
| Coverage | Invite, consent, name, notifications, tutorial, a practice run of every questionnaire, Settings and its panels, Details panels, Contact, the recorder modal |
| Latest result | 130 screens, 0 violations (report dated 4 Sep); `AUDIT.md` records 134 states, 0 issues |

Run it locally:

```bash
npm run a11y:setup   # one-off: installs the audit's dependencies
npm run a11y
```

The deploy workflow runs the same audit as a **hard gate** before syncing. A GitHub
workflow runs it on published releases and on manual dispatch, **not on every push**.

### Colour-vision deficiency

axe's contrast checks are luminance-only, so the audit adds a colour-vision-deficiency check on every
scanned screen. It reads the colours actually painted, simulates complete red, green, blue and total colour
blindness, and looks for a **colour-only indicator**: a chromatic fill or border with no text, icon or
accessible name of its own or in its container, whose colour becomes confusable with another colour on the
same screen (and cannot be told apart by lightness either). Such a finding is reported as `cvd-color-only`
and gates the deploy like any serious violation. A deliberate exception, such as a bar whose length carries
the meaning, is marked `data-cvd-ok="<reason>"`.

The report also lists text and icon colours that collapse together under a deficiency. The status colours
(green, red, amber) are always paired with a word or icon in the current UI, so these are information, not
failures, but they mark where a future colour-only change would break. In CI the audit also saves every
screen as seen with each deficiency, for human review. First run: no findings across 130 screens.

Limits: complete dichromacy is the worst case, so milder forms are not modelled separately; gradients,
images and canvases are not analysed; and whether a colour has a cue is a container heuristic, so reviewing
the saved screenshots remains worthwhile.

Fixes recorded in `AUDIT.md`: menu role changed to `group`, timestamp contrast, scrollable consent region made
focusable, focus-visible ring on the chat log, global `prefers-reduced-motion` CSS.

## Not covered

<span className="status status--tbc">TBC</span>

- Manual **VoiceOver / TalkBack** passes (recommended by the audit notes, not done).
- Automated coverage of the lightbox, keystroke modal, other-specify modal and wearables panel.
- The cognitive-task iframe (third-party content, deliberately skipped).
- **Likert buttons are 40 px**, under the 44 px recommended touch size, and scale anchor labels are one size
  smaller than body text.
- Usability testing with older adults or people with mild cognitive impairment.
