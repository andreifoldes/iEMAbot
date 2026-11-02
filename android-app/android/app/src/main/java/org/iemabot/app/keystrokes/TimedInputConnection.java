package org.iemabot.app.keystrokes;

import android.os.SystemClock;
import android.view.KeyEvent;
import android.view.inputmethod.InputConnection;
import android.view.inputmethod.InputConnectionWrapper;
import android.view.inputmethod.TextAttribute;

import androidx.annotation.RequiresApi;

/**
 * Wraps the WebView's InputConnection to time soft-keyboard edits where they enter the app.
 *
 * The keyboard (a separate app) talks to the focused editor through these calls. Chromium's own
 * connection forwards them to the renderer, which only then fires `beforeinput` with its own
 * timeStamp, so DOM timings carry that hop's latency and jitter. Here each call is stamped with
 * SystemClock.uptimeMillis() on entry (Chromium runs the connection on its own IME thread, so the
 * stamp does not wait on the UI thread), and KeyEvents keep the time the keyboard gave them.
 *
 * Gboard and most keyboards type a word as a growing "composing" text (h, he, hel, …), which the
 * DOM only reports as coarse insertCompositionText ("other") events. Each composing update is
 * diffed against the previous one so a key becomes a letter/digit/… or backspace row. The
 * composing text is held only for that diff and never logged.
 *
 * Every call is passed on unchanged; this class only observes.
 */
class TimedInputConnection extends InputConnectionWrapper {
    /** The keyboard's current composing text, or null when only its length is known (see setComposingRegion). */
    private String composing = "";
    private int composingLength = 0;

    TimedInputConnection(InputConnection target) {
        super(target, false);
    }

    /** The stamp for a call arriving now (overridable so the diffing can be exercised off-device). */
    long now() {
        return SystemClock.uptimeMillis();
    }

    private void composingChanged(CharSequence text, long time) {
        String next = text == null ? "" : text.toString();
        String prev = composing;
        int delta = next.length() - composingLength;
        composing = next;
        composingLength = next.length();
        if (prev != null && prev.equals(next)) return;
        boolean grows = prev == null || next.startsWith(prev);
        boolean shrinks = prev == null || prev.startsWith(next);
        if (delta > 0 && grows) {
            // The key(s) typed are at the end of the word (prev unknown: assume one, at the end).
            ImeTimingLog.inserted(prev == null ? next.substring(next.offsetByCodePoints(next.length(), -1)) : next.substring(prev.length()), time);
        } else if (delta < 0 && shrinks) {
            ImeTimingLog.record("backspace", time);
        } else {
            ImeTimingLog.record("other", time); // a suggestion or correction replaced the word
        }
    }

    private void committed(CharSequence text, long time) {
        String c = text == null ? "" : text.toString();
        if (composingLength == 0) {
            ImeTimingLog.inserted(c, time);
        } else if (composing != null && c.startsWith(composing)) {
            // The word is finalised as typed; anything after it (e.g. the space that ended it) is a key.
            if (c.length() > composing.length()) ImeTimingLog.inserted(c.substring(composing.length()), time);
        } else if (!(composing == null && c.length() == composingLength)) {
            ImeTimingLog.record("other", time); // autocorrect or a picked suggestion
        }
        composing = "";
        composingLength = 0;
    }

    private void composingReset() {
        composing = "";
        composingLength = 0;
    }

    @Override
    public boolean setComposingText(CharSequence text, int newCursorPosition) {
        composingChanged(text, now());
        return super.setComposingText(text, newCursorPosition);
    }

    @RequiresApi(33)
    @Override
    public boolean setComposingText(CharSequence text, int newCursorPosition, TextAttribute textAttribute) {
        composingChanged(text, now());
        return super.setComposingText(text, newCursorPosition, textAttribute);
    }

    @Override
    public boolean commitText(CharSequence text, int newCursorPosition) {
        committed(text, now());
        return super.commitText(text, newCursorPosition);
    }

    @RequiresApi(33)
    @Override
    public boolean commitText(CharSequence text, int newCursorPosition, TextAttribute textAttribute) {
        committed(text, now());
        return super.commitText(text, newCursorPosition, textAttribute);
    }

    @Override
    public boolean setComposingRegion(int start, int end) {
        // The keyboard re-opened an existing word: its text is not known here, only its length.
        composing = null;
        composingLength = Math.abs(end - start);
        return super.setComposingRegion(start, end);
    }

    @RequiresApi(33)
    @Override
    public boolean setComposingRegion(int start, int end, TextAttribute textAttribute) {
        composing = null;
        composingLength = Math.abs(end - start);
        return super.setComposingRegion(start, end, textAttribute);
    }

    @Override
    public boolean finishComposingText() {
        composingReset();
        return super.finishComposingText();
    }

    @RequiresApi(34)
    @Override
    public boolean replaceText(int start, int end, CharSequence text, int newCursorPosition, TextAttribute textAttribute) {
        ImeTimingLog.record("other", now());
        composingReset();
        return super.replaceText(start, end, text, newCursorPosition, textAttribute);
    }

    @Override
    public boolean deleteSurroundingText(int beforeLength, int afterLength) {
        deleted(beforeLength, afterLength, now());
        return super.deleteSurroundingText(beforeLength, afterLength);
    }

    @Override
    public boolean deleteSurroundingTextInCodePoints(int beforeLength, int afterLength) {
        deleted(beforeLength, afterLength, now());
        return super.deleteSurroundingTextInCodePoints(beforeLength, afterLength);
    }

    private static void deleted(int before, int after, long time) {
        if (before > 0) ImeTimingLog.record("backspace", time);
        else if (after > 0) ImeTimingLog.record("delete", time);
    }

    @Override
    public boolean sendKeyEvent(KeyEvent event) {
        if (event.getAction() == KeyEvent.ACTION_DOWN && event.getRepeatCount() == 0) {
            long n = now();
            long t = event.getEventTime();
            // The keyboard's own stamp (same uptime clock) is closest to the touch; distrust a stale one.
            ImeTimingLog.record(classifyKey(event), t > 0 && Math.abs(n - t) < 5000 ? t : n);
        }
        return super.sendKeyEvent(event);
    }

    private static String classifyKey(KeyEvent event) {
        switch (event.getKeyCode()) {
            case KeyEvent.KEYCODE_DEL:
                return "backspace";
            case KeyEvent.KEYCODE_FORWARD_DEL:
                return "delete";
            case KeyEvent.KEYCODE_ENTER:
            case KeyEvent.KEYCODE_NUMPAD_ENTER:
            case KeyEvent.KEYCODE_TAB:
            case KeyEvent.KEYCODE_SPACE:
                return "whitespace";
            case KeyEvent.KEYCODE_DPAD_LEFT:
            case KeyEvent.KEYCODE_DPAD_RIGHT:
            case KeyEvent.KEYCODE_DPAD_UP:
            case KeyEvent.KEYCODE_DPAD_DOWN:
            case KeyEvent.KEYCODE_MOVE_HOME:
            case KeyEvent.KEYCODE_MOVE_END:
            case KeyEvent.KEYCODE_PAGE_UP:
            case KeyEvent.KEYCODE_PAGE_DOWN:
                return "navigation";
            default:
                if (KeyEvent.isModifierKey(event.getKeyCode())) return "modifier";
                int cp = event.getUnicodeChar();
                return cp > 0 ? ImeTimingLog.classify(cp) : "other";
        }
    }
}
