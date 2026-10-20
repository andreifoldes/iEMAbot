/**
 * Privacy-preserving keystroke-dynamics recorder for the `record_keystrokes` input
 * (the voice-memo skip fallback: answer by typing, log the *dynamics* not the content).
 *
 * The literal characters are NEVER materialised in the log. Every key is bucketed into a
 * character *class* at capture time; the class + timings are all that leave the device.
 * The typed answer itself is stored separately (the textarea value, passed to `finish`).
 *
 * Two capture paths keep the three mobile-viable metrics — inter-key interval distribution,
 * correction/backspace rate, pause structure — available on every platform:
 *   • physical keyboard: keydown/keyup → full rows incl. hold/dwell (release − press).
 *   • soft keyboard (Android reports keyCode 229, `key` "Unidentified"/"Process", or an
 *     IME with no keydown at all): `beforeinput` → press-only rows; hold/release blank.
 * Focus loss and paste emit marker rows so offline analysis never treats away-time or a
 * paste as an inter-key interval.
 *
 * The log is a self-describing CSV: `class,hold,release,press` (seconds from t0 = first
 * observed event). Feature computation (percentiles, correction rate, pauses, sparsity
 * threshold) is done offline by the researcher — no on-device aggregation, no black box.
 *
 * Handlers take plain event-like objects (not real DOM events) so the whole thing is unit
 * testable under `node --test`; `KeystrokeRecorder.tsx` is the thin DOM adapter that forwards
 * real listeners here.
 */

export type KeystrokeClass =
  | 'letter'
  | 'digit'
  | 'whitespace'
  | 'punctuation'
  | 'backspace'
  | 'delete'
  | 'navigation'
  | 'modifier'
  | 'paste'
  | 'focus_lost'
  | 'focus_gained'
  | 'other';

/** How a session's rows were captured, so analysis can stratify (hold is physical-only). */
export type CaptureMode = 'physical' | 'soft' | 'mixed';

export interface KeyEventLike {
  key: string;
  code: string;
  keyCode?: number;
  timeStamp: number;
  isComposing?: boolean;
  repeat?: boolean;
}

export interface BeforeInputLike {
  inputType: string;
  data: string | null;
  timeStamp: number;
}

export interface KeystrokeResult {
  /** The classed event log as CSV text (`class,hold,release,press`). */
  csv: string;
  /** The typed answer content (kept entirely separate from the dynamics log). */
  transcript: string;
  captureMode: CaptureMode;
}

interface Row {
  cls: KeystrokeClass;
  press: number;
  release: number | null;
  hold: number | null;
}

const NAV_KEYS = new Set([
  'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
  'Home', 'End', 'PageUp', 'PageDown',
]);
const MODIFIER_KEYS = new Set([
  'Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'CapsLock', 'Fn', 'FnLock',
]);

/**
 * Bucket a key label into a character class WITHOUT retaining the character itself.
 * Only the returned class label is ever stored.
 */
export function classifyKey(key: string): KeystrokeClass {
  if (key === 'Backspace') return 'backspace';
  if (key === 'Delete') return 'delete';
  if (key === ' ' || key === 'Spacebar' || key === 'Enter' || key === 'Tab') return 'whitespace';
  if (NAV_KEYS.has(key)) return 'navigation';
  if (MODIFIER_KEYS.has(key)) return 'modifier';
  if (key.length === 1) {
    if (/[0-9]/.test(key)) return 'digit';
    if (/\p{L}/u.test(key)) return 'letter';
    return 'punctuation'; // any other single printable char (symbol/punctuation)
  }
  return 'other'; // named keys (F-keys, dead keys, Escape, composition, …)
}

/** Classify a soft-keyboard `beforeinput` from its inputType/data (char is classified, never stored). */
export function classifyInput(inputType: string, data: string | null): KeystrokeClass | null {
  switch (inputType) {
    case 'insertText':
      if (data == null || data.length === 0) return null;
      return data.length === 1 ? classifyKey(data) : 'other'; // multi-char = autocomplete/IME commit
    case 'insertLineBreak':
    case 'insertParagraph':
      return 'whitespace';
    case 'deleteContentBackward':
    case 'deleteWordBackward':
    case 'deleteSoftLineBackward':
      return 'backspace';
    case 'deleteContentForward':
    case 'deleteWordForward':
      return 'delete';
    case 'insertFromPaste':
    case 'insertFromPasteAsQuotation':
      return 'paste';
    default:
      return 'other'; // insertCompositionText, insertReplacementText, autocorrect, …
  }
}

