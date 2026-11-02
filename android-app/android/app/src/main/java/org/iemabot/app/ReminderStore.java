package org.iemabot.app;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;

import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;

/**
 * The reminders the web layer planned (web-pwa/src/lib/notificationPlan.ts), kept on the phone and armed
 * as exact alarms. Survives the app being closed; ReminderReceiver re-arms them after a reboot or update.
 * Each entry: {id, at, deadline, title, body, key}.
 */
final class ReminderStore {
    static final String PREFS = "esmira_reminders";
    private static final String ITEMS = "items";
    private static final String FIRED = "fired";
    private static final String SHOWN = "shown";
    /** How long a shown occurrence is remembered, as the PWA's service worker does (sw.ts swRecordShown). */
    static final long SHOWN_TTL_MS = 48L * 3600 * 1000;
    /** After a reboot, a reminder missed by at most this much is still shown; older ones are dropped. */
    static final long LATE_GRACE_MS = 15 * 60 * 1000;

    private ReminderStore() {}

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    static synchronized JSONObject all(Context ctx) {
        try {
            return new JSONObject(prefs(ctx).getString(ITEMS, "{}"));
        } catch (JSONException e) {
            return new JSONObject();
        }
    }

    private static void save(Context ctx, JSONObject items) {
        prefs(ctx).edit().putString(ITEMS, items.toString()).apply();
    }

    /** Store and arm one reminder (replacing an entry with the same id). */
    static synchronized void put(Context ctx, JSONObject item) throws JSONException {
        JSONObject items = all(ctx);
        items.put(String.valueOf(item.getInt("id")), item);
        save(ctx, items);
        arm(ctx, item.getInt("id"), item.getLong("at"));
    }

    /** Disarm and forget one reminder; returns it, or null when it was not planned. */
    static synchronized JSONObject remove(Context ctx, int id) {
        JSONObject items = all(ctx);
        JSONObject item = items.optJSONObject(String.valueOf(id));
        items.remove(String.valueOf(id));
        save(ctx, items);
        disarm(ctx, id);
        return item;
    }

    static synchronized List<Integer> ids(Context ctx) {
        List<Integer> out = new ArrayList<>();
        Iterator<String> it = all(ctx).keys();
        while (it.hasNext()) {
            try { out.add(Integer.parseInt(it.next())); } catch (NumberFormatException ignored) { }
        }
        return out;
    }

    /**
     * Record that the occurrence `key` is being shown; false if it already was within SHOWN_TTL_MS (then it
     * must not be shown again, like a re-delivered web push). Prunes old entries.
     */
    static synchronized boolean markShown(Context ctx, String key) {
        if (key == null || key.isEmpty()) return true;
        long now = System.currentTimeMillis();
        JSONObject shown;
        try {
            shown = new JSONObject(prefs(ctx).getString(SHOWN, "{}"));
        } catch (JSONException e) {
            shown = new JSONObject();
        }
        if (now - shown.optLong(key, -SHOWN_TTL_MS - 1) < SHOWN_TTL_MS) return false;
        List<String> stale = new ArrayList<>();
        Iterator<String> it = shown.keys();
        while (it.hasNext()) {
            String k = it.next();
            if (now - shown.optLong(k, 0) >= SHOWN_TTL_MS) stale.add(k);
        }
        for (String k : stale) shown.remove(k);
        try {
            shown.put(key, now);
        } catch (JSONException ignored) { }
        prefs(ctx).edit().putString(SHOWN, shown.toString()).apply();
        return true;
    }

    /** One more reminder was shown since the app last asked (for the researcher's funnel). */
    static synchronized void countFired(Context ctx) {
        prefs(ctx).edit().putInt(FIRED, prefs(ctx).getInt(FIRED, 0) + 1).apply();
    }

    /** Reminders shown since the last call, and reset. */
    static synchronized int takeFired(Context ctx) {
        int n = prefs(ctx).getInt(FIRED, 0);
        prefs(ctx).edit().putInt(FIRED, 0).apply();
        return n;
    }

    /** After a reboot or an app update (alarms do not survive those): arm what is still ahead. */
    static synchronized void rearm(Context ctx) {
        long now = System.currentTimeMillis();
        for (Integer id : ids(ctx)) {
            JSONObject item = all(ctx).optJSONObject(String.valueOf(id));
            if (item == null) continue;
            long at = item.optLong("at", 0);
            long deadline = item.optLong("deadline", 0);
            if (at < now - LATE_GRACE_MS || (deadline > 0 && deadline <= now)) remove(ctx, id);
            else arm(ctx, id, Math.max(at, now + 1000));
        }
    }

    private static PendingIntent alarmIntent(Context ctx, int id, int flags) {
        Intent intent = new Intent(ctx, ReminderReceiver.class)
                .setAction(ReminderReceiver.ACTION_FIRE)
                .putExtra(ReminderReceiver.EXTRA_ID, id);
        return PendingIntent.getBroadcast(ctx, id, intent, flags | PendingIntent.FLAG_IMMUTABLE);
    }

    private static void arm(Context ctx, int id, long at) {
        AlarmManager am = ctx.getSystemService(AlarmManager.class);
        if (am == null) return;
        PendingIntent pi = alarmIntent(ctx, id, PendingIntent.FLAG_UPDATE_CURRENT);
        // USE_EXACT_ALARM (manifest) is granted on Android 13+; on 12 without the special permission an
        // inexact alarm (still allowed while idle) is the best Android offers.
        if (Build.VERSION.SDK_INT >= 31 && !am.canScheduleExactAlarms()) {
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi);
        } else {
            am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi);
        }
    }

    private static void disarm(Context ctx, int id) {
        AlarmManager am = ctx.getSystemService(AlarmManager.class);
        PendingIntent pi = alarmIntent(ctx, id, PendingIntent.FLAG_NO_CREATE);
        if (am != null && pi != null) {
            am.cancel(pi);
            pi.cancel();
        }
    }
}
