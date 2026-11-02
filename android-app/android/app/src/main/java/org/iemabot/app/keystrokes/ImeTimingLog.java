package org.iemabot.app.keystrokes;

import java.util.ArrayList;
import java.util.List;

/**
 * Buffer of soft-keyboard edits timed at the app's InputConnection (TimedInputConnection), for the
 * keystroke recorder of `record_keystrokes` questions (web-pwa/src/lib/nativeKeystrokes.ts).
 *
 * Rows hold a character *class* and a SystemClock.uptimeMillis() stamp only: the typed characters
 * are classified here and dropped, never stored, matching the web recorder's privacy rule
 * (web-pwa/src/lib/keystrokeCapture.ts). Recording runs only between start() and stop(), i.e.
 * while the writer is open.
 */
public final class ImeTimingLog {
    /** Far beyond any answer; stops a writer left open for hours from growing without bound. */
    private static final int MAX_ROWS = 200_000;

    public static final class Row {
        public final String cls;
        public final long time;

        Row(String cls, long time) {
            this.cls = cls;
            this.time = time;
        }
    }

    private static final Object LOCK = new Object();
    private static final ArrayList<Row> rows = new ArrayList<>();
    private static boolean recording = false;
    private static int session = 0;

    private ImeTimingLog() {}

    /** Start a fresh recording; returns its session id for stop(). */
    public static int start() {
        synchronized (LOCK) {
            rows.clear();
            recording = true;
            return ++session;
        }
    }

    /**
     * Stop recording and hand over what was captured (the buffer is emptied). A stale id (a writer
     * that closed after a newer one opened) gets nothing and leaves the newer recording running.
     */
    public static List<Row> stop(int id) {
        synchronized (LOCK) {
            if (id != session) return new ArrayList<>();
            recording = false;
            List<Row> out = new ArrayList<>(rows);
            rows.clear();
            return out;
        }
    }

    static void record(String cls, long time) {
        synchronized (LOCK) {
            if (!recording || rows.size() >= MAX_ROWS) return;
            rows.add(new Row(cls, time));
        }
    }

    /** Record text the keyboard inserted: one character is classified, anything longer is "other". */
    static void inserted(CharSequence text, long time) {
        if (text == null || text.length() == 0) return;
        int cp = Character.codePointAt(text, 0);
        record(Character.charCount(cp) == text.length() ? classify(cp) : "other", time);
    }

    /** Same classes as classifyKey() in keystrokeCapture.ts (an emoji, two UTF-16 units there, is "other"). */
    static String classify(int cp) {
        if (Character.charCount(cp) > 1) return "other";
        if (Character.isWhitespace(cp) || Character.isSpaceChar(cp)) return "whitespace";
        if (cp >= '0' && cp <= '9') return "digit";
        if (Character.isLetter(cp)) return "letter";
        return "punctuation";
    }
}
