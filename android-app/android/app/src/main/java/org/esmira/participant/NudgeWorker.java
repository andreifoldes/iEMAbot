package org.esmira.participant;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.os.Build;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;
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
 * Background poll for server-driven nudges (wearable-triggered prompts and follow-up reminders).
 * Scheduled signal times are NOT handled here: the app plans those on the device. No Google services,
 * no push service: a plain HTTPS POST to api/native_pending.php every ~15 minutes (WorkManager's minimum).
 * Doze and battery savers can defer a run, which is acceptable for these nudges.
 */
public class NudgeWorker extends Worker {
    static final String PREFS = "esmira_nudges";
    static final String WORK_NAME = "esmira-server-nudges";
    static final String CHANNEL_ID = "reminders"; // same channel the on-device reminders use
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
            long since = prefs.getLong("since", 0);
            if (since > 0) body.put("since", since);

            JSONObject res = post(server + "api/native_pending.php", body);
            if (!res.optBoolean("success", false)) return Result.success(); // e.g. study locked: try again next period
            JSONObject data = res.getJSONObject("dataset");

            JSONArray items = data.optJSONArray("items");
            for (int i = 0; items != null && i < items.length(); i++) show(ctx, items.getJSONObject(i));
            prefs.edit().putLong("since", data.getLong("now")).apply(); // only after a successful round trip

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

    private JSONObject post(String url, JSONObject body) throws Exception {
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

    private void show(Context ctx, JSONObject item) {
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return; // the participant declined notifications; nothing to show
        }
        ensureChannel(ctx);
        Intent launch = new Intent(ctx, MainActivity.class).setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pi = PendingIntent.getActivity(ctx, 0, launch, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        // A stable id per occurrence: a retried poll replaces the notification instead of duplicating it.
        int id = item.optString("key").hashCode() & 0x7fffffff;
        NotificationCompat.Builder n = new NotificationCompat.Builder(ctx, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_esmira)
                .setColor(Color.parseColor("#065f52"))
                .setContentTitle(item.optString("title", "ESMira"))
                .setContentText(item.optString("body", ""))
                .setStyle(new NotificationCompat.BigTextStyle().bigText(item.optString("body", "")))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(pi);
        NotificationManagerCompat.from(ctx).notify(id, n.build());
    }

    private static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm.getNotificationChannel(CHANNEL_ID) != null) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "Questionnaire reminders", NotificationManager.IMPORTANCE_HIGH);
        ch.setDescription("Reminders when a questionnaire is ready");
        nm.createNotificationChannel(ch);
    }
}
