package org.iemabot.app.sensing;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/**
 * What the study asks this device to sense, as handed over by the web layer (PhoneSensingPlugin.configure)
 * and kept in SharedPreferences so the background parts (alarms, worker, receivers) can run without the app.
 * Mirrors the study fields phoneSensing* (src/frontend/ts/data/study/Study.ts).
 */
final class SensingConfig {
    static final String PREFS = "esmira_sensing";

    final String server;
    final long studyId;
    final String userId;
    final String accessKey;
    /** Which device this is (handed over by the web layer); "" when unknown. Sent so a study that allows an id on one device only can tell. */
    final String deviceToken;
    final boolean light;
    final boolean activity;
    final boolean accelerometer;
    final int accelBeforeMin;
    final int accelAfterMin;
    final int accelHz;
    /** "", "daily", "events" or "both" */
    final String usage;
    /** When batches may go over a metered network (mobile data): "any", "wifi_preferred" or "wifi_only". */
    final String uploadNetwork;
    /** With "wifi_preferred": how long a batch waits for an unmetered network before mobile data is used. */
    final int wifiFallbackHours;
    /** Upcoming scheduled prompts (epoch ms, ascending): the accelerometer records around these. */
    final List<Long> promptTimes;

    private SensingConfig(SharedPreferences p) {
        server = p.getString("server", null);
        studyId = p.getLong("studyId", 0);
        userId = p.getString("userId", null);
        accessKey = p.getString("accessKey", "");
        deviceToken = p.getString("deviceToken", "");
        light = p.getBoolean("light", false);
        activity = p.getBoolean("activity", false);
        accelerometer = p.getBoolean("accelerometer", false);
        accelBeforeMin = p.getInt("accelBeforeMin", 10);
        accelAfterMin = p.getInt("accelAfterMin", 10);
        accelHz = p.getInt("accelHz", 20);
        usage = p.getString("usage", "");
        uploadNetwork = normalizeNetwork(p.getString("uploadNetwork", null));
        wifiFallbackHours = clampFallbackHours(p.getInt("wifiFallbackHours", 24));
        List<Long> times = new ArrayList<>();
        try {
            JSONArray a = new JSONArray(p.getString("promptTimes", "[]"));
            for (int i = 0; i < a.length(); i++) times.add(a.getLong(i));
        } catch (Exception ignored) {
            // a broken list only costs the accelerometer windows until the app configures again
        }
        Collections.sort(times);
        promptTimes = times;
    }

    static String normalizeNetwork(String n) {
        return "any".equals(n) || "wifi_only".equals(n) ? n : "wifi_preferred";
    }

    static int clampFallbackHours(int h) {
        return Math.max(1, Math.min(168, h));
    }

    /** Whether a batch that has been waiting `waitedMs` may be sent over a metered network now. */
    boolean mayUseMetered(long waitedMs) {
        if ("any".equals(uploadNetwork)) return true;
        if ("wifi_only".equals(uploadNetwork)) return false;
        return waitedMs >= wifiFallbackHours * 3_600_000L;
    }

    static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    /** The current configuration, or null when this device is not sensing for anyone. */
    static SensingConfig load(Context ctx) {
        SensingConfig c = new SensingConfig(prefs(ctx));
        if (c.server == null || c.userId == null || c.studyId <= 0 || !c.anyStream()) return null;
        return c;
    }

    boolean usageDaily() {
        return "daily".equals(usage) || "both".equals(usage);
    }

    boolean usageEvents() {
        return "events".equals(usage) || "both".equals(usage);
    }

    /** One row per opened / submitted questionnaire (see PhoneSensingStore.php for when the server keeps it). */
    boolean prompts() {
        return light || activity || accelerometer;
    }

    boolean anyStream() {
        return prompts() || usageDaily() || usageEvents();
    }

    long accelBeforeMs() {
        return accelBeforeMin * 60000L;
    }

    long accelAfterMs() {
        return accelAfterMin * 60000L;
    }
}
