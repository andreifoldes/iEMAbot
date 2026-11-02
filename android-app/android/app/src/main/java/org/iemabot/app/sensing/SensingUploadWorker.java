package org.iemabot.app.sensing;

import android.content.Context;
import android.net.ConnectivityManager;

import androidx.annotation.NonNull;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Scanner;
import java.util.zip.GZIPOutputStream;

/**
 * Background round, about every hour while online (WorkManager): collects app usage, renews the
 * activity-recognition registration and the next accelerometer alarm (both can get lost), then uploads
 * every queued batch to api/phone_sensing.php, one request per batch, gzip-compressed. A batch is deleted
 * once the server has it (or says it never will); anything else leaves it for the next round.
 * On a metered network (mobile data, or a Wi-Fi the phone treats as metered such as a hotspot) the
 * upload follows the study's rule (SensingConfig.uploadNetwork): held back, it waits for Wi-Fi.
 */
public class SensingUploadWorker extends Worker {
    static final String WORK_NAME = "esmira-phone-sensing";
    /** One-off round that waits for an unmetered network (PhoneSensing.uploadOnWifi). */
    static final String WIFI_WORK_NAME = "esmira-phone-sensing-wifi";
    /** Last time the server accepted a batch (epoch ms), shown in the app's Phone sensing panel. */
    static final String KEY_LAST_UPLOAD = "lastUpload";
    /** ESMira server protocol version this client speaks (Main::SERVER_VERSION). */
    private static final int PROTOCOL_VERSION = 12;

    public SensingUploadWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    @NonNull
    @Override
    public Result doWork() {
        Context ctx = getApplicationContext();
        SensingConfig cfg = SensingConfig.load(ctx);
        if (cfg == null) return Result.success();

        try {
            UsageCollector.collect(ctx, cfg);
        } catch (Exception ignored) {
            // usage stats are best effort; the cursors only move after a successful read
        }
        ActivityTracker.sync(ctx, cfg);
        AccelerometerService.schedule(ctx, cfg, false);

        SensingStore.sealAll(ctx);
        List<File> ready = SensingStore.ready(ctx);
        if (ready.isEmpty()) return Result.success();
        // Oldest first: once the oldest batch has waited long enough, everything goes (it catches up).
        long waited = System.currentTimeMillis() - ready.get(0).lastModified();
        if (isMetered(ctx) && !cfg.mayUseMetered(waited)) {
            PhoneSensing.uploadOnWifi(ctx);
            return Result.success();
        }
        for (File batch : ready) {
            String status;
            try {
                status = upload(cfg, batch);
            } catch (Exception e) {
                return Result.success(); // offline or server trouble: the next round tries again
            }
            if (status == null) return Result.success();
            if ("withdrawn".equals(status)) {
                PhoneSensing.stopAll(ctx);
                return Result.success();
            }
            if ("ok".equals(status) || "duplicate".equals(status))
                SensingConfig.prefs(ctx).edit().putLong(KEY_LAST_UPLOAD, System.currentTimeMillis()).apply();
            //noinspection ResultOfMethodCallIgnored
            batch.delete(); // ok, duplicate, disabled, rejected
        }
        return Result.success();
    }

    /** Mobile data, or a Wi-Fi Android counts as metered. Unknown counts as metered (the safe side). */
    private static boolean isMetered(Context ctx) {
        ConnectivityManager cm = (ConnectivityManager) ctx.getSystemService(Context.CONNECTIVITY_SERVICE);
        return cm == null || cm.isActiveNetworkMetered();
    }

    /** Upload one batch; returns the server's status, or null when it did not accept it (try later). */
    private String upload(SensingConfig cfg, File batch) throws Exception {
        String[] parts = SensingStore.parse(batch);
        StringBuilder rows = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(new FileInputStream(batch), StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) {
                try {
                    new JSONArray(line); // skips a line cut short when the app was killed mid-write
                } catch (Exception e) {
                    continue;
                }
                if (rows.length() > 0) rows.append(',');
                rows.append(line);
            }
        }
        if (rows.length() == 0) return "ok"; // nothing usable in it

        String body = "{\"studyId\":" + cfg.studyId
                + ",\"userId\":" + JSONObject.quote(cfg.userId)
                + ",\"accessKey\":" + JSONObject.quote(cfg.accessKey == null ? "" : cfg.accessKey)
                + ",\"serverVersion\":" + PROTOCOL_VERSION
                + ",\"stream\":" + JSONObject.quote(parts[0])
                + ",\"batchId\":" + JSONObject.quote(parts[1])
                + ",\"rows\":[" + rows + "]}";
        ByteArrayOutputStream gz = new ByteArrayOutputStream();
        try (GZIPOutputStream out = new GZIPOutputStream(gz)) {
            out.write(body.getBytes(StandardCharsets.UTF_8));
        }

        HttpURLConnection c = (HttpURLConnection) new URL(cfg.server + "api/phone_sensing.php").openConnection();
        try {
            c.setConnectTimeout(15000);
            c.setReadTimeout(30000);
            c.setRequestMethod("POST");
            c.setRequestProperty("Content-Type", "application/json");
            c.setRequestProperty("Content-Encoding", "gzip");
            c.setDoOutput(true);
            c.setFixedLengthStreamingMode(gz.size());
            try (OutputStream os = c.getOutputStream()) {
                gz.writeTo(os);
            }
            if (c.getResponseCode() != 200) return null;
            JSONObject res;
            try (InputStream is = c.getInputStream(); Scanner s = new Scanner(is, "UTF-8").useDelimiter("\\A")) {
                res = new JSONObject(s.hasNext() ? s.next() : "{}");
            }
            if (!res.optBoolean("success", false)) return null;
            JSONObject data = res.optJSONObject("dataset");
            return data == null ? null : data.optString("status", null);
        } finally {
            c.disconnect();
        }
    }
}
