package org.iemabot.app;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

import org.iemabot.app.keystrokes.KeystrokeTimingPlugin;
import org.iemabot.app.sensing.PhoneSensingPlugin;

public class MainActivity extends BridgeActivity {
    /** Set on the launch intent of every notification (ReminderNotifier), so a tap is reported like a web-push click. */
    static final String EXTRA_NOTIFICATION_KEY = "org.iemabot.app.NOTIFICATION_KEY";

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(ServerNudgesPlugin.class); // must be registered before super.onCreate
        registerPlugin(RemindersPlugin.class);
        registerPlugin(PhoneSensingPlugin.class);
        registerPlugin(KeystrokeTimingPlugin.class);
        super.onCreate(savedInstanceState);
        reportNotificationTap(getIntent());
    }

    @Override
    public void onNewIntent(Intent intent) { // public: compiles whether the base declares it public or protected
        super.onNewIntent(intent);
        reportNotificationTap(intent);
    }

    private void reportNotificationTap(Intent intent) {
        String key = intent == null ? null : intent.getStringExtra(EXTRA_NOTIFICATION_KEY);
        if (key == null) return;
        intent.removeExtra(EXTRA_NOTIFICATION_KEY); // once per tap, not on every recreation
        SharedPreferences prefs = getSharedPreferences(NudgeWorker.PREFS, Context.MODE_PRIVATE);
        String server = prefs.getString("server", null);
        String userId = prefs.getString("userId", null);
        long studyId = prefs.getLong("studyId", 0);
        if (server == null || userId == null || studyId <= 0) return;
        new Thread(() -> NudgeWorker.reportEvent(server, studyId, userId, "clicked", key)).start();
    }
}
