package org.iemabot.app.sensing;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.ExistingWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.OneTimeWorkRequest;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;

import java.util.concurrent.TimeUnit;

/** Brings the background parts of phone sensing in line with the stored configuration, or stops them all. */
public final class PhoneSensing {
    private PhoneSensing() {}

    private static Constraints online() {
        return new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build();
    }

    /**
     * Register / unregister activity recognition, arm the next accelerometer window and keep the upload
     * round scheduled. `foreground`: the app is visible, so a window that is open right now can start.
     */
    static void apply(Context ctx, boolean foreground) {
        SensingConfig cfg = SensingConfig.load(ctx);
        ActivityTracker.sync(ctx, cfg);
        AccelerometerService.schedule(ctx, cfg, foreground);
        WorkManager wm = WorkManager.getInstance(ctx);
        if (cfg == null) {
            wm.cancelUniqueWork(SensingUploadWorker.WORK_NAME);
            return;
        }
        PeriodicWorkRequest req = new PeriodicWorkRequest.Builder(SensingUploadWorker.class, 1, TimeUnit.HOURS)
                .setConstraints(online())
                .build();
        wm.enqueueUniquePeriodicWork(SensingUploadWorker.WORK_NAME, ExistingPeriodicWorkPolicy.KEEP, req);
    }

    /** One upload round now (when the app opens and after a questionnaire). */
    static void uploadSoon(Context ctx) {
        WorkManager.getInstance(ctx).enqueue(new OneTimeWorkRequest.Builder(SensingUploadWorker.class).setConstraints(online()).build());
    }

    /**
     * Uploads are held back because the phone is on mobile data: run one round as soon as an unmetered
     * network (Wi-Fi) is there, instead of waiting for the next hourly round.
     */
    static void uploadOnWifi(Context ctx) {
        Constraints unmetered = new Constraints.Builder().setRequiredNetworkType(NetworkType.UNMETERED).build();
        WorkManager.getInstance(ctx).enqueueUniqueWork(SensingUploadWorker.WIFI_WORK_NAME, ExistingWorkPolicy.KEEP,
                new OneTimeWorkRequest.Builder(SensingUploadWorker.class).setConstraints(unmetered).build());
    }

    /** The participant left the study: stop everything and forget the configuration and queued data. */
    static void stopAll(Context ctx) {
        SensingConfig.prefs(ctx).edit().clear().commit();
        ActivityTracker.sync(ctx, null);
        AccelerometerService.schedule(ctx, null, false);
        AccelerometerService.stop(ctx);
        WorkManager.getInstance(ctx).cancelUniqueWork(SensingUploadWorker.WORK_NAME);
        WorkManager.getInstance(ctx).cancelUniqueWork(SensingUploadWorker.WIFI_WORK_NAME);
        SensingStore.clear(ctx);
    }

    /** Activity recognition and alarms do not survive a reboot or an app update: set them up again. */
    public static class BootReceiver extends BroadcastReceiver {
        @Override
        public void onReceive(Context ctx, Intent intent) {
            String action = intent.getAction();
            if (Intent.ACTION_BOOT_COMPLETED.equals(action) || Intent.ACTION_MY_PACKAGE_REPLACED.equals(action))
                apply(ctx, false);
        }
    }
}
