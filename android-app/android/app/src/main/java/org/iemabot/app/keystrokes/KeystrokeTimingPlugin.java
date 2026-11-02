package org.iemabot.app.keystrokes;

import android.os.SystemClock;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * JS bridge for native keystroke timing (web-pwa/src/lib/nativeKeystrokes.ts). start() opens a
 * recording while a typed answer is written and returns its session id; stop({session}) closes it
 * and returns the IME-timed rows as {cls, t} with t in SystemClock.uptimeMillis(). Both return
 * `now` on that clock, so the web layer can measure the offset to its own event clock and line the
 * rows up with its focus markers.
 */
@CapacitorPlugin(name = "KeystrokeTiming")
public class KeystrokeTimingPlugin extends Plugin {
    @PluginMethod
    public void start(PluginCall call) {
        int session = ImeTimingLog.start();
        JSObject res = new JSObject();
        res.put("session", session);
        res.put("now", SystemClock.uptimeMillis());
        call.resolve(res);
    }

    @PluginMethod
    public void stop(PluginCall call) {
        JSArray rows = new JSArray();
        for (ImeTimingLog.Row r : ImeTimingLog.stop(call.getInt("session", 0))) {
            JSObject row = new JSObject();
            row.put("cls", r.cls);
            row.put("t", r.time);
            rows.put(row);
        }
        JSObject res = new JSObject();
        res.put("rows", rows);
        res.put("now", SystemClock.uptimeMillis());
        call.resolve(res);
    }
}
