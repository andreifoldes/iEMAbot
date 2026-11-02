package org.iemabot.app.sensing;

import android.content.Context;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.SystemClock;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/**
 * One ambient-light reading: listens to the light sensor for a short moment and returns the median lux.
 * The light sensor reports on change, so the first value arrives right after registering and more only
 * when the light changes. Blocking: call it off the main thread.
 */
final class LightSampler {
    private static final long MIN_MS = 600;

    private LightSampler() {}

    static boolean available(Context ctx) {
        SensorManager sm = (SensorManager) ctx.getSystemService(Context.SENSOR_SERVICE);
        return sm != null && sm.getDefaultSensor(Sensor.TYPE_LIGHT) != null;
    }

    /** Median lux over about `maxMs`, or null when the phone has no light sensor or it did not answer. */
    static Float sample(Context ctx, long maxMs) {
        SensorManager sm = (SensorManager) ctx.getSystemService(Context.SENSOR_SERVICE);
        Sensor sensor = sm == null ? null : sm.getDefaultSensor(Sensor.TYPE_LIGHT);
        if (sensor == null) return null;

        final List<Float> values = new ArrayList<>();
        HandlerThread thread = new HandlerThread("light-sample");
        thread.start();
        SensorEventListener listener = new SensorEventListener() {
            @Override
            public void onSensorChanged(SensorEvent event) {
                synchronized (values) {
                    values.add(event.values[0]);
                }
            }

            @Override
            public void onAccuracyChanged(Sensor s, int accuracy) {}
        };
        try {
            sm.registerListener(listener, sensor, SensorManager.SENSOR_DELAY_NORMAL, new Handler(thread.getLooper()));
            long start = SystemClock.elapsedRealtime();
            while (true) {
                long elapsed = SystemClock.elapsedRealtime() - start;
                synchronized (values) {
                    if (elapsed >= maxMs || (elapsed >= MIN_MS && !values.isEmpty())) break;
                }
                SystemClock.sleep(50);
            }
        } finally {
            sm.unregisterListener(listener);
            thread.quitSafely();
        }
        synchronized (values) {
            if (values.isEmpty()) return null;
            Collections.sort(values);
            return values.get(values.size() / 2);
        }
    }
}
