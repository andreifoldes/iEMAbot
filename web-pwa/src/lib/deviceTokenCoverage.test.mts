/**
 * Every request the app sends about a participant must say which device it comes from, or a study that allows an id on one
 * device only could not tell the devices apart (the server lets a request without a token through, for old apps). The
 * requests are built by hand in a dozen places and there is no shared helper, so this reads the source: a request to the
 * ESMira API that does not carry the token fails here, wherever it is added.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

/** The whole app source, not just lib/: a request added in a component or in App.tsx must carry the token too. */
const SRC = fileURLToPath(new URL('..', import.meta.url));

/** The service worker has no access to the page's storage, so its receipts (push_event.php) go without a token, which the server lets through. */
const NO_STORAGE = new Set(['sw.ts']);

/** API endpoints that are asked before this device holds an id, or that do not name a participant. */
const WITHOUT_TOKEN = new Set(['check_pid.php', 'app_version.php', 'channel_handoff.php']);

interface Call { file: string; endpoint: string; text: string; before: string }

/** Source files under src/ (relative paths), without tests. */
function sourceFiles(dir: string, prefix = ''): string[] {
  return readdirSync(join(dir, prefix), { withFileTypes: true }).flatMap((entry) => {
    const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isDirectory()) return sourceFiles(dir, rel);
    return /\.tsx?$/.test(entry.name) && !/\.test\./.test(entry.name) ? [rel] : [];
  });
}

