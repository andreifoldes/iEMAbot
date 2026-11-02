package org.iemabot.app.sensing;

import android.Manifest;
import android.app.AppOpsManager;
import android.app.usage.UsageEvents;
import android.app.usage.UsageStatsManager;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Process;

import org.json.JSONArray;

import java.util.Calendar;
import java.util.HashMap;
import java.util.Locale;
import java.util.Map;
import java.util.TreeSet;

/**
 * App usage ("screen time") from Android's usage statistics, collected after the fact by the upload
 * worker (Android keeps the raw events for about a week). Needs the special Usage access permission,
 * which the participant switches on in Android's settings. Only package names are recorded.
 *
 *  usage_events  every app coming to the foreground / leaving it, screen on / off, unlock, shutdown,
 *                startup, from the moment usage collection was enabled on this device
 *  usage_daily   per local day and app: foreground time and launches (computed from the same events)
 *  screen_daily  per local day: screen-on time, screen-ons, unlocks and how much of the day was observed
 *                (the first day starts when collection was enabled)
 * Daily rows are written once a day is over.
 */
final class UsageCollector {
    static final String KEY_SINCE = "usageSince";
    private static final String KEY_EVENTS_CURSOR = "usageEventsCursor";
    private static final String KEY_DAILY_CURSOR = "usageDailyCursor";

    // UsageEvents.Event types (some constants only exist on newer SDKs, the values are stable).
    private static final int ACTIVITY_RESUMED = 1;
    private static final int ACTIVITY_PAUSED = 2;
    private static final int SCREEN_INTERACTIVE = 15;
    private static final int SCREEN_NON_INTERACTIVE = 16;
    private static final int KEYGUARD_SHOWN = 17;
    private static final int KEYGUARD_HIDDEN = 18;
    private static final int DEVICE_SHUTDOWN = 26;
    private static final int DEVICE_STARTUP = 27;

    /** Pausing one activity of an app and resuming another of the same app within this time is not a new launch. */
    private static final long SAME_APP_GAP_MS = 2000;
    /** How far before a day the events are read, to know what was in the foreground at midnight. */
    private static final long LOOKBEHIND_MS = 6 * 3600_000L;
    private static final long DAY_MS = 86400_000L;

    private UsageCollector() {}

