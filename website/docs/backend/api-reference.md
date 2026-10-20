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
| `push_subscribe.php` | POST | Store or delete a Web Push subscription with `tzOffset`. | **fork** |
| `push_test.php` | POST | Send a welcome/test push to the caller's own subscription. | **fork** |
| `push_status.php` | POST | Subscription status for the caller. | **fork** |
| `push_event.php` | POST | Log client funnel events (`received`, `clicked`; `welcome_*` accepted but not persisted). | **fork** |
| `client_info.php` | POST | Record installed-vs-browser and device class. | **fork** |
| `wearables_connect.php` | POST | Start OAuth; returns `authUrl`. | **fork** |
| `wearables_oauth.php` | GET | OAuth redirect target; exchanges the code. | **fork** |
| `wearables_status.php` | POST | Connected providers, last sync and `needsReconnect` (provider rejected the link). | **fork** |
| `wearables_disconnect.php` | POST | Delete token, data and cursor for a provider. | **fork** |

The remaining upstream participant endpoints are `access.php`, `app_install_instructions.php`,
`questionnaire.php`, `reward.php`, `save_errors.php`, `save_merlin_log.php`, `server_statistics.php`,
`settings.php`, `statistics.php`, `update.php` and `checkHtaccess.php`.

:::warning[Light authentication on participant endpoints]
`push_subscribe`, `push_test`, `client_info` and the wearables status/disconnect endpoints accept any `userId`
that passes `Main::strictCheckInput`. They are designed for pseudonymous participants and carry no further
credentials. Treat user IDs as secrets, and see [Data and privacy](./data-and-privacy.md).
:::

## Admin endpoints

All go through `admin.php?type=<Name>`, grouped by required permission.

| Permission | Examples |
| --- | --- |
| none | `Login`, `Logout`, `InitESMira`, `GetPermissions` |
| logged in | study list and full study, `ChangePassword`, tokens, bookmarks |
| message | list/send/delete messages, `ListParticipants` |
| read | `ListData`, `GetData` (CSV), `CreateMediaZip`, `GetMedia`, Merlin logs, **`GetWearableDataZip`**, **`GetWearableInfo`** |
| reward | reward-code management |
| write | `SaveStudy`, `DeleteStudy`, `FreezeStudy`, `EmptyData`, `BackupStudy`, **`GetPushInfo`**, **`GetPushStats`**, **`SendTestPush`**, **`SendTestPushToParticipant`** |
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

## Response row format

Submitted values match the stock web client so CSVs stay identical: Likert `1..N`, binary `0`/`1`, single
choice = the label, multiple choice = `name~i` booleans, time `HH:MM`, duration in total minutes, date
`YYYY-MM-DD`. Fork-added suffixes: `name~other` (other-specify), `name~text` and `name~capture_mode`
(keystrokes).
