package org.iemabot.app;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.os.Build;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

/**
 * Posts every iEMAbot notification of the Android app, from the reminders planned on the phone
 * (ReminderReceiver) and from the server nudges (NudgeWorker), with the stacking rules of the PWA's
 * service worker (web-pwa/src/sw.ts): one constant tag, so there is never more than one iEMAbot
 * notification on screen and each new one replaces the last and alerts again (web push `renotify`).
 * Also like the service worker, the same occurrence (key `type:qid:time`) is never shown twice, and a check-in
 * whose window has closed is not shown: a notification goes away by itself when its window closes (`deadline`,
 * through Android's setTimeoutAfter), so last night's prompt is not still waiting the next morning.
 * Look and content follow the shared notification spec (NotificationSpec.php / notificationSpec.ts).
 */
final class ReminderNotifier {
    static final String CHANNEL_ID = "reminders";
    /** The PWA's notification tag (sw.ts DEFAULT_TAG, PushSender::REMINDER_TAG). */
    static final String TAG = "esmira-reminder";
    /** With the constant tag: the one slot every notification goes into. */
    static final int ID = 1;
    /** NotificationSpec::ACCENT_COLOR / notificationSpec.ts NOTIFICATION_ACCENT. */
    static final String ACCENT_COLOR = "#075E54";

    private ReminderNotifier() {}

    /**
     * Show one notification in place of whatever iEMAbot notification is showing, until `deadline` (epoch ms, the
     * check-in's window closes; 0 = no end). False when not allowed, or when the window has already closed.
     */
    static boolean show(Context ctx, String title, String body, String key, long deadline) {
        long now = System.currentTimeMillis();
        if (deadline > 0 && deadline <= now) return false; // too late to do it (e.g. an alarm delayed by Doze)
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return false; // the participant declined notifications; nothing to show
        }
        if (!ReminderStore.markShown(ctx, key)) return false; // already shown (a retried poll, a re-armed alarm)
        ensureChannel(ctx);
        Intent launch = new Intent(ctx, MainActivity.class)
                .setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP)
                .putExtra(MainActivity.EXTRA_NOTIFICATION_KEY, key == null ? "" : key);
        PendingIntent tap = PendingIntent.getActivity(ctx, ID, launch, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        NotificationCompat.Builder n = new NotificationCompat.Builder(ctx, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_esmira)
                .setLargeIcon(BitmapFactory.decodeResource(ctx.getResources(), R.drawable.ic_notification_large))
                .setColor(Color.parseColor(ACCENT_COLOR))
                .setContentTitle(title == null || title.isEmpty() ? "iEMAbot" : title)
                .setContentText(body == null ? "" : body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body == null ? "" : body))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setOnlyAlertOnce(false) // a replacement alerts again, like web push renotify
                .setAutoCancel(true)
                .setContentIntent(tap);
        // Android takes it down when the window closes, also while the app is not running (API 26+).
        if (deadline > 0) n.setTimeoutAfter(deadline - now);
        NotificationManagerCompat.from(ctx).notify(TAG, ID, n.build());
        return true;
    }

    /** Take down the notification that is showing (sign-out, notifications switched off). */
    static void clear(Context ctx) {
        NotificationManagerCompat.from(ctx).cancel(TAG, ID);
    }

    static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm == null || nm.getNotificationChannel(CHANNEL_ID) != null) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "Questionnaire reminders", NotificationManager.IMPORTANCE_HIGH);
        ch.setDescription("Reminders when a questionnaire is ready");
        ch.enableVibration(true);
        nm.createNotificationChannel(ch);
    }
}
