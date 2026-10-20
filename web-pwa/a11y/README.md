# Participant PWA — automated accessibility audit

Automated **WCAG 2.2 A/AA** audit of the ESMira participant PWA, using
[axe-core](https://github.com/dequelabs/axe-core) driven by Playwright. It serves
the real production build (`dist/pwa`) with `vite preview` and walks the whole
participant journey — invite code → consent → name → the mandatory notifications
step → the tutorial → a practice run of **every questionnaire** — running an axe
scan at each state, in both the **light** and **dark** themes.

This is the same check that gates a production deploy (see `deploy.sh`).

## What it covers

- **Every renderable question type**, via a fixture study
  (`fixtures/study-all-types.json`) served through request interception: likert,
  single- & multiple-choice, yes/no, free text, number, time, duration, date,
  visual-analogue scale, voice memo (recorder modal included), cognitive-task
  launch card, and info/image. The fixture is the real `ssrc` sample study (its
  morning / momentary / evening bundle) **plus** an injected "All Question Types"
  questionnaire, so the sample bundle is exercised too.
- **Both themes**, so colour-contrast is checked against the actually rendered
  colours in day and night mode.
- **All the app chrome**: onboarding screens, the quick-actions menu, Settings
  and its sub-panels, the Details/study-info modal, Contact, and the recorder.
- **Every install-guidance variant** on the invite-code screen. The guidance is chosen
  from the browser's user agent, so each flavour gets its own browser context: iOS Safari,
  iOS Chrome, an outdated iOS (amber alert), an in-app web-view, Android with a captured
  install prompt, and a desktop browser that can't install (with the "use your phone"
  QR code open).

Colour-contrast is measured on the *settled* UI: the audit runs with
`prefers-reduced-motion`, so axe never samples a mid-fade blend.

## Colour-vision deficiency (WCAG 1.4.1 Use of Color)

axe's contrast checks are luminance-only. On top of axe, every scanned screen goes through
`cvd-scan.mjs`, which reads the colours the browser actually painted, simulates **protanopia,
deuteranopia, tritanopia and achromatopsia** (Machado 2009, complete dichromacy, in `cvd.mjs`), and
looks for **colour-only indicators**: a chromatic fill or border with no text, icon or accessible name
of its own or in its container, whose colour is *confusable* with another chromatic colour on the same
screen under some deficiency.

- *Confusable* = clearly different to a typical viewer (CIEDE2000 ≥ 20), but close under the
  deficiency (ΔE < 12) **and** not separable by lightness (simulated contrast < 3:1). The thresholds
  are deliberately conservative, because a finding fails the deploy.
- A finding is reported as `cvd-color-only` and gates like any other serious violation. Fix it by
  adding a cue (a word, an icon, a pattern, a different lightness), or, for a deliberate exception such
  as a bar whose *length* carries the meaning, put `data-cvd-ok="<reason>"` on the element.
- The report also lists text/icon colours that collapse together under a deficiency (typically the
  green/red/amber status trio). Those are **information**, not failures, while a word or icon
  accompanies the colour; they show where a future colour-only change would break.

Knobs: `A11Y_CVD=0` skips it, `A11Y_CVD_IMPACT` changes the impact it is reported at (set it to
`moderate` to make it report-only), `A11Y_CVD_CHROMA` (default 25) is the Lab chroma above which a colour
counts as carrying hue, and `A11Y_CVD_SHOTS=1` saves each screen as seen with each deficiency to
`report/cvd/` for review (CI does this and uploads it with the report).

Limits: complete dichromacy is the worst case, so milder anomalous trichromacy is not separately
modelled; gradients, images and canvases are not analysed; and the "has a cue" test is a container
heuristic, so reviewing the saved screenshots is still worthwhile. Unit tests (`npm test` here, or
`npm run a11y:test` at the repo root) cover the colour maths against published reference values and
prove the detector fails on a colour-only indicator.

## Run it

```bash
# one-time: install axe + Playwright + Chromium
npm install            # (or `npm run a11y:setup` from the repo root)

# build the PWA first (the audit serves dist/pwa), then audit
(cd .. && npm run build)
node audit.mjs         # (or `npm run a11y` from the repo root, after a build)
```

Exit code is non-zero if any **critical** or **serious** violation is found.
A human-readable summary and the full machine-readable results are written to
`report/a11y-report.md` and `report/a11y-report.json`.

## Modes & knobs (env vars)

| Var | Default | Meaning |
|-----|---------|---------|
| `A11Y_MODE` | `fixture` | `fixture` = offline, deterministic, all question types. `live` = drive the real study over the network (needs VPN/connectivity; catches server drift). |
| `A11Y_KEY` | `ssrc` | Study invite code used in `live` mode. |
| `A11Y_FAIL_ON` | `critical,serious` | Impact levels that fail the gate. |
| `A11Y_THEMES` | `light,dark` | Themes to audit. |
| `A11Y_LIVE_BUNDLE` | – | `1` = walk morning/momentary/evening in *every* theme (slower). |
| `A11Y_PORT` | `4318` | Port for the spawned `vite preview`. |
| `A11Y_BASE_URL` | – | Audit an already-running server instead of spawning preview. |
| `ESMIRA_PROXY` | (vite default) | API proxy target for `live` mode. |

## In the deploy pipeline

`deploy.sh` runs this audit right after `npm run build:all` and before anything
is synced to the server; a critical/serious violation aborts the deploy. Set
`A11Y_SKIP=1` to bypass it in an emergency. CI runs the same audit at release
time (`.github/workflows/accessibility.yml`, on a published GitHub release or
on demand) — deploy.sh remains the day-to-day enforcement.