/** Every `fetch(...)` to api/<endpoint>.php in the app's source, with its full argument text. */
function apiCalls(): Call[] {
  const calls: Call[] = [];
  for (const file of sourceFiles(SRC)) {
    if (NO_STORAGE.has(file)) continue;
    const source = readFileSync(join(SRC, file), 'utf8');
    for (const match of source.matchAll(/\bfetch\(/g)) {
      let depth = 1;
      let end = match.index + match[0].length;
      while (depth > 0 && end < source.length) {
        const ch = source[end++];
        if (ch === '(') depth++;
        else if (ch === ')') depth--;
      }
      const text = source.slice(match.index, end);
      const endpoint = /api\/([a-z_]+\.php)/.exec(text)?.[1];
      if (endpoint) calls.push({ file, endpoint, text, before: source.slice(Math.max(0, match.index - 900), match.index) });
    }
  }
  return calls;
}

const CARRIES_TOKEN = /deviceField\(\)|deviceQuery\(\)/;

describe('requests about a participant carry the device token', () => {
  const calls = apiCalls();

  it('finds the requests (so this test cannot pass by looking at nothing)', () => {
    assert.ok(calls.length >= 15, `found only ${calls.length} requests to the API`);
    const endpoints = new Set(calls.map((call) => call.endpoint));
    for (const expected of ['datasets.php', 'file_uploads.php', 'pid_lock.php', 'push_subscribe.php', 'schedule.php', 'save_message.php']) {
      assert.ok(endpoints.has(expected), `no request to ${expected} found`);
    }
  });

  for (const call of calls.filter((c) => !WITHOUT_TOKEN.has(c.endpoint))) {
    const label = `${call.file}: ${call.endpoint}`;
    it(label, () => {
      if (call.endpoint === 'file_uploads.php') {
        // Uploads are multipart: the token is a form field, added where the form is built just before.
        assert.match(call.before, /append\('deviceToken', getOrCreateDeviceToken\(\)\)/, `${label} does not send the device token`);
      } else {
        assert.match(call.text, CARRIES_TOKEN, `${label} does not send the device token`);
      }
    });
  }

  it('the requests left without a token are the ones that cannot have one', () => {
    for (const name of WITHOUT_TOKEN) assert.ok(calls.some((call) => call.endpoint === name), `${name} is no longer called: drop it from the list`);
  });
});

/**
 * The Android app's background workers build their own requests (no page, no shared helper). Each one that posts to the API
 * must send the token the page handed over, and the plugin that receives the settings must keep it. Each check looks at the one
 * piece of code that builds or makes the request, not at the file as a whole, so another line in the same file cannot satisfy it.
 */
describe('the Android native requests carry the device token', () => {
  const JAVA = fileURLToPath(new URL('../../../android-app/android/app/src/main/java/org/iemabot/app/', import.meta.url));
  const read = (rel: string): string => readFileSync(join(JAVA, rel), 'utf8');
  /** The text from `from` up to (not including) `to`, so a check cannot be satisfied by code elsewhere in the file. */
  const between = (text: string, from: string, to: string): string => {
    const start = text.indexOf(from);
    const end = text.indexOf(to, start + from.length);
    assert.ok(start >= 0 && end > start, `could not find "${from}" ... "${to}"`);
    return text.slice(start, end);
  };

  it('the poll sends it with the native_pending.php request body', () => {
    const body = between(read('NudgeWorker.java'), 'JSONObject body = new JSONObject();', 'post(server + "api/native_pending.php"');
    assert.match(body, /body\.put\("deviceToken", deviceToken\)/);
    assert.match(body, /deviceToken = prefs\.getString\("deviceToken"/);
  });

  it('the receipts (push_event.php) carry it, from the poll and from a notification tap', () => {
    const worker = read('NudgeWorker.java');
    assert.match(between(worker, 'static void reportEvent(', 'post(server + "api/push_event.php"'), /body\.put\("deviceToken", deviceToken\)/);
    assert.match(worker, /reportEvent\(server, studyId, userId, prefs\.getString\("deviceToken", null\), "received"/);
    const tap = read('MainActivity.java');
    assert.match(tap, /String deviceToken = prefs\.getString\("deviceToken", null\)/);
    assert.match(tap, /reportEvent\(server, studyId, userId, deviceToken, "clicked"/);
  });

  it('a refused poll clears nothing (the participant may take the id back) and does not cancel a poll that was re-armed meanwhile', () => {
    const branch = between(read('NudgeWorker.java'), 'optBoolean("otherDevice"', 'optBoolean("withdrawn"');
    assert.doesNotMatch(branch, /clear\(\)|cancelAll|ReminderStore/, 'the otherDevice branch deletes local state');
    assert.match(branch, /prefs\.getLong\("armedAt", 0\) == armedAt\) WorkManager\.getInstance\(ctx\)\.cancelUniqueWork/);
    assert.match(read('NudgeWorker.java'), /long armedAt = prefs\.getLong\("armedAt", 0\);/);
    assert.match(read('ServerNudgesPlugin.java'), /putLong\("armedAt", System\.currentTimeMillis\(\)\)/);
  });

  it('the sensing upload sends it with the phone_sensing.php body, and keeps the batch on "other_device"', () => {
    const worker = read('sensing/SensingUploadWorker.java');
    assert.match(between(worker, 'String body = "{', 'ByteArrayOutputStream gz'), /\\"deviceToken\\":" \+ JSONObject\.quote\(cfg\.deviceToken\)/);
    const branch = between(worker, '"other_device".equals(status)', '"withdrawn".equals(status)');
    assert.match(branch, /return Result\.success\(\)/, 'other_device must end the round before the batch is deleted');
    assert.doesNotMatch(branch, /delete\(\)|stopAll|clear/, 'other_device must keep the batches and the sensing');
    assert.ok(worker.indexOf('"other_device"') < worker.indexOf('batch.delete()'), 'other_device must be handled before the delete');
  });

  it('both plugins keep the token the page hands over, and the sensing configuration reads it', () => {
    assert.match(read('ServerNudgesPlugin.java'), /putString\("deviceToken", deviceToken\)/);
    assert.match(read('sensing/PhoneSensingPlugin.java'), /putString\("deviceToken", call\.getString\("deviceToken", ""\)\)/);
    assert.match(read('sensing/SensingConfig.java'), /deviceToken = p\.getString\("deviceToken"/);
  });

  it('every Java file that calls the API or sends a receipt is checked above', () => {
    const callers: string[] = [];
    const walk = (rel: string): void => {
      for (const entry of readdirSync(join(JAVA, rel), { withFileTypes: true })) {
        const next = rel ? `${rel}/${entry.name}` : entry.name;
        if (entry.isDirectory()) walk(next);
        else if (entry.name.endsWith('.java') && /api\/[a-z_]+\.php|reportEvent\(/.test(read(next))) callers.push(next);
      }
    };
    walk('');
    assert.deepEqual(callers.sort(), ['MainActivity.java', 'NudgeWorker.java', 'sensing/SensingUploadWorker.java']);
  });

  it('signing out stops the native background work before it lets go of the id', () => {
    const app = readFileSync(join(SRC, 'App.tsx'), 'utf8');
    const reset = between(app, 'const resetDevice = async', 'localStorage.clear()');
    const stop = reset.indexOf('await cancelServerNudges()');
    assert.ok(stop >= 0 && reset.indexOf('await cancelPhoneSensing()') >= 0, 'sign-out does not stop the native work');
    assert.ok(stop < reset.indexOf('releasePidLock('), 'a background request could take the id again after it was let go');
  });
});
