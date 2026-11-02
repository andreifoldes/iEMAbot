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
| Responses | `studies/<id>/responses/*.csv`, and `responses` table of `iemabot.sqlite` | No |
| Media (images, audio, keystroke logs) | `studies/<id>/media/`, and `media` table (bytes optional) | No |
| Wearable measurements | `studies/<id>/.wearables_data/*.csv`, and `wearable_measurements` table | No |
| Wearable OAuth tokens | `studies/<id>/.wearables_tokens/` | **Yes** (libsodium `secretbox`), with plaintext fallback |
| Phone sensing (light, activity, accelerometer, app usage) | `sensor_data`, `sensor_chunks` and `sensor_batches` tables of `esmira_data/iemabot.sqlite` only | No |
| Push subscriptions | `studies/<id>/.push_subscriptions/` | No |
| Push funnel events, client telemetry, participant messages | files under `studies/<id>/`, and the `push_events`, `client_info`, `participant_messages` tables | No |
| Provider client secrets, token key, VAPID private key, GitHub token | Server config file in `backend/config/` | **No** (plaintext, outside web root via `.htaccess`) |

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
| Disconnect a wearable (deletes its token, data, cursor) | Yes |
| Sign out (clears local storage, IndexedDB, caches, service worker, push subscription; records a `quit` event so the server sends nothing more, on any device) | Yes |
| Participant-initiated deletion of **response data** | <span className="status status--tbc">TBC</span> not provided |

## Researcher controls

CSV, media and wearable export; study backup; **Reset study** (wipes responses, statistics, media) and
delete study; **Withdraw participant** (Push notifications panel: stops every reminder for one participant,
see [Leaving the study](./web-push.md#leaving-the-study)). Reset does not currently clear the wearables, push
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
