---
title: "SQLite store"
sidebar_label: "SQLite store"
description: "The server-side SQLite database that receives a copy of everything collected from participants: what it holds, how it is written, how to query, back up and backfill it."
---

# SQLite store

<span className="status status--shipped">Shipped</span> · fork-added

Everything collected from participants is also written to **one SQLite database on the server**:
`esmira_data/iemabot.sqlite`. It lives on the same volume as the CSV files, is denied to the web by the data
folder's `.htaccess`, and is covered by the same backups and server snapshots.

:::info[The files are still written]
The CSV, JSONL and media files remain the source for the researcher panel and its exports, so nothing that
worked before changed. The database is the **queryable copy** on the server. Writes are best-effort: a
database error is logged once per request to the server's error reports and never fails a participant's
upload.
:::

## What goes in

| Table | Content | Written when |
| --- | --- | --- |
| `responses` | One row per questionnaire submission (`kind = questionnaire`) or event (`kind = event`). The full row is a JSON object in `data`, keyed by the study's CSV column names; `study_id`, `questionnaire_id`, `user_id`, `entry_id` and `response_time` are also real columns. | A dataset is accepted |
| `web_access` | Hits recorded for the no-JS study page | A web access is recorded |
| `media` | Uploaded images, audio and keystroke logs: metadata, SHA-256 and, for files up to the size cap, the bytes in `content` | A file upload completes |
| `participant_messages` | Messages **sent by participants** to the researchers | A message is received |
| `push_events` | Push funnel: `sent`, `failed`, `received`, `clicked`. One row per event, so a participant with several devices has several identical `sent` rows | Each event |
| `client_info` | Latest installed-as-PWA flag and device class per participant | Each report (latest wins) |
| `wearable_measurements` | Synced Withings / Google Health measurements | Each hourly sync |

Storing the response as JSON keyed by column name means adding or renaming a study variable never needs a
schema change. Rows are de-duplicated on who sent them and when (user, entry id, response time), not on the
column set, so re-importing the same data is harmless even after a researcher has edited the study.

Not copied, on purpose: researcher replies to participants, derived statistics, and operational secrets
(push subscriptions, wearable OAuth tokens, server config). Researcher accounts and study definitions are
not participant data.

## Reset and delete

**Reset study** removes the study's `responses`, `web_access` and `media` rows, exactly mirroring what it
removes from disk. **Delete study** removes every row for the study from every table. Other studies are not
touched. As with the files, Reset does not clear push, wearable or message data.

## Configuration

Both keys live in the server config and default to on.

| Key | Default | Effect |
| --- | --- | --- |
| `sqlite_enabled` | `true` | Turn the whole store off. Existing rows are kept, and Reset/Delete still clean them. |
| `sqlite_store_media_content` | `true` | Keep a copy of uploaded media bytes in `media.content`. Set `false` to store only metadata and the checksum; the files stay on disk either way. |
| `sqlite_media_content_max_bytes` | `16000000` | Files larger than this keep only metadata and checksum in the database (the file stays on disk). The cap exists because the copy is read into PHP memory. |

Requires PHP's `pdo_sqlite`, which is compiled into the base image; the `Dockerfile` fails the build if it
is ever missing.

## Importing data collected before the store existed

Run once after the first deploy that includes the store. It reads the existing CSV, JSONL, JSON and media
files and inserts whatever is not yet in the database, so it is safe to run again and safe to run while the
server is live:

```bash
docker exec -u www-data <container> php /var/www/html/cli/sqlite_backfill.php
```

It prints one line per study and a final count of inserted rows per table. It also recovers older rows that
were moved into dated files (`<date>_<id>.csv`) when a study's variables changed. It writes in small
transactions, so live collection keeps working while it runs.

## Querying

The `sqlite3` command-line tool is not in the image. Take a consistent snapshot, copy it off the server and
query it locally:

```bash
# on the VPS
docker exec -u www-data <container> php -r \
  '(new PDO("sqlite:/var/www/html/esmira_data/iemabot.sqlite"))->exec("VACUUM INTO \"/tmp/iemabot-snapshot.sqlite\"");'
docker cp <container>:/tmp/iemabot-snapshot.sqlite .
```

```sql
-- responses per study and questionnaire
SELECT study_id, questionnaire_id, COUNT(*) FROM responses WHERE kind = 'questionnaire' GROUP BY 1, 2;

-- one answer column out of the JSON
SELECT user_id, response_time, json_extract(data, '$.mood') AS mood
FROM responses WHERE study_id = 1 AND questionnaire_id = 7;
```

The database runs in WAL mode, so do not copy `iemabot.sqlite` alone while the server is live; use the
snapshot above.

## Snapshots and backups

Server snapshots contain a **transactionally consistent copy** of the database (made with `VACUUM INTO`),
not the live file, because a plain copy of a database in WAL mode can be torn. For file-level backups, use
the snapshot recipe above rather than copying `iemabot.sqlite` while the server runs.

## Backups and privacy

The database holds the same personal data as the CSV files, **unencrypted at rest**, including a copy of
media when `sqlite_store_media_content` is on. The database file lives in `esmira_data/`, so a backup of
that folder includes it, but copy the database with the snapshot recipe above (or stop the container) rather
than as a bare file while the server runs; `iemabot.sqlite-wal` and `-shm` appear next to it then. See
[Data and privacy](./data-and-privacy.md).
