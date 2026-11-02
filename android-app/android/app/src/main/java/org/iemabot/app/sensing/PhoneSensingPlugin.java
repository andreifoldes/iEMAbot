package org.iemabot.app.sensing;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

import org.json.JSONArray;

/**
 * JS bridge for phone sensing (web-pwa/src/lib/phoneSensing.ts). The web layer hands over the study's
 * phoneSensing* settings and the upcoming prompt times on every app start (configure), reports each
 * opened / submitted questionnaire (promptEvent), and asks for the permissions the study needs.
 */
@CapacitorPlugin(
        name = "PhoneSensing",
        permissions = { @Permission(alias = "activity", strings = { Manifest.permission.ACTIVITY_RECOGNITION }) }
)
public class PhoneSensingPlugin extends Plugin {
    /** How long the accelerometer keeps recording after a questionnaire was opened if it is never submitted. */
    private static final long OPEN_QUESTIONNAIRE_MAX_MS = 30 * 60000L;
    private static final long LIGHT_SAMPLE_MS = 1500;

    @PluginMethod
    public void configure(PluginCall call) {
        String server = call.getString("server");
        String userId = call.getString("userId");
        long studyId = call.getData().optLong("studyId", 0);
        if (server == null || userId == null || studyId <= 0 || !server.startsWith("http")) {
            call.reject("server, userId and studyId are required");
            return;
        }
        if (!server.endsWith("/")) server += "/";
        Context ctx = getContext();
        SharedPreferences prefs = SensingConfig.prefs(ctx);
        boolean sameTarget = server.equals(prefs.getString("server", null))
                && userId.equals(prefs.getString("userId", null))
                && studyId == prefs.getLong("studyId", 0);
        if (!sameTarget) {
            // Another participant or study: nothing collected for the previous one belongs to this one.
            prefs.edit().clear().commit();
            SensingStore.clear(ctx);
        }

        String usage = call.getString("usage", "");
        if (!"daily".equals(usage) && !"events".equals(usage) && !"both".equals(usage)) usage = "";
        JSONArray times = new JSONArray();
        JSArray promptTimes = call.getArray("promptTimes", new JSArray());
        for (int i = 0; i < promptTimes.length(); i++) {
            long t = promptTimes.optLong(i, 0);
            if (t > 0) times.put(t);
        }
        SharedPreferences.Editor e = prefs.edit()
                .putString("server", server)
                .putString("userId", userId)
                .putLong("studyId", studyId)
                .putString("accessKey", call.getString("accessKey", ""))
                .putBoolean("light", Boolean.TRUE.equals(call.getBoolean("light", false)))
                .putBoolean("activity", Boolean.TRUE.equals(call.getBoolean("activity", false)))
                .putBoolean("accelerometer", Boolean.TRUE.equals(call.getBoolean("accelerometer", false)))
                .putInt("accelBeforeMin", clamp(call.getInt("accelBeforeMin", 10), 0, 60))
                .putInt("accelAfterMin", clamp(call.getInt("accelAfterMin", 10), 0, 60))
                .putInt("accelHz", clamp(call.getInt("accelHz", 20), 1, 100))
                .putString("usage", usage)
                .putString("uploadNetwork", SensingConfig.normalizeNetwork(call.getString("uploadNetwork")))
                .putInt("wifiFallbackHours", SensingConfig.clampFallbackHours(call.getInt("wifiFallbackHours", 24)))
                .putString("promptTimes", times.toString());
        // App usage is read from when it was first enabled on this device (consent), never further back.
        if (!usage.isEmpty() && !prefs.contains(UsageCollector.KEY_SINCE))
            e.putLong(UsageCollector.KEY_SINCE, System.currentTimeMillis());
        e.commit();

        if (SensingConfig.load(ctx) == null) {
            PhoneSensing.stopAll(ctx); // the study senses nothing (any more)
        } else {
            PhoneSensing.apply(ctx, true);
            PhoneSensing.uploadSoon(ctx);
        }
        call.resolve(status(ctx));
    }

    private static int clamp(Integer v, int min, int max) {
        return Math.max(min, Math.min(max, v == null ? min : v));
    }

    /** The participant switched "Wait for Wi-Fi" in the Phone sensing panel (the web layer checks the study allows it). */
    @PluginMethod
    public void setUploadNetwork(PluginCall call) {
        Context ctx = getContext();
        SensingConfig.prefs(ctx).edit()
                .putString("uploadNetwork", SensingConfig.normalizeNetwork(call.getString("uploadNetwork")))
                .putInt("wifiFallbackHours", SensingConfig.clampFallbackHours(call.getInt("wifiFallbackHours", 24)))
                .commit();
        if (SensingConfig.load(ctx) != null) PhoneSensing.uploadSoon(ctx);
        call.resolve(status(ctx));
    }

