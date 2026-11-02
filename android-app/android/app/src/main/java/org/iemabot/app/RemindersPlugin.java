package org.iemabot.app;

import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * JS bridge for the reminders planned on the phone (web-pwa/src/lib/nativeReminders.ts). Replaces
 * @capacitor/local-notifications for posting, because that plugin gives every notification its own id and
 * so stacks them, where the PWA shows one at a time (see ReminderNotifier).
 */
@CapacitorPlugin(name = "Reminders")
public class RemindersPlugin extends Plugin {

    /** {notifications: [{id, at, deadline, title, body, key}]}: store and arm each (replacing one with the same id). */
    @PluginMethod
    public void schedule(PluginCall call) {
        JSArray list = call.getArray("notifications");
        try {
            for (int i = 0; list != null && i < list.length(); i++) {
                JSONObject in = list.getJSONObject(i);
                JSONObject item = new JSONObject();
                item.put("id", in.getInt("id"));
                item.put("at", in.getLong("at"));
                // When the notification is taken down again (its window closes); 0 = no end.
                item.put("deadline", in.optLong("deadline", 0));
                item.put("title", in.optString("title"));
                item.put("body", in.optString("body"));
                item.put("key", in.optString("key"));
                ReminderStore.put(getContext(), item);
            }
            call.resolve();
        } catch (JSONException e) {
            call.reject("Invalid reminder: " + e.getMessage());
        }
    }

    /** {ids: [..]}: disarm and forget. */
    @PluginMethod
    public void cancel(PluginCall call) {
        JSArray ids = call.getArray("ids");
        for (int i = 0; ids != null && i < ids.length(); i++) {
            ReminderStore.remove(getContext(), ids.optInt(i));
        }
        call.resolve();
    }

    /** {notifications: [{id, at, deadline}]} still to come (no deadline: armed by an older build). */
    @PluginMethod
    public void getPending(PluginCall call) {
        JSONObject all = ReminderStore.all(getContext());
        JSONArray out = new JSONArray();
        for (Integer id : ReminderStore.ids(getContext())) {
            JSONObject item = all.optJSONObject(String.valueOf(id));
            if (item == null) continue;
            JSObject row = new JSObject();
            row.put("id", id);
            row.put("at", item.optLong("at"));
            if (item.has("deadline")) row.put("deadline", item.optLong("deadline"));
            out.put(row);
        }
        JSObject res = new JSObject();
        res.put("notifications", out);
        call.resolve(res);
    }

    /** {count}: reminders shown since the last call (the funnel's "arrived" for the Android app). */
    @PluginMethod
    public void takeFired(PluginCall call) {
        JSObject res = new JSObject();
        res.put("count", ReminderStore.takeFired(getContext()));
        call.resolve(res);
    }

    /** Take down the notification that is showing. */
    @PluginMethod
    public void clearShown(PluginCall call) {
        ReminderNotifier.clear(getContext());
        call.resolve();
    }

    /**
     * Opens this app's notification settings, for when Android no longer shows the permission dialog (declined
     * twice); the web layer re-reads the permission when the app is visible again.
     */
    @PluginMethod
    public void openNotificationSettings(PluginCall call) {
        Context ctx = getContext();
        Intent intent = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, ctx.getPackageName())
                : new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", ctx.getPackageName(), null));
        try {
            ctx.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        } catch (Exception e) {
            try {
                ctx.startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", ctx.getPackageName(), null))
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
            } catch (Exception e2) {
                call.reject("Notification settings are not available on this device");
                return;
            }
        }
        call.resolve();
    }
}
