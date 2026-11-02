package org.iemabot.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import org.json.JSONObject;

/** A planned reminder is due (ReminderStore alarm), or the phone rebooted / the app was updated. */
public class ReminderReceiver extends BroadcastReceiver {
    static final String ACTION_FIRE = "org.iemabot.app.REMINDER_DUE";
    static final String EXTRA_ID = "id";

    @Override
    public void onReceive(Context ctx, Intent intent) {
        String action = intent == null ? null : intent.getAction();
        if (ACTION_FIRE.equals(action)) {
            JSONObject item = ReminderStore.remove(ctx, intent.getIntExtra(EXTRA_ID, 0));
            if (item == null) return; // cancelled meanwhile
            if (ReminderNotifier.show(ctx, item.optString("title"), item.optString("body"), item.optString("key"), item.optLong("deadline", 0))) {
                ReminderStore.countFired(ctx);
            }
        } else if (Intent.ACTION_BOOT_COMPLETED.equals(action) || Intent.ACTION_MY_PACKAGE_REPLACED.equals(action)) {
            ReminderStore.rearm(ctx);
        }
    }
}
