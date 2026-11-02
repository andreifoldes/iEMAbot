package org.iemabot.app.sensing;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.graphics.Color;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.os.PowerManager;
import android.os.SystemClock;

import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

import org.iemabot.app.MainActivity;
import org.iemabot.app.R;

import java.io.BufferedWriter;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStreamWriter;
import java.nio.charset.StandardCharsets;
import java.util.Locale;

/**
 * Records the raw accelerometer into the `accelerometer` stream for a limited time: from
 * accelBeforeMin before each scheduled prompt until accelAfterMin after it (started by an exact alarm,
 * see {@link #schedule}), and while a questionnaire is open (started by PhoneSensingPlugin.promptEvent).
 * Android delivers sensor data to background apps only through a foreground service, so this runs as
 * one (type "health", with a low-key notification) and holds a partial wake lock, without which the
 * sensor's buffer overflows while the screen is off. It stops by itself when the window ends.
 */
public class AccelerometerService extends Service implements SensorEventListener {
    static final String EXTRA_UNTIL = "until";
    static final String EXTRA_INTERACTIVE = "interactive";
    private static final String CHANNEL_ID = "sensing";
    private static final int NOTIFICATION_ID = 7302;
    private static final int ALARM_REQUEST_CODE = 7303;
    private static final long CHECK_MS = 10_000;
    /** An alarm may fire a little late; a window that started this recently is still joined. */
    private static final long ALARM_SLACK_MS = 120_000;

    /** Open-file key of the recording in progress (see SensingStore), null when not recording. */
    private static volatile String writingKey = null;

    // Set on the main thread, read by check() on the sensor thread.
    private volatile long scheduledUntil = 0;
    private volatile long interactiveUntil = 0;
    private long minGapNs = 0;
    private long lastSampleNs = 0;
    private HandlerThread thread;
    private Handler handler;
    private BufferedWriter writer;
    private PowerManager.WakeLock wakeLock;
    private SensorManager sensorManager;
    private String key;

    static boolean isWriting(String key) {
        return key.equals(writingKey);
    }

    static boolean available(Context ctx) {
        SensorManager sm = (SensorManager) ctx.getSystemService(Context.SENSOR_SERVICE);
        return sm != null && sm.getDefaultSensor(Sensor.TYPE_ACCELEROMETER) != null;
    }

    /**
     * Record until `until` (epoch ms). Interactive windows (questionnaire open) replace the previous
     * interactive end, scheduled ones only extend. Starting a foreground service is allowed while the app
     * is visible and from an exact alarm; anywhere else Android refuses, which is ignored.
     */
    static void request(Context ctx, long until, boolean interactive) {
        if (until <= System.currentTimeMillis()) return;
        Intent intent = new Intent(ctx, AccelerometerService.class)
                .putExtra(EXTRA_UNTIL, until)
                .putExtra(EXTRA_INTERACTIVE, interactive);
        try {
            ContextCompat.startForegroundService(ctx, intent);
        } catch (Exception ignored) {
            // ForegroundServiceStartNotAllowedException / IllegalStateException: not allowed right now
        }
    }

    static void stop(Context ctx) {
        ctx.stopService(new Intent(ctx, AccelerometerService.class));
    }

    // ── Scheduling around prompts ─────────────────────────────────

