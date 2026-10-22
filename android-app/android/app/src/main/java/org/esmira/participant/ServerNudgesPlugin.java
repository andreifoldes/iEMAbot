package org.esmira.participant;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.OneTimeWorkRequest;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.util.concurrent.TimeUnit;

/**
 * JS bridge for the server-nudge poll (see NudgeWorker). The web layer calls configure() once the participant
 * is enrolled (and on every app start, which also re-arms a poll that stopped itself), and cancel() on sign-out.
 */
@CapacitorPlugin(name = "ServerNudges")
public class ServerNudgesPlugin extends Plugin {

    @PluginMethod
    public void configure(PluginCall call) {
        String server = call.getString("server");
        String userId = call.getString("userId");
        // JSON numbers arrive as Integer or Long depending on size, and getLong() only accepts Long: read as long.
        long studyId = call.getData().optLong("studyId", 0);
        if (server == null || userId == null || studyId <= 0 || !server.startsWith("http")) {
            call.reject("server, userId and studyId are required");
            return;
        }
        if (!server.endsWith("/")) server += "/";
        Context ctx = getContext();
        SharedPreferences prefs = ctx.getSharedPreferences(NudgeWorker.PREFS, Context.MODE_PRIVATE);
        boolean sameTarget = server.equals(prefs.getString("server", null))
                && userId.equals(prefs.getString("userId", null))
                && studyId == prefs.getLong("studyId", 0);
        SharedPreferences.Editor e = prefs.edit()
                .putString("server", server)
                .putString("userId", userId)
                .putLong("studyId", studyId);
        long joinedAt = call.getData().optLong("joinedAt", 0);
        if (joinedAt > 0) e.putLong("joinedAt", joinedAt);
        if (!sameTarget) e.remove("since"); // a different study/participant starts from "now", no backfill
        e.apply();

        PeriodicWorkRequest req = new PeriodicWorkRequest.Builder(NudgeWorker.class, 15, TimeUnit.MINUTES)
                .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build();
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(NudgeWorker.WORK_NAME, ExistingPeriodicWorkPolicy.UPDATE, req);
        call.resolve();
    }

    /** Run one poll right away (also used by the emulator test instead of waiting for the 15-minute period). */
    @PluginMethod
    public void pollNow(PluginCall call) {
        WorkManager.getInstance(getContext()).enqueue(new OneTimeWorkRequest.Builder(NudgeWorker.class).build());
        call.resolve();
    }

    @PluginMethod
    public void cancel(PluginCall call) {
        Context ctx = getContext();
        WorkManager.getInstance(ctx).cancelUniqueWork(NudgeWorker.WORK_NAME);
        ctx.getSharedPreferences(NudgeWorker.PREFS, Context.MODE_PRIVATE).edit().clear().apply();
        call.resolve(new JSObject());
    }
}
