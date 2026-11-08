---
title: "Phone sensing"
sidebar_label: "Phone sensing"
description: "Ambient light, activity recognition, accelerometer windows and app usage collected by the Android app, how they reach the server and what the researcher downloads."
---

# Phone sensing

<span className="status status--partial">Partial</span> implemented in the Android app, server and admin · not yet verified on real devices · fork-only

Studies can collect context from the participant's phone around their questionnaires. Only the iEMAbot
**Android app** does this. The web app and iOS have no access to these sensors, so participants using them
are not affected. Researchers turn each sensor on in the study's **Phone sensing** panel.

| Setting (study JSON) | What the phone collects | Android permission | File |
| --- | --- | --- | --- |
| `phoneSensingLight` | Ambient light (lux, median over about a second) when a questionnaire is opened and when it is submitted | none | `prompts.csv` |
| `phoneSensingActivity` | Google Activity Recognition transitions: entering or leaving *still*, *walking*, *running*, *on bicycle*, *in vehicle*, recorded all the time. The activity at each prompt also goes into `prompts.csv` | *Physical activity* (runtime dialog, Android 10+), Google Play services | `activity.csv` |
| `phoneSensingAccelerometer` | Raw accelerometer (x, y, z in m/s², including gravity) from `phoneSensingAccelBeforeMin` before each scheduled prompt until `phoneSensingAccelAfterMin` after it, and while a questionnaire is open. `phoneSensingAccelHz` samples per second (default 20) | none (foreground service with a notification) | `accelerometer.csv` |
| `phoneSensingUsage` = `daily` | Foreground time and launches per app (package name) and local day; screen-on time, screen-ons and unlocks per day | *Usage access* (the participant switches it on in Android's settings) | `usage_daily.csv`, `screen_daily.csv` |
| `phoneSensingUsage` = `events` | Every app coming to the foreground or leaving it, screen on/off, lock/unlock, shutdown/startup | *Usage access* | `usage_events.csv` |
| `phoneSensingUsage` = `both` | Both of the above | *Usage access* | all three |

Activity recognition is the battery-friendly way to know what happened **before, during and after** each prompt:
it runs continuously, so the timeline around every prompt is in `activity.csv`. The raw accelerometer only records
in windows around prompts, uses more battery and data, and needs Android to show a notification while it records.
A prompt the phone cannot know in advance (a sensor-triggered or server-sent nudge) has no "before" window: the
recording starts when the questionnaire is opened.

## On the phone

The web layer (`web-pwa/src/lib/phoneSensing.ts`) passes the study's settings and the upcoming prompt times
(the same 7-day plan the reminders use) to the `PhoneSensing` Capacitor plugin on every app start, after each
questionnaire and when the app returns to the foreground. It reports each opened, submitted or abandoned
questionnaire. When participants enter the study, the app shows Android's *Physical activity* dialog if the
study needs it and explains Usage access. The **Phone sensing** tile in the menu shows what is collected,
whether each sensor works, and buttons for any missing permission.

The native side (`android-app/.../sensing/`):

- **Light**: the light sensor is read in the foreground when a questionnaire is opened or submitted.
- **Activity**: `ActivityRecognition.requestActivityTransitionUpdates`, delivered to a broadcast receiver even when
  the app is closed. The registration is renewed on app start, after a reboot or app update, and in every upload round.
- **Accelerometer**: an exact alarm at the start of each window starts a short foreground service
  (type `health`, partial wake lock), which stops by itself at the end of the window.
- **Usage**: `UsageStatsManager.queryEvents`, read afterwards by the upload worker. Daily rows are computed from the
  events (an in-app switch between activities of the same app is not a new launch) and written once the day is over.
  Collection starts when usage was first enabled on the device, never earlier. Android keeps the raw events for
  about a week.
- **Upload**: rows are queued as JSON lines in the app's private storage and uploaded about every hour while online
  (WorkManager), one gzip-compressed request per batch of at most about 400 kB.
- **Upload network** (`phoneSensingUploadNetwork`, set under **Upload over** in the study's Phone sensing panel):
  `any` sends over any connection; `wifi_preferred` (the default) holds batches back on a metered network (mobile
  data, or a Wi-Fi Android treats as metered such as a phone hotspot) until the oldest one has waited
  `phoneSensingWifiFallbackHours` (default 24, 1–168), then sends everything; `wifi_only` never uses a metered
  network. Held back, the app also queues a round that runs as soon as an unmetered network is there. With
  `phoneSensingParticipantNetwork` (default on) the participant can switch **Wait for Wi-Fi** in the app's Phone
  sensing panel, choosing between `any` and `wifi_preferred` (not offered with `wifi_only`); the choice is kept on
  the phone. Collection itself never waits for the network. Questionnaire answers are not affected: they are
  always sent over any connection.

Activity recognition uses Google Play services (`play-services-location`), the app's only Google dependency.
Without Play services that sensor is reported as unavailable and everything else still works.

## Server

`api/phone_sensing.php` accepts `{studyId, userId, accessKey, serverVersion, stream, batchId, rows}`, optionally
gzip-compressed. It checks the access key, that the participant has enrolled (their `joined` event arrived) and has
not left the study, and that the study collects that stream. `backend/PhoneSensingStore.php` then writes the rows
to the main SQLite store (see [SQLite store](../sqlite-store.md)): prompt records, activity transitions and app usage as
one `sensor_data` row each, raw accelerometer samples as compressed chunks in `sensor_chunks` (below). There are **no CSV files**
for phone sensing: the database is the only place the data is kept, so it is written whether or not the
`sqlite_enabled` setting is on, and a database error fails the request so the app sends the batch again. Each batch
is recorded in `sensor_batches` in the same transaction, so a batch the app sends again after a lost response is
recognised and not stored twice; the admin panel's per-stream overview also comes from there.

Answers to the app:

| Status | Meaning | The app |
| --- | --- | --- |
| `ok` / `duplicate` | stored (now or earlier) | deletes the batch |
| `disabled` | the study does not collect this stream (any more) | deletes the batch |
| `rejected` | malformed rows | deletes the batch |
| `withdrawn` | the participant left the study | stops all sensing and deletes everything queued |
| error | e.g. enrolment not on the server yet | keeps the batch and tries again |

Researchers download everything as `phone_sensing.zip` from the Phone sensing panel or the data page. The zip is
generated from the database on each download: one folder per stream, one CSV per participant (server's CSV
delimiter, columns below). The panel also shows how many participants have sent each stream.

When the raw accelerometer is switched on, the panel shows a warning with an estimate of the data it will produce:
samples and server storage per prompt, per participant and day (plus the participant's mobile data for the upload),
and for the whole study. Prompts per day start from the study's scheduled signal times, days from the longest
questionnaire period (30 if none); both and the number of participants can be adjusted for the estimate and are not
saved. It uses measured sizes: about 4 bytes per sample in the database (5 in the estimate, for noisier real data),
about 10 bytes per sample uploaded.

## Accelerometer chunks

A row per accelerometer sample would cost about 155 bytes. Instead, each upload batch (up to about 10,000 samples)
becomes one row of `sensor_chunks` with `start_time`, `end_time`, `sample_count`, `encoding` and the samples in `data`,
about 4 bytes per sample. Encoding `d1` (`backend/AccelerometerCodec.php`): raw deflate of unsigned LEB128 varints:
the sample count, then all time deltas in ms (the first absolute), then all x, all y and all z deltas in units of
0.001 m/s², every delta zigzag-encoded and relative to the previous sample. 0.001 m/s² is finer than phone
accelerometers resolve (about 0.002 m/s²), so nothing measurable is lost. To read the samples outside the server,
use the download or decode the blob as described (a few lines of Python or R).

## Columns of the download

All times are epoch milliseconds; dates are the phone's local `YYYY-MM-DD`.

| File | Columns |
| --- | --- |
| `prompts.csv` | `userId`, `time`, `event` (`opened` / `submitted` / `closed`), `questionnaire_id`, `questionnaire`, `light_lux`, `activity`, `activity_since` |
| `activity.csv` | `userId`, `time`, `activity`, `transition` (`enter` / `exit`) |
| `accelerometer.csv` | `userId`, `time`, `x`, `y`, `z` |
| `usage_daily.csv` | `userId`, `date`, `package`, `foreground_ms`, `launches` |
| `screen_daily.csv` | `userId`, `date`, `screen_on_ms`, `screen_on_count`, `unlocks`, `observed_ms` (less than a day on the first day) |
| `usage_events.csv` | `userId`, `time`, `package`, `event` (`app_foreground`, `app_background`, `screen_on`, `screen_off`, `locked`, `unlocked`, `shutdown`, `startup`) |

## Limits

- Not yet verified on real devices. Manufacturers' battery savers (Xiaomi, Huawei, Samsung and others) can delay or
  block the alarms and the hourly upload.
- Light readings depend on where the phone's sensor is pointing (a phone in a pocket reads close to 0) and are not
  calibrated across models. Compare them within a participant.
- If the participant does not open the app for a week, the prompt plan runs out and no new accelerometer windows are
  scheduled, just like the on-device reminders.
- The data is not used for triggers or charts.
- The raw accelerometer is by far the largest stream: at 20 samples per second, a 20-minute window is 24,000
  samples, about 100 kB in the database. 100 participants with 5 prompts a day for 30 days come to about 1.5 GB.
  Lower the rate or the window if storage matters (see the estimate in the panel).
- Mention what is collected in the informed consent. Google Play requires a prominent disclosure for usage access
  and declarations for the `health` foreground service and activity recognition.
