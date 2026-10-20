# Accessibility audit — ESMira participant PWA

Scope: the client-facing participant PWA (`web-pwa/`), audited against **WCAG 2.2
level A/AA** using the `accessibility` skill (WCAG 2.2 + Lighthouse/axe guidance).
Two parts: an automated axe-core audit (now wired into the deploy pipeline) and a
manual code review of things axe can't detect.

## How it was audited

`web-pwa/a11y/` — an axe-core + Playwright harness that serves the real
production build and drives the full participant journey (invite code → consent →
name → notifications step → tutorial → a practice run of **every question type**)
in **light and dark** themes, scanning **134 states** in total. See its README to
run it. It gates `deploy.sh` and runs in CI at release time.

## Starting point

The PWA was already strongly accessible: modal focus-trap + focus restore
(`useDialogA11y`), `role="dialog"`/`aria-modal`, `aria-hidden` on decorative
icons, accessible names on icon-only buttons, `role="switch"`/`aria-checked`
toggles, `aria-live` regions, `alt=""` on decorative images, `lang="en"`, a
zoomable viewport, a dedicated high-contrast theme, and framer-motion animations
gated on a reduce-motion toggle. The automated sweep found only a handful of
issues.

## Findings & fixes (all fixed)

| # | WCAG | Severity | Issue | Fix |
|---|------|----------|-------|-----|
| 1 | 4.1.2 Name, Role, Value | **Critical** | The quick-actions popover used `role="menu"` but its children are plain buttons, not `menuitem` (and it has no menu keyboard pattern) → axe `aria-required-children`. | Changed to `role="group"` (`aria-label="Quick actions menu"`). `App.tsx` |
| 2 | 1.4.3 Contrast (Minimum) | **Serious** | The answered-message timestamp on the primary bubble used `opacity-70`, dropping to **3.85:1** on the dark-theme primary colour. | Removed `opacity-70`; timestamps now use full `text-on-primary` / `text-on-surface-variant`. `App.tsx` |
| 3 | 2.1.1 Keyboard | **Serious** | The scrollable informed-consent / study-description text panel had no focusable content → keyboard users couldn't scroll it (`scrollable-region-focusable`). | Added `tabIndex={0}` + `role="region"` + a visible focus ring. `App.tsx` |

### Improvements from the manual review (axe can't detect these)

| WCAG | Issue | Fix |
|------|-------|-----|
| 2.4.7 Focus Visible | The scrollable chat log is `tabIndex={0}` with `focus:outline-none` and no replacement — keyboard focus was invisible. | Added `focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary`. `App.tsx` |
| 2.3.3 Animation from Interactions | The in-app "Reduce motion" toggle only gates the framer-motion animations; Tailwind `transition`/`animate`/scale utilities ignored the OS setting until then. | Added a global `@media (prefers-reduced-motion: reduce)` rule (`index.css`) **and** initialised the "Reduce motion" state from `prefers-reduced-motion` so motion-sensitive users get calm animations from first paint. `App.tsx` |

## Result

Automated gate: **0 critical / 0 serious / 0 moderate / 0 minor** across all 134
audited states (every question type, both themes). The gate now blocks any prod
deploy that regresses this.

## Colour-vision deficiency

Added after the original audit: a colour-vision-deficiency check (see `a11y/README.md`) simulates
protanopia, deuteranopia, tritanopia and achromatopsia on the colours the browser actually paints, and
fails the gate on colour-only indicators that clash under one of them. First run: **0 findings** across
130 screens. The status colours (green / red / amber) are always paired with a word or icon in the
current UI, so the check currently guards against regressions rather than reporting defects; the
quick-action tiles (blue / green / yellow / orange) collapse under several deficiencies but each carries
an icon and a label.

## Known limitations / future work

- **Live study drift.** The gate audits an offline fixture (deterministic). Run
  `A11Y_MODE=live` periodically to audit the real server's study content
  (researcher-authored rich text / consent HTML is out of the app's control but
  worth spot-checking).
- **Page language for multilingual studies.** `<html lang>` is fixed to `en`;
  studies delivered in another language should set `lang` to match (3.1.1).
- **Manual AT passes.** Automated tools catch ~a third of issues. A periodic
  manual pass with VoiceOver (iOS/Safari) and TalkBack (Android) — the platforms
  participants actually use — is still recommended.