    @PluginMethod
    public void status(PluginCall call) {
        call.resolve(status(getContext()));
    }

    private JSObject status(Context ctx) {
        JSObject s = new JSObject();
        s.put("configured", SensingConfig.load(ctx) != null);
        s.put("lightSensor", LightSampler.available(ctx));
        s.put("accelerometer", AccelerometerService.available(ctx));
        s.put("playServices", ActivityTracker.playServicesAvailable(ctx));
        s.put("activityPermission", activityPermission());
        s.put("usageAccess", UsageCollector.hasAccess(ctx));
        s.put("pendingBatches", SensingStore.pendingCount(ctx));
        SharedPreferences prefs = SensingConfig.prefs(ctx);
        s.put("lastUpload", prefs.getLong(SensingUploadWorker.KEY_LAST_UPLOAD, 0));
        s.put("uploadNetwork", SensingConfig.normalizeNetwork(prefs.getString("uploadNetwork", null)));
        s.put("wifiFallbackHours", SensingConfig.clampFallbackHours(prefs.getInt("wifiFallbackHours", 24)));
        return s;
    }

    /** "granted", "denied" or "prompt" (Android asks; before Android 10 it is granted at install). */
    private String activityPermission() {
        if (Build.VERSION.SDK_INT < 29) return "granted";
        PermissionState state = getPermissionState("activity");
        if (state == PermissionState.GRANTED) return "granted";
        if (state == PermissionState.DENIED) return "denied";
        return "prompt";
    }

    @PluginMethod
    public void requestActivityPermission(PluginCall call) {
        if ("granted".equals(activityPermission())) {
            activityPermissionResult(call);
            return;
        }
        requestPermissionForAlias("activity", call, "activityPermissionResult");
    }

    @PermissionCallback
    private void activityPermissionResult(PluginCall call) {
        Context ctx = getContext();
        ActivityTracker.sync(ctx, SensingConfig.load(ctx));
        call.resolve(status(ctx));
    }

    /** Opens Android's Usage access screen; the web layer re-reads the status when the app is visible again. */
    @PluginMethod
    public void openUsageAccessSettings(PluginCall call) {
        Context ctx = getContext();
        Intent intent = new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS)
                .setData(Uri.parse("package:" + ctx.getPackageName()))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            ctx.startActivity(intent); // straight to this app's switch where supported (Android 10+)
        } catch (Exception e) {
            try {
                ctx.startActivity(new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
            } catch (Exception e2) {
                call.reject("Usage access settings are not available on this device");
                return;
            }
        }
        call.resolve();
    }

    /**
     * A questionnaire was opened ("opened") or finished ("submitted" / "closed"). Records a `prompts` row
     * with the ambient light and the current activity, and keeps the accelerometer recording while the
     * questionnaire is open and accelAfterMin after it.
     */
    @PluginMethod
    public void promptEvent(PluginCall call) {
        Context ctx = getContext();
        SensingConfig cfg = SensingConfig.load(ctx);
        if (cfg == null || !cfg.prompts()) {
            call.resolve(new JSObject());
            return;
        }
        String event = call.getString("event", "opened");
        long questionnaireId = call.getData().optLong("questionnaireId", 0);
        String questionnaire = call.getString("questionnaire", "");
        long now = System.currentTimeMillis();

        if (cfg.accelerometer) {
            boolean opened = "opened".equals(event);
            AccelerometerService.request(ctx, now + cfg.accelAfterMs() + (opened ? OPEN_QUESTIONNAIRE_MAX_MS : 0), true);
        }

        new Thread(() -> {
            Float lux = cfg.light ? LightSampler.sample(ctx, LIGHT_SAMPLE_MS) : null;
            SharedPreferences prefs = SensingConfig.prefs(ctx);
            String activity = cfg.activity ? prefs.getString(ActivityTracker.KEY_CURRENT, "") : "";
            long since = cfg.activity && !activity.isEmpty() ? prefs.getLong(ActivityTracker.KEY_SINCE, 0) : 0;
            JSONArray row = new JSONArray()
                    .put(now)
                    .put(event)
                    .put(questionnaireId)
                    .put(questionnaire == null ? "" : questionnaire)
                    .put(lux == null ? "" : String.valueOf(lux))
                    .put(activity)
                    .put(since > 0 ? String.valueOf(since) : "");
            SensingStore.append(ctx, "prompts", row);
            if (!"opened".equals(event)) PhoneSensing.uploadSoon(ctx);
            JSObject res = new JSObject();
            if (lux != null) res.put("lightLux", lux.doubleValue());
            if (!activity.isEmpty()) res.put("activity", activity);
            call.resolve(res);
        }, "prompt-event").start();
    }

    /** Sign-out / leaving the study. */
    @PluginMethod
    public void cancel(PluginCall call) {
        PhoneSensing.stopAll(getContext());
        call.resolve(new JSObject());
    }
}