function round3(n: number): number {
  return Math.round(n * 1000) / 1000;
}
function fmt(n: number | null): string {
  return n == null ? '' : String(round3(n));
}

export class KeystrokeRecorderCore {
  private rows: Row[] = [];
  /** Held keydowns awaiting a keyup, by `event.code`. */
  private pending = new Map<string, { cls: KeystrokeClass; press: number }>();
  private t0: number | null = null;
  /** Whether the most recent keydown was a usable physical key (covers the following beforeinput). */
  private lastKeydownUsable = false;
  private physicalUsed = false;
  private softUsed = false;
  /** Deduped focus state: the textarea blur/focus and document visibilitychange can both fire. */
  private focused = false;

  /** Seconds since t0 (t0 is anchored on the first observed event). */
  private rel(timeStamp: number): number {
    if (this.t0 == null) this.t0 = timeStamp;
    const s = (timeStamp - this.t0) / 1000;
    return s < 0 ? 0 : s;
  }

  /** A physical keydown reports a real key; Android soft keyboards report 229 / "Unidentified". */
  private isUsablePhysical(e: KeyEventLike): boolean {
    if (e.isComposing) return false;
    if (e.keyCode === 229) return false;
    return e.key !== 'Unidentified' && e.key !== 'Process' && e.key !== '';
  }

  onFocus(timeStamp: number): void {
    if (this.focused) return; // already focused — ignore the duplicate (blur/focus + visibilitychange)
    this.focused = true;
    this.rows.push({ cls: 'focus_gained', press: this.rel(timeStamp), release: null, hold: null });
  }

  onBlur(timeStamp: number): void {
    if (!this.focused) return;
    this.focused = false;
    // Drop dangling keydowns (no keyup) so away-time never becomes a bogus hold, then mark the gap.
    this.pending.clear();
    this.lastKeydownUsable = false;
    this.rows.push({ cls: 'focus_lost', press: this.rel(timeStamp), release: null, hold: null });
  }

  onKeyDown(e: KeyEventLike): void {
    if (e.repeat) return; // ignore auto-repeat while a key is held
    if (!this.isUsablePhysical(e)) {
      this.lastKeydownUsable = false; // soft path handles it via beforeinput
      return;
    }
    this.lastKeydownUsable = true;
    // Register (don't emit yet — wait for keyup to know the hold). One pending row per code.
    this.pending.set(e.code, { cls: classifyKey(e.key), press: this.rel(e.timeStamp) });
  }

  onKeyUp(e: KeyEventLike): void {
    this.lastKeydownUsable = false;
    const down = this.pending.get(e.code);
    if (!down) return;
    this.pending.delete(e.code);
    const release = this.rel(e.timeStamp);
    this.rows.push({ cls: down.cls, press: down.press, release, hold: round3(release - down.press) });
    this.physicalUsed = true;
  }

  onBeforeInput(e: BeforeInputLike): void {
    const cls = classifyInput(e.inputType, e.data);
    if (cls === null) return;
    // Paste is always marked, on either path (a paste boundary must never look like typing).
    if (cls === 'paste') {
      this.rows.push({ cls: 'paste', press: this.rel(e.timeStamp), release: null, hold: null });
      return;
    }
    // On desktop a usable keydown already covers this insertion (physical path records it with hold),
    // so skip to avoid double-counting. On soft keyboards there was no usable keydown → record here.
    if (this.lastKeydownUsable) return;
    this.rows.push({ cls, press: this.rel(e.timeStamp), release: null, hold: null });
    this.softUsed = true;
  }

  private captureMode(): CaptureMode {
    if (this.physicalUsed && this.softUsed) return 'mixed';
    if (this.softUsed) return 'soft';
    return 'physical';
  }

  /** Finalise: flush any keys still held (press-only), serialise the log, and take the transcript. */
  finish(transcript: string): KeystrokeResult {
    for (const down of this.pending.values()) {
      this.rows.push({ cls: down.cls, press: down.press, release: null, hold: null });
    }
    this.pending.clear();
    const header = 'class,hold,release,press';
    const body = this.rows.map((r) => `${r.cls},${fmt(r.hold)},${fmt(r.release)},${fmt(r.press)}`);
    return {
      csv: [header, ...body].join('\n') + '\n',
      transcript,
      captureMode: this.captureMode(),
    };
  }
}
