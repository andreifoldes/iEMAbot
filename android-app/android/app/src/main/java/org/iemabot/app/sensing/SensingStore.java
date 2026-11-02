package org.iemabot.app.sensing;

import android.content.Context;

import org.json.JSONArray;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStreamWriter;
import java.io.Writer;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.UUID;

/**
 * Local queue of sensing rows waiting for upload. Each stream appends JSON-array lines to
 * files/sensing/&lt;key&gt;.open, where the key is the stream name (an accelerometer recording adds its own
 * suffix, "accelerometer.&lt;start&gt;", so a new recording never writes into the previous one's file).
 * Sealing renames the file to &lt;stream&gt;.&lt;time&gt;-&lt;random&gt;.jsonl, which is one upload batch (its name is
 * the batch id the server deduplicates on). Files are sealed when they
 * grow past MAX_OPEN_BYTES and before each upload round, so a batch stays well below the server's
 * request limits.
 */
final class SensingStore {
    static final String DIR = "sensing";
    static final String ACCELEROMETER = "accelerometer";
    static final long MAX_OPEN_BYTES = 400_000;
    private static final String OPEN = ".open";
    private static final String READY = ".jsonl";

    private SensingStore() {}

    static File dir(Context ctx) {
        File d = new File(ctx.getFilesDir(), DIR);
        if (!d.isDirectory()) //noinspection ResultOfMethodCallIgnored
            d.mkdirs();
        return d;
    }

    static File openFile(Context ctx, String key) {
        return new File(dir(ctx), key + OPEN);
    }

    /** Append one row to a stream. */
    static synchronized void append(Context ctx, String stream, JSONArray row) {
        File f = openFile(ctx, stream);
        try (Writer w = new OutputStreamWriter(new FileOutputStream(f, true), StandardCharsets.UTF_8)) {
            w.write(row.toString());
            w.write('\n');
        } catch (IOException ignored) {
            return; // a full disk loses this row, nothing else to do
        }
        if (f.length() > MAX_OPEN_BYTES) seal(ctx, stream);
    }

    /** Turn an open file into an upload batch. */
    static synchronized void seal(Context ctx, String key) {
        File f = openFile(ctx, key);
        if (!f.exists()) return;
        if (f.length() == 0) {
            //noinspection ResultOfMethodCallIgnored
            f.delete();
            return;
        }
        int dot = key.indexOf('.');
        String stream = dot < 0 ? key : key.substring(0, dot);
        String id = System.currentTimeMillis() + "-" + UUID.randomUUID().toString().substring(0, 8);
        //noinspection ResultOfMethodCallIgnored
        f.renameTo(new File(dir(ctx), stream + "." + id + READY));
    }

    /** Seal every open file, except the one an accelerometer recording is writing right now. */
    static synchronized void sealAll(Context ctx) {
        File[] files = dir(ctx).listFiles();
        if (files == null) return;
        for (File f : files) {
            String name = f.getName();
            if (!name.endsWith(OPEN)) continue;
            String key = name.substring(0, name.length() - OPEN.length());
            if (AccelerometerService.isWriting(key)) continue;
            seal(ctx, key);
        }
    }

    /** Batches ready for upload, oldest first. */
    static synchronized List<File> ready(Context ctx) {
        File[] files = dir(ctx).listFiles((d, name) -> name.endsWith(READY));
        if (files == null) return new ArrayList<>();
        Arrays.sort(files, (a, b) -> Long.compare(a.lastModified(), b.lastModified()));
        return new ArrayList<>(Arrays.asList(files));
    }

    /** The stream a batch belongs to and its batch id ("accelerometer" and "1729…-ab12cd34"). */
    static String[] parse(File batch) {
        String name = batch.getName();
        String base = name.substring(0, name.length() - READY.length());
        int dot = base.indexOf('.');
        return new String[] { base.substring(0, dot), base.substring(dot + 1) };
    }

    static synchronized int pendingCount(Context ctx) {
        File[] files = dir(ctx).listFiles();
        return files == null ? 0 : files.length;
    }

    /** Delete everything still queued (the participant left the study). */
    static synchronized void clear(Context ctx) {
        File[] files = dir(ctx).listFiles();
        if (files == null) return;
        for (File f : files) //noinspection ResultOfMethodCallIgnored
            f.delete();
    }
}
