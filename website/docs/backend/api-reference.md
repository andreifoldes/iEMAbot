---
title: "API reference"
sidebar_label: "API reference"
description: "Public and admin endpoints of the ESMira server, with the endpoints added by this fork marked."
---

# API reference

Endpoints live in `src/api/` and are served below the ESMira root (for example `/esmira/api/…`). The PWA
reads studies with `GET` and writes data with `POST`.

:::info[Versions]
`SERVER_VERSION` is **12** in this fork (upstream: 11). `ACCEPTED_SERVER_VERSION` is unchanged at 7, so older
clients keep working.
:::

## Participant-facing endpoints

| Endpoint | Method | Purpose | Origin |
| --- | --- | --- | --- |
| `studies.php?access_key=KEY[&id=ID]` | GET | Study definition by invite key. The fork adds `vapidPublicKey` and `wearableProviders` to the response. | upstream, extended |
| `datasets.php` | POST | Receive responses and events. The PWA sends the same shape as the native apps (`appType: 'Web'`). | upstream |
| `file_uploads.php` | POST | Media upload. The fork adds the `Keystrokes` type (`text/*` or `application/csv`). The PWA also uses `Audio`. | upstream, extended |
| `save_message.php` | POST | Participant contact message / error report. | upstream |
| `push_subscribe.php` | POST | Store or delete a Web Push subscription with `tzOffset`. Refused (`errorCode` 410) for a participant who has left the study. | **fork** |
| `push_test.php` | POST | Send a welcome/test push to the caller's own subscription. | **fork** |
| `check_pid.php` | POST | Asked by the app before it stores anything for a participant id: `{studyId, userId, accessKey, serverVersion}` -> `{allowed, userId}`, where `userId` is the id as the study spells it. Only restricted studies (see [Who may join](#who-may-join)) ever answer no. | **fork** |
| `pid_lock.php` | POST | A device takes a participant id, for studies that allow one device per id: `{studyId, userId, deviceToken, accessKey, serverVersion, force?, release?}` -> `{status}` (`claimed`, `already_yours`, `conflict`, or `released`). `force` transfers the id to this device; `release` gives it up (sign-out). See [One device at a time](#one-device-at-a-time). | **fork** |
| `push_status.php` | GET | Next reminder and schedule anchor for the caller, plus `withdrawn: true` once they have left the study (the app then resets itself). | **fork** |
| `schedule.php` | GET | What the app needs to compute the study's schedule itself (`?studyId&userId`): the caller's answers the schedule reads (usual sleep times) and the wearable events it reacts to. The app caches it and computes the windows with its own completions. | **fork** |
| `native_pending.php` | POST | Android app's background poll for server-driven nudges; `active: false` stops the poll (also when the study's Android notifications are off or the participant switched theirs off, `notificationsOff: true`) and `withdrawn: true` stops it for good. | **fork** |
| `app_version.php` | GET | The Android app built from the same commit as the server (`{versionCode, versionName, sha, apk}`, or null); the app offers the update when it is newer. See [CI and release](../deployment/ci-and-release.md#android-lockstep). | **fork** |
| `notification_prefs.php` | POST | The participant's notification switch from the app's settings (`off`, `channel`); the server stops sending to them on every channel while it is off. | **fork** |
| `push_event.php` | POST | Log client funnel events per `channel` (`scheduled`, `received`, `clicked`, `welcome_confirmed`, `welcome_missed`; `count` for batches). | **fork** |
| `client_info.php` | POST | Record installed-vs-browser and device class. | **fork** |
| `wearables_connect.php` | POST | Start OAuth; returns `authUrl`. | **fork** |
| `wearables_oauth.php` | GET | OAuth redirect target; exchanges the code. | **fork** |
| `wearables_status.php` | POST | Connected providers, last sync and `needsReconnect` (provider rejected the link). | **fork** |
| `wearables_disconnect.php` | POST | Delete the token and cursor for a provider: collection stops, data collected so far stays. | **fork** |
| `phone_sensing.php` | POST | Android app's upload of phone sensing data (light, activity, accelerometer, app usage), one batch per request; see [Phone sensing](./channels/phone-sensing.md). | **fork** |

The remaining upstream participant endpoints are `access.php`, `app_install_instructions.php`,
`questionnaire.php`, `reward.php`, `save_errors.php`, `save_merlin_log.php`, `server_statistics.php`,
`settings.php`, `statistics.php`, `update.php` and `checkHtaccess.php`.

:::warning[Light authentication on participant endpoints]
`push_subscribe`, `push_test`, `client_info` and the wearables status/disconnect endpoints accept any `userId`
that passes `Main::strictCheckInput`. They are designed for pseudonymous participants and carry no further
credentials. Treat user IDs as secrets, and see [Data and privacy](./data-and-privacy.md).
:::

### Who may join {#who-may-join}

A study is **open** (`pidMode: "open"`, the default: anyone with an access key may join, with any participant id) or
**restricted to a list** (`pidMode: "list"`: only ids the researcher listed). The list is kept in its own file in the
study folder, never in the study that every participant downloads. In a restricted study the server refuses every
participant request for an id that is not on the list, with `errorCode` 403 (uploads: the dataset's error line says
"This participant ID is not allowed in this study"), except for ids it already knows. An id is known once its first
upload has created the study's record for it, so participants who joined while the study was still open keep working
when it is switched to a list, and keep working if their id is later taken off the list. To remove someone, use
`WithdrawParticipant`.

Ids are compared exactly. `check_pid.php` additionally matches a typed id in any letter case and returns the listed
spelling, so one participant never ends up with two spellings. Telegram invitations must name a listed id.
`phone_sensing.php` already refuses ids the study has not seen. Participant ids still work like passwords: whoever
knows or guesses a listed id can use it.

### One device at a time {#one-device-at-a-time}

A study can let a participant id be active on any number of devices (`pidDevices: "multi"`, the default) or on one at a time
(`"single"`). With `single`, the first device whose token the server sees for an id holds it. The app sends its device token
(`deviceToken`: a field of the JSON body, the query string or the form) with its requests, and a request from another device is
refused with `errorCode` 409 ("This participant ID is active on another device"). An upload from another device is refused as a
whole, never as a per-dataset error: the apps take a per-dataset error for "sent" and would drop answers they should keep. The
person who holds the id can transfer it to a new device (`pid_lock.php` with `force`); the old device is then refused. The Android
app's background requests (`native_pending.php`, `phone_sensing.php`) get a plain answer instead: `otherDevice: true` /
`status: "other_device"`, which makes them stop.

A request **without** a token is let through: apps from before this feature do not send one, and cutting them off would be
worse than leaving them unrestricted until they update. A token is a UUID or the app's fallback `dt-...`, made on the device
and kept in its browser storage; clearing storage makes the device a new one. An id remains a shared secret: whoever knows it can
transfer it to their device. A lock that cannot be written never stops an upload.

## Admin endpoints

All go through `admin.php?type=<Name>`, grouped by required permission.

| Permission | Examples |
| --- | --- |
| none | `Login`, `Logout`, `InitESMira`, `GetPermissions` |
| logged in | study list and full study, `ChangePassword`, tokens, bookmarks |
| message | list/send/delete messages, `ListParticipants` |
| read | `ListData`, `GetData` (CSV), `CreateMediaZip`, `GetMedia`, Merlin logs, **`GetWearableDataZip`**, **`GetWearableInfo`** |
| reward | reward-code management |
| write | `SaveStudy`, `DeleteStudy`, `FreezeStudy`, `EmptyData`, `BackupStudy`, **`GetPushInfo`**, **`GetPushStats`**, **`SendTestPush`**, **`SendTestPushToParticipant`**, **`WithdrawParticipant`**, **`DeleteParticipantData`**, **`GetAllowedPids`**, **`SetAllowedPids`** |
| create | `CreateStudy` |
| admin | accounts, server config (`GetServerConfig` now also returns `webOnlyMode`), error reports, plugins, snapshots, updates, `RebuildStudyIndex` |

Bold entries are fork-added.

## CLI scripts (fork-added)

| Script | Purpose |
| --- | --- |
| `cli/generate_vapid.php [--force]` | Create VAPID keys. |
| `cli/push_send_due.php` | Send due pushes (cron, every minute). |
| `cli/push_test.php` | Manual push test. |
| `cli/wearables_setup.php` | Provider credentials and `genkey`. |
| `cli/wearables_sync.php` | Pull wearable data (cron, hourly). |
| `cli/github_issues_setup.php` | `set <owner/repo>` (token on stdin), `status`, `off`: file error reports as GitHub issues. |
| `cli/github_issues_send.php` | Post queued error reports as issues (cron, every minute). |

## Response row format

Submitted values match the stock web client so CSVs stay identical: Likert `1..N`, binary `0`/`1`, single
choice = the label, multiple choice = `name~i` booleans, time `HH:MM`, duration in total minutes, date
`YYYY-MM-DD`. Fork-added suffixes: `name~other` (other-specify), `name~text` and `name~capture_mode`
(keystrokes).
