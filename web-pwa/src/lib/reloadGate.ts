/**
 * Holds the PWA's update reload (main.tsx: a new service worker took over) while a check-in is open.
 *
 * A reload mid-check-in drops the question on screen and anything in progress on it (a cognitive
 * task, a recording). Outside a check-in an update applies straight away, as before. During one it
 * waits: once the check-in is over it applies the next time the app goes to the background, so the
 * participant's thank-you is not wiped off the screen. The Android app has no service worker and
 * never asks for one.
 */

export interface ReloadGate {
  /** A new version is ready: reload now, or once nothing holds it. */
  request(): void;
  /** Hold (a check-in is open) or release. */
  hold(on: boolean): void;
  /** The app went to the background: a deferred reload runs now if nothing holds it. */
  hidden(): void;
}

export function createReloadGate(reload: () => void, isHidden: () => boolean): ReloadGate {
  let held = false;
  let pending = false;
  let done = false;
  const run = () => {
    if (done) return;
    done = true;
    pending = false;
    reload();
  };
  return {
    request() {
      if (held) pending = true;
      else run();
    },
    hold(on) {
      held = on;
      if (!on && pending && isHidden()) run();
    },
    hidden() {
      if (pending && !held) run();
    },
  };
}

/** The app's gate: main.tsx requests, App.tsx holds while a check-in is open. */
export const updateReload: ReloadGate = createReloadGate(
  () => window.location.reload(),
  () => typeof document !== 'undefined' && document.visibilityState === 'hidden',
);