    private static PendingIntent alarmIntent(Context ctx) {
        Intent intent = new Intent(ctx, AlarmReceiver.class);
        return PendingIntent.getBroadcast(ctx, ALARM_REQUEST_CODE, intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /**
     * Arm the alarm for the next recording window. With `startNow`, a window that is already open is also
     * recorded right away (only possible from the foreground or an alarm).
     */
    static void schedule(Context ctx, SensingConfig cfg, boolean startNow) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        PendingIntent pi = alarmIntent(ctx);
        if (cfg == null || !cfg.accelerometer) {
            am.cancel(pi);
            return;
        }
        long now = System.currentTimeMillis();
        long currentEnd = 0;
        long nextStart = 0;
        for (long prompt : cfg.promptTimes) {
            long start = prompt - cfg.accelBeforeMs();
            long end = prompt + cfg.accelAfterMs();
            if (end <= now) continue;
            // Starting now anyway: also take a window that opens within the next minute.
            if (start <= now + (startNow ? 60_000 : 0)) currentEnd = Math.max(currentEnd, end);
            else if (nextStart == 0 || start < nextStart) nextStart = start;
        }
        if (startNow && currentEnd > 0) request(ctx, currentEnd, false);
        if (nextStart == 0) {
            am.cancel(pi);
            return;
        }
        if (Build.VERSION.SDK_INT >= 31 && !am.canScheduleExactAlarms())
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, nextStart, pi); // late, and may not be allowed to start the service
        else
            am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, nextStart, pi);
    }

    /** Fires at the start of a recording window. */
    public static class AlarmReceiver extends BroadcastReceiver {
        @Override
        public void onReceive(Context ctx, Intent intent) {
            SensingConfig cfg = SensingConfig.load(ctx);
            if (cfg == null || !cfg.accelerometer) return;
            long now = System.currentTimeMillis();
            long end = 0;
            for (long prompt : cfg.promptTimes) {
                long start = prompt - cfg.accelBeforeMs();
                long stop = prompt + cfg.accelAfterMs();
                if (start <= now + ALARM_SLACK_MS && stop > now) end = Math.max(end, stop);
            }
            if (end > 0) request(ctx, end, false);
            schedule(ctx, cfg, false);
        }
    }

    // ── Service ───────────────────────────────────────────────────

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!startInForeground()) {
            stopSelf();
            return START_NOT_STICKY;
        }
        SensingConfig cfg = SensingConfig.load(this);
        if (cfg == null || !cfg.accelerometer || intent == null) {
            stopSelf();
            return START_NOT_STICKY;
        }
        long until = intent.getLongExtra(EXTRA_UNTIL, 0);
        if (intent.getBooleanExtra(EXTRA_INTERACTIVE, false)) interactiveUntil = until;
        else scheduledUntil = Math.max(scheduledUntil, until);
        if (key == null && !startRecording(cfg)) {
            stopSelf();
            return START_NOT_STICKY;
        }
        holdWakeLock();
        return START_NOT_STICKY;
    }

    private long endTime() {
        return Math.max(scheduledUntil, interactiveUntil);
    }

    private boolean startInForeground() {
        ensureChannel(this);
        Intent launch = new Intent(this, MainActivity.class).setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 0, launch, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification n = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_esmira)
                .setColor(Color.parseColor("#065f52"))
                .setContentTitle(getString(R.string.sensing_notification_title))
                .setContentText(getString(R.string.sensing_notification_text))
                .setPriority(NotificationCompat.PRIORITY_LOW)
                .setOngoing(true)
                .setContentIntent(pi)
                .build();
        try {
            if (Build.VERSION.SDK_INT >= 34)
                startForeground(NOTIFICATION_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_HEALTH);
            else
                startForeground(NOTIFICATION_ID, n);
            return true;
        } catch (Exception e) {
            return false; // not allowed to start from the background right now
        }
    }

    private boolean startRecording(SensingConfig cfg) {
        sensorManager = (SensorManager) getSystemService(Context.SENSOR_SERVICE);
        Sensor sensor = sensorManager == null ? null : sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);
        if (sensor == null) return false;
        int hz = Math.max(1, Math.min(100, cfg.accelHz));
        int periodUs = 1_000_000 / hz;
        // Sensors often deliver faster than asked: drop samples that come much sooner than the period.
        minGapNs = periodUs * 800L;
        key = SensingStore.ACCELEROMETER + "." + System.currentTimeMillis();
        try {
            openWriter();
        } catch (IOException e) {
            key = null;
            return false;
        }
        writingKey = key;
        thread = new HandlerThread("accelerometer");
        thread.start();
        handler = new Handler(thread.getLooper());
        // Batching (5 s) lets the sensor hub buffer samples instead of waking the CPU for each one.
        sensorManager.registerListener(this, sensor, periodUs, 5_000_000, handler);
        handler.postDelayed(this::check, CHECK_MS);
        return true;
    }

    private void openWriter() throws IOException {
        writer = new BufferedWriter(new OutputStreamWriter(
                new FileOutputStream(SensingStore.openFile(this, key), true), StandardCharsets.UTF_8));
    }

    /** Runs on the sensor thread every CHECK_MS: flush, rotate the batch, stop at the end of the window. */
    private void check() {
        if (System.currentTimeMillis() >= endTime()) {
            stopSelf();
            return;
        }
        try {
            writer.flush();
            if (SensingStore.openFile(this, key).length() > SensingStore.MAX_OPEN_BYTES) {
                writer.close();
                SensingStore.seal(this, key);
                openWriter();
            }
        } catch (IOException ignored) {
            // keep recording; a lost flush only loses samples
        }
        handler.postDelayed(this::check, CHECK_MS);
    }

    private void holdWakeLock() {
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        if (pm == null) return;
        if (wakeLock == null) {
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "iemabot:accelerometer");
            wakeLock.setReferenceCounted(false);
        }
        // Bounded, so a recording can never keep the phone awake past its window.
        wakeLock.acquire(Math.max(60_000, endTime() - System.currentTimeMillis() + 60_000));
    }

    @Override
    public void onSensorChanged(SensorEvent event) {
        if (writer == null || event.timestamp - lastSampleNs < minGapNs) return;
        lastSampleNs = event.timestamp;
        // event.timestamp counts from boot like elapsedRealtimeNanos(); convert to wall-clock time.
        long time = System.currentTimeMillis() - (SystemClock.elapsedRealtimeNanos() - event.timestamp) / 1_000_000L;
        try {
            writer.write(String.format(Locale.ROOT, "[%d,%.4f,%.4f,%.4f]\n", time, event.values[0], event.values[1], event.values[2]));
        } catch (IOException ignored) {
            // disk full: drop the sample
        }
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {}

    @Override
    public void onDestroy() {
        if (sensorManager != null) sensorManager.unregisterListener(this);
        if (handler != null) {
            // The writer belongs to the sensor thread: close it there, after any sample still in flight.
            handler.removeCallbacksAndMessages(null);
            handler.post(() -> {
                finishBatch();
                thread.quitSafely();
            });
        } else {
            finishBatch();
        }
        if (wakeLock != null && wakeLock.isHeld()) wakeLock.release();
        super.onDestroy();
    }

    private void finishBatch() {
        try {
            if (writer != null) writer.close();
        } catch (IOException ignored) {
            // nothing to recover
        }
        writer = null;
        if (key == null) return;
        if (key.equals(writingKey)) writingKey = null;
        SensingStore.seal(this, key);
    }

    private static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm.getNotificationChannel(CHANNEL_ID) != null) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL_ID,
                ctx.getString(R.string.sensing_channel_name), NotificationManager.IMPORTANCE_LOW);
        ch.setDescription(ctx.getString(R.string.sensing_channel_description));
        nm.createNotificationChannel(ch);
    }
}
