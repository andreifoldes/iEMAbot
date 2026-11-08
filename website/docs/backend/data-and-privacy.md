---
title: "Data and privacy"
sidebar_label: "Data and privacy"
description: "What data the server stores, where, what is encrypted, which third parties are involved, and what is not yet provided."
---

# Data and privacy

This page lists **mechanisms and facts**. It is not a compliance statement; whether a given study complies
with GDPR depends on how it is run (legal basis, retention, processor agreements, hosting location).

## Where data lives

Everything is a file under the data folder, on your server; see [storage layout](./overview.md#storage-files-plus-one-sqlite-database).
Everything collected from participants is also copied into a SQLite database in the same folder; see
[SQLite store](./sqlite-store.md).

| Data | Location | Encrypted at rest |
| --- | --- | --- |
| Responses | the `responses` table of `iemabot.sqlite` (the only copy; the CSV files of the older server are an archive until `purge_legacy_csv`) | No |
| Media (images, audio, keystroke logs) | `studies/<id>/media/`, and `media` table (bytes optional) | No |
| Wearable measurements | `studies/<id>/.wearables_data/*.csv`, and `wearable_measurements` table | No |
| Wearable OAuth tokens | `studies/<id>/.wearables_tokens/` | **Yes** (libsodium `secretbox`); never written in plaintext (older plaintext files are encrypted when read) |
| Phone sensing (light, activity, accelerometer, app usage) | `sensor_data`, `sensor_chunks` and `sensor_batches` tables of `esmira_data/iemabot.sqlite` only | No |
| Push subscriptions | `studies/<id>/.push_subscriptions/` | No |
| Push funnel events, client telemetry, participant messages | files under `studies/<id>/`, and the `push_events`, `client_info`, `participant_messages` tables | No |
| Provider client secrets, token key, VAPID private key, GitHub token | Server config file in `backend/config/` | **No** (plaintext, outside web root via `.htaccess`) |

### File permissions for credentials

Files that hold credentials are written readable and writable by their owner only (mode `0600`), and the
folders created for them are `0700`. The owner is the web server's user (`www-data` in the Docker image),
which is also the user the cron jobs run as. Other accounts on the machine cannot read them.

| What | Where |
| --- | --- |
| Server config (API keys, VAPID private key, token keys) | `backend/config/configs.php`, and its folder |
| Account password hashes | `esmira_data/.logins` |
| Login tokens | `esmira_data/.loginToken/` |
| Wearable tokens and connection (OAuth) state | `studies/<id>/.wearables_tokens/`, `.wearables_states/` |
| Fallback-server tokens | `.inboundFallbackTokens`, `.outboundFallbackTokens`, `.fallbackSetupTokens` |
| Chat-channel bindings, state, enrolments and invite codes | `.channels/` and `studies/<id>/.channel_bindings/` |

Notes for operators:

- There is no migration. A file written by an earlier version keeps its old mode until the server next
  writes it (a login, a configuration change, a token refresh). `docker-entrypoint.sh` also removes group and
  other access from the config file, the logins file, the login token folder and the wearable token folders each
  time the container starts. It never removes the owner's access.
- When a command is run as `root` inside the container (`docker exec` without `-u www-data`), the file is
  handed to the owner of the folder it lives in, so the web server can still read it. Prefer
  `docker exec -u www-data`, as in [first-time setup](../deployment/docker-and-cron.md#first-time-setup).
- Backup tools that run as a different, non-root user will not be able to read these files. Run them as `root`,
  or back up the Docker volumes.
- The mode is best effort: if the server cannot change the mode (a file owned by another user), it keeps working
  with the mode the file has.
- Responses, media and the collected-data database are not covered by this change.

## Third parties by design

| Party | What is sent |
| --- | --- |
| Browser push services (FCM, Mozilla, Apple) | Encrypted push payloads containing the study or questionnaire title and a short body. |
| Withings / Google Health | OAuth exchange and API pulls, server to provider; the provider also calls the server's webhook when new data exists. |
| Google Fonts | The PWA's service worker fetches the Inter font. |
| GitHub (only when set up, see [error reports as GitHub issues](../deployment/docker-and-cron.md#error-reports-as-github-issues)) | Each "Send error report": the participant's note and recent app errors, both with email addresses, phone and other long numbers, IP addresses, URL query strings, access keys, tokens, @handles removed; the first half of the participant's user id (also where the id appears in the text); the study id, app and server versions, pending uploads, online state, language, and browser and OS major versions. Not sent: the full user id, the full user agent (device model), the server address, the study title, survey answers. Names or addresses a participant writes in the note are not recognised and stay in. |

## Transport security

The application **does not enforce HTTPS**. TLS is expected from Apache's SSL module or, in the reference
deployment, a reverse proxy in front of the container. Push and service workers require HTTPS (or
localhost). The wearables redirect URI falls back to `http` if `$_SERVER['HTTPS']` is unset, so set
`wearables_redirect_uri` explicitly behind a proxy.

## Participant controls

| Control | Available |
| --- | --- |
| Informed-consent screen | Yes (study's `informedConsentForm`) |
| Decline notifications | Yes |
| Unsubscribe from push | Yes |
| Disconnect a wearable (deletes its token and cursor, so nothing more is collected; data collected so far stays with the study; the app tells the participant to contact the researchers to have it removed) | Yes |
| Sign out (clears local storage, IndexedDB, caches, service worker, push subscription; records a `quit` event so the server sends nothing more, on any device) | Yes |
| Participant-initiated deletion of **response data** | <span className="status status--tbc">TBC</span> not provided: the participant asks the researchers (the wearables screen says so), who use **Delete a participant's data** below |

## Researcher controls

**Delete a participant's data** (Push notifications panel, next to *Withdraw participant*; admin action `DeleteParticipantData`,
needs write permission on the study, POST only, the participant id typed twice) removes everything one participant left in one
study, from the files and the database:

| Removed | Notes |
| --- | --- |
| Answers and events | Their lines in every response CSV of the study: the live files, the dated copies from *Create backup* and `.bak` / fix-backup copies. Other participants' lines are left byte for byte as they were |
| Database rows | Every table with a study and participant column (so a table added later is covered), with the deleted content overwritten and the write-ahead log emptied |
| Media | Their images, audio and keystroke logs, and uploads still waiting; the cached media ZIP is dropped |
| Messages | Both directions, in all three message folders |
| Push and chat | Subscription and state, client info, push funnel events, the chat channel binding and its index entry |
| Wearables | Tokens, downloaded data, sync cursor, notification archive, the scheduler log and any connect flow still open |
| Study files | Their user data file and participant variables |
| Spool | Rows the database refused that still wait in `esmira_data/.spool` (only when `write_csv_files` is off) |
| App logs | The participant id inside uploaded app logs is replaced by `[erased]`; the rest of a log stays |

Each kind is its own step, so one failure is reported and the rest still run; run it again to finish. The answer lists counts
only. The optional *also withdraw* box stops reminders and resets their app; the withdrawn marker keeps the participant id so it
can do that, which is the one deliberate trace.

**Not covered**, so say so when answering a participant: combined study statistics (they cannot be taken apart), copies of the
whole server folder made by hand (`esmira_data/backup-*`), study snapshot ZIPs, and server error reports, which can quote an id.

Other researcher controls:

CSV, media and wearable export; study backup; **Reset study** (wipes responses, statistics, media) and
delete study; **Withdraw participant** (Push notifications panel: stops every reminder for one participant,
see [Leaving the study](./channels/web-push.md#leaving-the-study)). Reset does not currently clear the wearables, push
or withdrawal folders. Reset and delete also remove the
matching rows from the SQLite database (Reset: responses, web access and media; Delete: everything for the
study).

## Telemetry recorded without a separate consent step

- Push funnel events (sent, arrived, opened, failed).
- Client info: installed-as-PWA vs browser tab, and device class.
- The PWA stores the browser `userAgent` as a model string on each questionnaire event.

## Keystroke capture

The typing fallback records a **content-free event log** (letters are bucketed into classes, never stored as
characters) as a CSV, **and** the typed text itself in the `<name>~text` column. The log therefore does not
reveal text, but the response does. In the Android app the native keyboard-timing hook classifies characters
on the device too and passes only the class and a timestamp to the web layer.

## Not yet provided

<span className="status status--tbc">TBC</span> DPIA template, retention policy guidance, hardening
checklist, enforced HTTPS, removal or encryption of plaintext secrets in the server config, and a
participant-initiated data deletion path. Compliance documentation, including a DPIA, is **in progress**.
See [Current status](../current-status.md).
