/**
 * Participant display preferences (text size, dark mode, high contrast, reduced motion).
 *
 * They are stored on the device so a low-vision or older participant sets them once and
 * never has to find Settings again. When nothing has been stored yet, the OS-level
 * preferences (`prefers-color-scheme`, `prefers-contrast`, `prefers-reduced-motion`) seed
 * the first run, so people who already configured their phone get a sensible app from the
 * very first screen (WCAG 1.4.4, 1.4.12, 2.3.3).
 */

export type TextSize = 'normal' | 'large' | 'xlarge' | 'xxlarge';

export interface A11yPrefs {
  dark: boolean;
  highContrast: boolean;
  reduceMotion: boolean;
  textSize: TextSize;
}

export const A11Y_PREFS_KEY = 'iemabot.a11yPrefs.v1';

/** Root font-size per text-size setting. Every rem-based size in the UI scales with it. */
export const TEXT_SIZE_ROOT_PERCENT: Record<TextSize, number> = {
  normal: 100,
  large: 125,
  xlarge: 150,
  xxlarge: 175,
};

const TEXT_SIZES = Object.keys(TEXT_SIZE_ROOT_PERCENT) as TextSize[];

const matches = (query: string): boolean => {
  try {
    return typeof window !== 'undefined' && window.matchMedia?.(query).matches === true;
  } catch {
    return false;
  }
};

/** Defaults taken from the operating system's accessibility settings. */
export function systemA11yPrefs(): A11yPrefs {
  return {
    dark: matches('(prefers-color-scheme: dark)'),
    highContrast: matches('(prefers-contrast: more)'),
    reduceMotion: matches('(prefers-reduced-motion: reduce)'),
    textSize: 'normal',
  };
}

/** Stored preferences merged over the OS defaults; unknown or corrupt values are ignored. */
export function loadA11yPrefs(): A11yPrefs {
  const base = systemA11yPrefs();
  let raw: string | null = null;
  try {
    raw = localStorage.getItem(A11Y_PREFS_KEY);
  } catch {
    return base;
  }
  if (!raw) return base;
  let stored: Partial<Record<keyof A11yPrefs, unknown>>;
  try {
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return base;
    stored = parsed as Partial<Record<keyof A11yPrefs, unknown>>;
  } catch {
    return base;
  }
  return {
    dark: typeof stored.dark === 'boolean' ? stored.dark : base.dark,
    highContrast: typeof stored.highContrast === 'boolean' ? stored.highContrast : base.highContrast,
    reduceMotion: typeof stored.reduceMotion === 'boolean' ? stored.reduceMotion : base.reduceMotion,
    textSize: TEXT_SIZES.includes(stored.textSize as TextSize) ? (stored.textSize as TextSize) : base.textSize,
  };
}

/** Best-effort save (private mode or a full quota must never break the app). */
export function saveA11yPrefs(prefs: A11yPrefs): void {
  try {
    localStorage.setItem(A11Y_PREFS_KEY, JSON.stringify(prefs));
  } catch {
    /* storage unavailable: the preference simply lasts for this session */
  }
}
