package org.iemabot.app;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationManagerCompat;
import androidx.work.Worker;
import androidx.work.WorkManager;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Scanner;
import java.util.TimeZone;

/**
 * Background poll for server-driven nudges (wearable-triggered prompts, and for app builds that do not plan
 * them themselves, follow-up reminders). Scheduled signal times are NOT handled here: the app plans those on
 * the device. Title, body and look come from the server's shared notification spec (NotificationSpec.php),
 * so they match the PWA's web push and the reminders planned on the device. No Google services,
 * no push service: a plain HTTPS POST to api/native_pending.php every ~15 minutes (WorkManager's minimum).
 * Doze and battery savers can defer a run, which is acceptable for these nudges.
 */
public class NudgeWorker extends Worker {
    static final String PREFS = "esmira_nudges";
    static final String WORK_NAME = "esmira-server-nudges";
    /** ESMira server protocol version this client speaks (Main::SERVER_VERSION). */
    private static final int PROTOCOL_VERSION = 12;

    public NudgeWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    @NonNull
    @Override
    public Result doWork() {
        Context ctx = getApplicationContext();
        SharedPreferences prefs = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String server = prefs.getString("server", null);
        String userId = prefs.getString("userId", null);
        long studyId = prefs.getLong("studyId", 0);
        if (server == null || userId == null || studyId <= 0) return Result.success();

        try {
            JSONObject body = new JSONObject();
            body.put("studyId", studyId);
            body.put("userId", userId);
            body.put("serverVersion", PROTOCOL_VERSION);
            // Same sign convention as JS getTimezoneOffset(): minutes WEST of UTC.
            body.put("tzOffset", -TimeZone.getDefault().getOffset(System.currentTimeMillis()) / 60000);
            long joinedAt = prefs.getLong("joinedAt", 0);
            if (joinedAt > 0) body.put("joinedAt", joinedAt);
            String lang = prefs.getString("lang", null);
            if (lang != null) body.put("lang", lang);

            JSONObject res = post(server + "api/native_pending.php", body);
            if (!res.optBoolean("success", false)) return Result.success(); // e.g. study locked: try again next period
            JSONObject data = res.getJSONObject("dataset");

            if (data.optBoolean("withdrawn", false)) {
                // The participant left the study (signed out on another device, or withdrawn by the research
                // team): stop polling for good, forget who we were polling for and take down anything still
                // showing. The reminders the web layer scheduled itself are cleared the next time the app is
                // opened (it asks the server the same question and resets the device).
                WorkManager.getInstance(ctx).cancelUniqueWork(WORK_NAME);
                prefs.edit().clear().apply();
                NotificationManagerCompat.from(ctx).cancelAll();
                ReminderStore.ids(ctx).forEach(id -> ReminderStore.remove(ctx, id));
                return Result.success();
            }

            JSONArray items = data.optJSONArray("items");
            for (int i = 0; items != null && i < items.length(); i++) {
                JSONObject item = items.getJSONObject(i);
                if (show(ctx, item)) reportEvent(server, studyId, userId, "received", item.optString("key"));
            }

            if (!data.optBoolean("active", true)) {
                // The study has nothing for the server to nudge about: stop polling. The app re-arms this the
                // next time it is opened (ServerNudgesPlugin.configure), so a later study change is picked up.
                WorkManager.getInstance(ctx).cancelUniqueWork(WORK_NAME);
            }
            return Result.success();
        } catch (Exception e) {
            return Result.retry();
        }
    }

    private static JSONObject post(String url, JSONObject body) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        try {
            c.setConnectTimeout(15000);
            c.setReadTimeout(20000);
            c.setRequestMethod("POST");
            c.setRequestProperty("Content-Type", "application/json");
            c.setDoOutput(true);
            try (OutputStream os = c.getOutputStream()) {
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            if (c.getResponseCode() != 200) throw new Exception("HTTP " + c.getResponseCode());
            try (InputStream is = c.getInputStream(); Scanner s = new Scanner(is, "UTF-8").useDelimiter("\\A")) {
                return new JSONObject(s.hasNext() ? s.next() : "{}");
            }
        } finally {
            c.disconnect();
        }
    }

    /** Funnel receipt for the researcher's notification panel (api/push_event.php). Best effort, blocking. */
    static void reportEvent(String server, long studyId, String userId, String event, String key) {
        try {
            JSONObject body = new JSONObject();
            body.put("studyId", studyId);
            body.put("userId", userId);
            body.put("event", event);
            body.put("channel", "android");
            if (key != null && !key.isEmpty()) body.put("key", key);
            post(server + "api/push_event.php", body);
        } catch (Exception ignored) {
            // telemetry must never fail the poll
        }
    }

    /** Through ReminderNotifier: the same look and the same one-at-a-time stacking as everything else. */
    private boolean show(Context ctx, JSONObject item) {
        return ReminderNotifier.show(ctx, item.optString("title", "iEMAbot"), item.optString("body", ""), item.optString("key"),
                item.optLong("deadline", 0));
    }
}
