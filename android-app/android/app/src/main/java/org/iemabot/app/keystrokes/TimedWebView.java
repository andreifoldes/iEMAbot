package org.iemabot.app.keystrokes;

import android.content.Context;
import android.util.AttributeSet;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputConnection;

import com.getcapacitor.CapacitorWebView;

/**
 * Capacitor's WebView with its keyboard InputConnection wrapped in a TimedInputConnection, so the
 * keystroke recorder gets soft-keyboard timings from the app instead of the renderer. Swapped in by
 * res/layout/capacitor_bridge_layout_main.xml, which overrides Capacitor's layout of the same name.
 */
public class TimedWebView extends CapacitorWebView {
    public TimedWebView(Context context, AttributeSet attrs) {
        super(context, attrs);
    }

    @Override
    public InputConnection onCreateInputConnection(EditorInfo outAttrs) {
        InputConnection ic = super.onCreateInputConnection(outAttrs);
        return ic == null ? null : new TimedInputConnection(ic);
    }
}