    static boolean hasAccess(Context ctx) {
        AppOpsManager ops = (AppOpsManager) ctx.getSystemService(Context.APP_OPS_SERVICE);
        if (ops == null) return false;
        int mode = Build.VERSION.SDK_INT >= 29
                ? ops.unsafeCheckOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), ctx.getPackageName())
                : ops.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), ctx.getPackageName());
        if (mode == AppOpsManager.MODE_DEFAULT)
            return ctx.checkCallingOrSelfPermission(Manifest.permission.PACKAGE_USAGE_STATS) == PackageManager.PERMISSION_GRANTED;
        return mode == AppOpsManager.MODE_ALLOWED;
    }

    static String eventName(int type) {
        switch (type) {
            case ACTIVITY_RESUMED: return "app_foreground";
            case ACTIVITY_PAUSED: return "app_background";
            case SCREEN_INTERACTIVE: return "screen_on";
            case SCREEN_NON_INTERACTIVE: return "screen_off";
            case KEYGUARD_SHOWN: return "locked";
            case KEYGUARD_HIDDEN: return "unlocked";
            case DEVICE_SHUTDOWN: return "shutdown";
            case DEVICE_STARTUP: return "startup";
            default: return null;
        }
    }

    static void collect(Context ctx, SensingConfig cfg) {
        if (cfg == null || (!cfg.usageEvents() && !cfg.usageDaily()) || !hasAccess(ctx)) return;
        UsageStatsManager usm = (UsageStatsManager) ctx.getSystemService(Context.USAGE_STATS_SERVICE);
        if (usm == null) return;
        SharedPreferences prefs = SensingConfig.prefs(ctx);
        long now = System.currentTimeMillis();
        long since = prefs.getLong(KEY_SINCE, now);
        if (cfg.usageEvents()) collectEvents(ctx, usm, prefs, Math.max(since, prefs.getLong(KEY_EVENTS_CURSOR, since)), now);
        if (cfg.usageDaily()) collectDaily(ctx, usm, prefs, since, now);
    }

    private static void collectEvents(Context ctx, UsageStatsManager usm, SharedPreferences prefs, long from, long to) {
        if (to <= from) return;
        UsageEvents events = usm.queryEvents(from, to);
        UsageEvents.Event e = new UsageEvents.Event();
        while (events != null && events.hasNextEvent()) {
            events.getNextEvent(e);
            String name = eventName(e.getEventType());
            if (name == null || e.getTimeStamp() < from || e.getTimeStamp() >= to) continue;
            SensingStore.append(ctx, "usage_events", new JSONArray().put(e.getTimeStamp()).put(e.getPackageName()).put(name));
        }
        prefs.edit().putLong(KEY_EVENTS_CURSOR, to).apply();
    }

    private static long midnight(long time) {
        Calendar c = Calendar.getInstance();
        c.setTimeInMillis(time);
        c.set(Calendar.HOUR_OF_DAY, 0);
        c.set(Calendar.MINUTE, 0);
        c.set(Calendar.SECOND, 0);
        c.set(Calendar.MILLISECOND, 0);
        return c.getTimeInMillis();
    }

    private static long nextMidnight(long dayStart) {
        Calendar c = Calendar.getInstance();
        c.setTimeInMillis(dayStart);
        c.add(Calendar.DAY_OF_MONTH, 1); // not +24 h: days around a DST change are 23 or 25 hours long
        return midnight(c.getTimeInMillis());
    }

    private static void collectDaily(Context ctx, UsageStatsManager usm, SharedPreferences prefs, long since, long now) {
        long cursor = Math.max(since, prefs.getLong(KEY_DAILY_CURSOR, since));
        long today = midnight(now);
        // Android keeps the events for about a week; older days could only be reported incomplete.
        cursor = Math.max(cursor, midnight(today - 9 * DAY_MS));
        while (true) {
            long dayStart = midnight(cursor);
            long dayEnd = nextMidnight(dayStart);
            if (dayEnd > today) break;
            summarizeDay(ctx, usm, Math.max(cursor, dayStart), dayStart, dayEnd);
            cursor = dayEnd;
            prefs.edit().putLong(KEY_DAILY_CURSOR, cursor).apply();
        }
    }

    /** Foreground time per app etc. for [from, dayEnd), from the events around it. */
    private static void summarizeDay(Context ctx, UsageStatsManager usm, long from, long dayStart, long dayEnd) {
        DayTotals totals = new DayTotals(from, dayEnd);
        UsageEvents events = usm.queryEvents(Math.max(0, from - LOOKBEHIND_MS), dayEnd);
        UsageEvents.Event e = new UsageEvents.Event();
        while (events != null && events.hasNextEvent()) {
            events.getNextEvent(e);
            totals.add(e.getTimeStamp(), e.getEventType(), e.getPackageName());
        }
        totals.finish();

        String date = String.format(Locale.ROOT, "%1$tY-%1$tm-%1$td", dayStart);
        for (String pkg : new TreeSet<>(totals.packages())) {
            long fg = totals.foreground.containsKey(pkg) ? totals.foreground.get(pkg) : 0;
            int launches = totals.launches.containsKey(pkg) ? totals.launches.get(pkg) : 0;
            if (fg > 0 || launches > 0)
                SensingStore.append(ctx, "usage_daily", new JSONArray().put(date).put(pkg).put(fg).put(launches));
        }
        SensingStore.append(ctx, "screen_daily", new JSONArray().put(date).put(totals.screenOnMs)
                .put(totals.screenOnCount).put(totals.unlocks).put(dayEnd - from));
    }

    /** Folds a stream of usage events into one day's totals, clipping intervals to [from, to). */
    static final class DayTotals {
        final long from;
        final long to;
        final Map<String, Long> foreground = new HashMap<>();
        final Map<String, Integer> launches = new HashMap<>();
        long screenOnMs = 0;
        int screenOnCount = 0;
        int unlocks = 0;
        private final Map<String, Long> fgStart = new HashMap<>();
        private final Map<String, Long> pausedAt = new HashMap<>();
        private long screenOnSince = -1;

        DayTotals(long from, long to) {
            this.from = from;
            this.to = to;
        }

        TreeSet<String> packages() {
            TreeSet<String> all = new TreeSet<>(foreground.keySet());
            all.addAll(launches.keySet());
            return all;
        }

        private boolean inDay(long t) {
            return t >= from && t < to;
        }

        private void addForeground(String pkg, long start, long end) {
            long a = Math.max(start, from);
            long b = Math.min(end, to);
            if (b > a) foreground.put(pkg, (foreground.containsKey(pkg) ? foreground.get(pkg) : 0) + (b - a));
        }

        /** Close app sessions that were paused long enough ago not to be an in-app activity switch. */
        private void settle(long now) {
            for (String pkg : new TreeSet<>(pausedAt.keySet())) {
                long paused = pausedAt.get(pkg);
                if (now - paused >= SAME_APP_GAP_MS) {
                    pausedAt.remove(pkg);
                    Long start = fgStart.remove(pkg);
                    if (start != null) addForeground(pkg, start, paused);
                }
            }
        }

        void add(long t, int type, String pkg) {
            settle(t);
            switch (type) {
                case ACTIVITY_RESUMED:
                    if (pausedAt.remove(pkg) != null) break; // same app, next activity
                    if (!fgStart.containsKey(pkg)) {
                        fgStart.put(pkg, t);
                        if (inDay(t)) launches.put(pkg, (launches.containsKey(pkg) ? launches.get(pkg) : 0) + 1);
                    }
                    break;
                case ACTIVITY_PAUSED:
                    if (fgStart.containsKey(pkg)) pausedAt.put(pkg, t);
                    break;
                case SCREEN_INTERACTIVE:
                    if (screenOnSince < 0) {
                        screenOnSince = t;
                        if (inDay(t)) screenOnCount++;
                    }
                    break;
                case SCREEN_NON_INTERACTIVE:
                case DEVICE_SHUTDOWN:
                    if (screenOnSince >= 0) {
                        screenOnMs += Math.max(0, Math.min(t, to) - Math.max(screenOnSince, from));
                        screenOnSince = -1;
                    }
                    // Nothing stays in the foreground with the screen off.
                    for (Map.Entry<String, Long> s : fgStart.entrySet())
                        addForeground(s.getKey(), s.getValue(), pausedAt.containsKey(s.getKey()) ? pausedAt.get(s.getKey()) : t);
                    fgStart.clear();
                    pausedAt.clear();
                    break;
                case KEYGUARD_HIDDEN:
                    if (inDay(t)) unlocks++;
                    break;
                default:
                    break;
            }
        }

        /** Whatever is still open at the end of the day counts until midnight. */
        void finish() {
            settle(Long.MAX_VALUE);
            for (Map.Entry<String, Long> s : fgStart.entrySet()) addForeground(s.getKey(), s.getValue(), to);
            fgStart.clear();
            if (screenOnSince >= 0) {
                screenOnMs += Math.max(0, to - Math.max(screenOnSince, from));
                screenOnSince = -1;
            }
        }
    }
}
