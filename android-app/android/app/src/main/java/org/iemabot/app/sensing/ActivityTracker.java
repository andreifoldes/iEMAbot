package org.iemabot.app.sensing;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.SystemClock;

import androidx.core.content.ContextCompat;

import com.google.android.gms.common.ConnectionResult;
import com.google.android.gms.common.GoogleApiAvailability;
import com.google.android.gms.location.ActivityRecognition;
import com.google.android.gms.location.ActivityTransition;
import com.google.android.gms.location.ActivityTransitionEvent;
import com.google.android.gms.location.ActivityTransitionRequest;
import com.google.android.gms.location.ActivityTransitionResult;
import com.google.android.gms.location.DetectedActivity;

import org.json.JSONArray;

import java.util.ArrayList;
import java.util.List;

/**
 * Google Activity Recognition (Transition API): Play services tells us, also while the app is closed,
 * when the participant starts or stops being still, walking, running, cycling or in a vehicle. Every
 * transition becomes a row of the `activity` stream, and the latest one is kept so a prompt can note
 * the current activity. Cheap on the battery: the classification runs in Play services on low-power
 * sensor batches. The registration does not survive a reboot or an app update, so it is renewed then
 * (SensingBootReceiver) and on every app start and upload round.
 */
public final class ActivityTracker {
    static final String KEY_CURRENT = "activityCurrent";
    static final String KEY_SINCE = "activitySince";
    private static final int REQUEST_CODE = 7301;

    private static final int[] ACTIVITIES = {
            DetectedActivity.STILL, DetectedActivity.WALKING, DetectedActivity.RUNNING,
            DetectedActivity.ON_BICYCLE, DetectedActivity.IN_VEHICLE,
    };

    private ActivityTracker() {}

    static boolean playServicesAvailable(Context ctx) {
        try {
            return GoogleApiAvailability.getInstance().isGooglePlayServicesAvailable(ctx) == ConnectionResult.SUCCESS;
        } catch (Throwable t) {
            return false;
        }
    }

    /** The Physical activity permission (Android 10+; before that Play services' install-time permission). */
    static boolean hasPermission(Context ctx) {
        if (Build.VERSION.SDK_INT < 29) return true;
        return ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACTIVITY_RECOGNITION) == PackageManager.PERMISSION_GRANTED;
    }

    static String name(int type) {
        switch (type) {
            case DetectedActivity.STILL: return "still";
            case DetectedActivity.WALKING: return "walking";
            case DetectedActivity.RUNNING: return "running";
            case DetectedActivity.ON_BICYCLE: return "on_bicycle";
            case DetectedActivity.IN_VEHICLE: return "in_vehicle";
            case DetectedActivity.ON_FOOT: return "on_foot";
            case DetectedActivity.TILTING: return "tilting";
            default: return "unknown";
        }
    }

    private static PendingIntent pendingIntent(Context ctx) {
        Intent intent = new Intent(ctx, Receiver.class);
        // Play services adds the result to the intent, so it has to stay mutable.
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_MUTABLE : 0);
        return PendingIntent.getBroadcast(ctx, REQUEST_CODE, intent, flags);
    }

    /** Register (or renew) the transition updates when the study wants them, unregister otherwise. */
    @SuppressLint("MissingPermission") // checked by hasPermission()
    static void sync(Context ctx, SensingConfig cfg) {
        if (!playServicesAvailable(ctx)) return;
        try {
            if (cfg == null || !cfg.activity || !hasPermission(ctx)) {
                ActivityRecognition.getClient(ctx).removeActivityTransitionUpdates(pendingIntent(ctx));
                return;
            }
            List<ActivityTransition> transitions = new ArrayList<>();
            for (int type : ACTIVITIES) {
                transitions.add(new ActivityTransition.Builder().setActivityType(type)
                        .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER).build());
                transitions.add(new ActivityTransition.Builder().setActivityType(type)
                        .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_EXIT).build());
            }
            ActivityRecognition.getClient(ctx)
                    .requestActivityTransitionUpdates(new ActivityTransitionRequest(transitions), pendingIntent(ctx));
        } catch (Throwable ignored) {
            // e.g. the permission was revoked a moment ago: the next app start or upload round tries again
        }
    }

    /** Receives the transitions from Play services. */
    public static class Receiver extends BroadcastReceiver {
        @Override
        public void onReceive(Context ctx, Intent intent) {
            if (!ActivityTransitionResult.hasResult(intent)) return;
            ActivityTransitionResult result = ActivityTransitionResult.extractResult(intent);
            SensingConfig cfg = SensingConfig.load(ctx);
            if (result == null || cfg == null || !cfg.activity) return;
            long nowWall = System.currentTimeMillis();
            long nowElapsed = SystemClock.elapsedRealtimeNanos();
            for (ActivityTransitionEvent e : result.getTransitionEvents()) {
                long time = nowWall - (nowElapsed - e.getElapsedRealTimeNanos()) / 1_000_000L;
                String activity = name(e.getActivityType());
                boolean enter = e.getTransitionType() == ActivityTransition.ACTIVITY_TRANSITION_ENTER;
                SensingStore.append(ctx, "activity", new JSONArray().put(time).put(activity).put(enter ? "enter" : "exit"));
                String current = SensingConfig.prefs(ctx).getString(KEY_CURRENT, "");
                if (enter || activity.equals(current)) {
                    SensingConfig.prefs(ctx).edit()
                            .putString(KEY_CURRENT, enter ? activity : "")
                            .putLong(KEY_SINCE, time)
                            .apply();
                }
            }
        }
    }
}
