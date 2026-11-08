---
title: "SQLite store"
sidebar_label: "SQLite store"
description: "The server-side SQLite database that receives a copy of everything collected from participants: what it holds, how it is written, how to query, back up and backfill it."
---

# SQLite store

<span className="status status--shipped">Shipped</span> · fork-added

Everything collected from participants is stored in **one SQLite database on the server**:
`esmira_data/iemabot.sqlite`. It is the only copy of the answers: the server writes no response CSV files. CSV exists as the researcher's
downloads (and the dated copies of "Create backup"), built from the database on request. The database lives on the same volume as the rest of
the data folder, is denied to the web by the data folder's `.htaccess`, and is included when a server snapshot is made. Write a nightly backup
with [`cli/sqlite_backup.php`](#backups-clisqlite_backupphp) and move it off the server. It is the one place to run queries across studies,
participants and data types.

## What lives where

| Data | Stored in |
| --- | --- |
| Questionnaire responses and events | the database (`responses`) |
| Web access hits | the database (`web_access`) |
| Wearable measurements and provider notifications | the database (`wearable_measurements`, `wearable_events`) |
| Messages in both directions, push funnel, client info | the database, besides their message and state files |
| Uploaded images, audio and keystroke logs | the files themselves, and a copy of the bytes in `media` (up to the size cap) |
| **Phone sensing** (Android) | the database (`sensor_*`) |

Answers and wearable data are **required writes**: an upload is stored in one transaction per questionnaire or event batch before the statistics
queue and the waiting file uploads (so a refused upload leaves nothing queued that a resend would count twice). A row the database refuses goes
to the [spool](#the-spool-rows-the-database-refused) and the participant still gets "saved"; an alert is raised. When the spool fails as well,
nothing is stored and the upload is answered with **HTTP 503**: the apps keep the data and send it again (the PWA queues on any 5xx without
counting it as a refusal). The participant's entry counter is only saved after a successful store, so the resend carries the same entry id and
the database ignores a row it already holds. The wearable sync does not move its cursor past days it could not keep. The database runs with
`synchronous=FULL`, so a commit has reached the disk.

The rest (push events, client info, messages, media metadata) is still written best-effort next to its own files: a database error is logged once
per request and never fails a participant's request, and the [reconcile check](#checking-that-the-database-is-complete) and the
[backfill](#importing-data-collected-before-the-store-existed) repair any gap. `sqlite_enabled` only switches these extras (and the nightly snapshot
copy) off.

## The spool (rows the database refused)

When the response CSV files stop being written, the database is the only copy of an answer. A failed insert must then neither lose
the answer nor leave the app guessing. `Spool` (`esmira_data/.spool/<study>.jsonl`, folder `0700`, files `0600`) is where such rows
go: one JSON line per row, appended under a lock and flushed to disk, with an alert that repeats each time the backlog doubles.
It is not a research export and not a second source of truth; it should be empty.

- `cli/sqlite_spool_import.php` moves the rows into the database. A line leaves the spool only after its insert has committed,
  rows the database still refuses stay, and a line that cannot be read is never thrown away. Importing twice adds nothing
  (same row identity as every other write). Exit code `0` = empty, `2` = rows waiting.
- `sqlite_reconcile.php` prints the spool and is **not consistent** while it holds rows: a spooled row is in neither the database
  nor a file. Anything that claims the database is complete should call `Spool::pendingCount()`.
- Resetting or deleting a study empties its spool; erasing one participant must too (`Spool::deleteParticipant`).

## Backups (`cli/sqlite_backup.php`)

:::danger[TODO: no automatic backup is set up]
The database is the **only copy** of the answers (the CSV files are gone), and nothing backs it up automatically. The job below exists and is
tested, but it is **not scheduled** and there is **no off-server destination** yet. The only copies are two one-off snapshots made by hand on
2026-11-06 (in the container's `/tmp` and in a directory on the host), on the same disk as the data. To close this: choose a
destination off the server, add the nightly job with the copy, restore one backup once to prove it, then remove this note and the
`TODO(backup)` comments in `CollectedDataDb` and `cli/sqlite_backup.php`.
:::

Once the CSV files are not written the database is the only copy of the answers, so it needs a backup that survives losing the server.
`cli/sqlite_backup.php --to=<folder> [--keep=14]` writes a consistent copy (`VACUUM INTO`, safe while the server runs), opens it read-only,
requires `PRAGMA integrity_check` to pass and every table to be present, writes a `.sha256` file, and only then removes backups beyond the
newest `--keep`. It touches nothing else in the folder. A failure raises an error report and exits `1`. The copy also works as the
`--backup` of `purge_legacy_csv.php`. It warns when rows wait in the spool, because those are in no backup.

The folder must be absolute and **outside** the data folder (a mounted volume). Getting the files off the server is the owner's choice, one
more line in the same cron job, for example:

```
# host crontab, nightly at 02:30
30 2 * * * docker exec -u www-data esmira-esmira-1 php /var/www/html/cli/sqlite_backup.php --to=/backups --keep=14 \
  && rsync -a --delete-after /srv/esmira-backups/ backup-host:/path/esmira/   # or rclone sync, a mounted share, ...
```

Test a restore once: copy a backup to a scratch folder, open it with `sqlite3` and count the rows (the integrity check proves the file is sound,
not that it is the file you meant).

## The study keeps only the column index

The study keeps the column list of each questionnaire (`.responses_index`) and no response file:

- saving a study or adding a variable updates the index (new names go at the end, removed ones stay) and rewrites nothing; while there are
  no answers the index is simply the new one, also after a reset;
- *Create backup* writes dated copies as an export of the database at that moment, listed and downloadable;
- "does this questionnaire exist" is answered from the index; the data views and downloads come from the database. They refuse
  while rows wait in the spool, because a spooled row is in neither place;
- files written by the older server (the response CSVs, `.wearables_data/*.csv`, `.wearables_event_archive/*.events.csv`) stay untouched as a
  read-only archive until [`purge_legacy_csv`](#purge); the backfill and the reconcile check still read them.

## What goes in

| Table | Content | Written when |
| --- | --- | --- |
| `responses` | One row per questionnaire submission (`kind = questionnaire`) or event (`kind = event`). The full row is a JSON object in `data`, keyed by the study's CSV column names; `study_id`, `questionnaire_id`, `user_id`, `entry_id` and `response_time` are also real columns. | A dataset is accepted |
| `web_access` | Hits recorded for the no-JS study page | A web access is recorded |
| `media` | Uploaded images, audio and keystroke logs: metadata, SHA-256 and, for files up to the size cap, the bytes in `content` | A file upload completes |
| `participant_messages` | Messages **sent by participants** to the researchers | A message is received |
| `researcher_messages` | Messages the **researchers sent** to a participant, with their state: `pending` (waiting for the app; `delivered` counts the fetches) or `archived` (handed over; `read_at`). The message files decide the state; a taken-back message is deleted. The database keeps messages the archive file drops after `max_msgs_per_user` | A message is sent, fetched by the app, acknowledged or deleted |
| `push_events` | Push funnel: `sent`, `failed`, `received`, `clicked`. One row per event, so a participant with several devices has several identical `sent` rows | Each event |
| `client_info` | Latest installed-as-PWA flag and device class per participant | Each report (latest wins) |
| `wearable_measurements` | Synced Withings / Google Health measurements | Each hourly sync |
| `wearable_events` | One row per provider notification of a linked wearable: `provider`, `event` (sleep, activity, weight, ...), `detail` (the provider's own type), `received_at` and the period it covers (`interval_*` in UTC ms, `local_*` as the participant's clock time, empty when the provider omits it). Same fields as the archive CSV, so a row is identical in both | Each webhook notification that is not a repeat |
| `sensor_data` | Phone sensing from the Android app (see [Phone sensing](./channels/phone-sensing.md)): one row per prompt record, activity transition, app event or daily summary. `stream`, `user_id`, `sample_time` (epoch ms, 0 for daily rows), `local_date` (daily rows) and `label` (activity, package or event) are real columns; the rest is JSON in `data`, keyed by the stream's columns. Kept only here | Each upload batch |
| `sensor_chunks` | Raw accelerometer samples from phone sensing, one compressed chunk per upload batch (`start_time`, `end_time`, `sample_count`, `encoding`, `data`; about 4 bytes per sample, format in [Phone sensing](./channels/phone-sensing.md#accelerometer-chunks)). Kept only here | Each upload batch |
| `sensor_batches` | One row per phone sensing upload batch (participant, stream, batch id, row count, time): recognises re-sent batches and feeds the admin overview | Each upload batch |

Storing the response as JSON keyed by column name means adding or renaming a study variable never needs a
schema change. Rows are de-duplicated on who sent them and when (user, entry id, response time), not on the
column set, so re-importing the same data is harmless even after a researcher has edited the study.

Not copied, on purpose: researcher replies to participants, derived statistics, and operational secrets
(push subscriptions, wearable OAuth tokens, server config). Researcher accounts and study definitions are
not participant data.

## Reset and delete

**Reset study** removes the study's `responses`, `web_access` and `media` rows, exactly mirroring what it
removes from disk. **Delete study** removes every row for the study from every table. Other studies are not
touched. As with the files, Reset does not clear push, wearable, message or phone sensing data.

**Delete backups** deletes the dated copies of the response files and the files of questionnaires that were
removed from the study. The database keeps no copies of rows, so it has nothing to remove for the dated copies,
but the `responses` rows of removed questionnaires are deleted along with their files. Events, web access and
the rows of current questionnaires stay.

## Configuration

These keys live in the server config and default to on.

| Key | Default | Effect |
| --- | --- | --- |
| `sqlite_enabled` | `true` | Switches the best-effort extras (push events, client info, messages, media metadata, the snapshot copy) off. Answers and wearable data are stored whatever this says. |
| `sqlite_store_media_content` | `true` | Keep a copy of uploaded media bytes in `media.content`. Set `false` to store only metadata and the checksum; the files stay on disk either way. |
| `sqlite_media_content_max_bytes` | `16000000` | Files larger than this keep only metadata and checksum in the database (the file stays on disk). The cap exists because the copy is read into PHP memory. |

Requires PHP's `pdo_sqlite`, which is compiled into the base image; the `Dockerfile` fails the build if it
is ever missing.

## Downloads and data views

The researcher panel's data views and downloads (questionnaires, events, web access) are generated from the `responses` and `web_access`
tables, always for the one study the request is for and only for researchers with read permission on it. The header is the study's response
index, columns a row never had are empty and values are quoted like the old files. Known behaviour:

- **Row order** follows the time the server received the row (`uploaded`), then insertion order.
- Dated copies (`<date>_<id>.csv`) made by "Create backup" are files and keep their plain download.
- Text the database had to repair is not byte-exact: an answer cut in the middle of a multibyte character by `user_input_max_length` comes out
  with U+FFFD, and two web hits identical down to the millisecond are stored once.
- A questionnaire removed from the study has no download. Its rows stay in the database until "Delete backups" is used.
- Everything refuses while rows wait in the spool.

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

The backfill imports the live files first, then the dated copies, so a row that is in both keeps the live
file's version. It **never imports a line with more cells than its header** (a copy cut to the header's width
would be a wrong row); those lines are listed by the reconcile. To correct rows whose cells differ from the live
files, run it with `--refresh`:

```bash
docker exec -u www-data <container> php /var/www/html/cli/sqlite_backfill.php --refresh
```

## Checking that the database is complete

A read-only check compares the archive files of the older server with the database and reports, per study and questionnaire, rows that
are in a file but not in the database (**missing**) and rows in the database that no file holds
(**db-only**, which is normal now and never a difference). It also reports the spool: rows waiting there make the check not consistent. It works on a temporary snapshot copy, never writes to the live database and is safe to run
while the server is live:

```bash
docker exec -u www-data <container> php /var/www/html/cli/sqlite_reconcile.php
```

It also compares the **cells** of every row of the live files with the database's copy (`changed_rows`): a
row that is in both but holds other cells in the database is a difference even though the identities match.
Exit code `0` means consistent, `2` means differences were found. Missing rows are imported by running the
backfill above. Identical duplicate lines in a CSV count once in `csv rows`, because the database stores them
once.

## Downloads as CSV or Excel {/* #downloads */}

In the researcher panel's data list, every questionnaire, **events** and **web access** has two download links: **CSV** and **XLSX**
(Excel). Both are built from the database on request (`GetData` with `format=csv` or `format=xlsx`; without `format` the admin
screens get the plain feed they have always read).

- **CSV** has the same columns and rows as the plain feed. Cells that a spreadsheet would run as a formula (text that starts with
  `=`, `+`, `-`, `@`, a tab or a carriage return, except plain numbers like `-3.5`) get a `'` in front, which a spreadsheet hides, so
  opening a participant's answer in Excel cannot run it. **This changes the data**: a phone number such as `+49 151 2345` reads as
  `'+49 151 2345` in R, Stata or pandas, so strip a leading `'` there or use the XLSX. Every cell is quoted, and a `"` inside a cell is
  doubled.
- **XLSX** stores text as text, so a formula cannot happen at all; plain numbers are stored as numbers (they sum), ids with leading zeros
  (`007`) stay text. It is written without any library (`backend/XlsxWriter.php`), streamed, so a large file does not need much memory.
- **No file to fall back to.** If the database cannot serve the download (a stored row is damaged, or it fails) the server answers with an error message instead of a file, so a researcher is never handed a partial file without knowing. The file is built completely (in a temporary file) before the first byte is sent, so a failure half way is an error, never a cut-off download. Run the [reconcile check](#checking-that-the-database-is-complete) and, if needed, the backfill.
- The column list comes from the study's response index (`.responses_index/<id>`).
- Dated backup copies (`<date>_<id>.csv`) are files and keep their plain download.

## Deleting the old CSV files {/* #purge */}

The server no longer writes CSV files, so the files of the older server are only an archive. They
are kept for **one week at most**, then removed by one explicit command, never silently:

```bash
docker exec -u www-data <container> php /var/www/html/cli/purge_legacy_csv.php --backup=/path/to/copy.sqlite            # dry run: lists files
docker exec -u www-data <container> php /var/www/html/cli/purge_legacy_csv.php --backup=/path/to/copy.sqlite --apply    # deletes them
```

`--backup` is a separate copy of the database (made with `VACUUM INTO`, as a server snapshot does). The command **refuses** unless all of this holds:

- the live database holds every row, changed cell and wide line of the files (the [reconcile check](#checking-that-the-database-is-complete), ignoring rows only the database has);
- the backup is a different file, opens read-only, passes `PRAGMA integrity_check`, and holds every row of the files too;
- no row waits in the [spool](#the-spool-rows-the-database-refused) (rows the database refused; import them with `cli/sqlite_spool_import.php` first);
- `--days` is at least 7 (the default).

Only response files in a study's `responses/` folder (`<id>.csv`, `events.csv`, `web_access.csv` and their dated copies) that have not changed for
that long are listed or deleted. It prints names and sizes, never answers. Resetting or deleting a study removes its files at once, as before.

## Tests that no data is lost

Two suites in `test/backend/sqlite/` run **the real code with no mocks**: a study saved through the study
store, uploads through `CreateDataSet`, the SQLite store, the researcher's downloads and
`sqlite_reconcile`.

- **Every question type.** For each of the 28 input types of the study editor, an answer in the form the apps
  send it is checked in the database and in the CSV built from it, together
  with the neighbouring answer and the event record. File answers (`photo`, `file_upload`, `record_audio`,
  `record_keystrokes`) are uploaded and checked for the file, its checksum and the bytes kept in the database.
  One questionnaire holding every type, hostile text (delimiters, quotes, line breaks, emoji, over-long
  answers), a variable added later, a resend, a refused upload, and a database that fails every insert or is unusable (the answers wait in
  the spool and are imported) are covered too.
- **Every wearable data type.** For each data type of Withings and Google Health, a canned API answer goes
  through the real provider and the database; every field of every record, every page of a
  day's data points, the researcher's ZIP and the import of a file the older server wrote are
  checked.

**Guards.** A new input type in the study editor, a new wearable provider or a new data type fails a test
until it gets a fixture (`QuestionTypeFixtures`, `WearableFixtures`), so coverage cannot silently fall behind.

Behaviour these tests pin on purpose (change the test together with the behaviour):

- The server turns `"` into `'` and line breaks into spaces in every text answer, including structured
  `webapp` results.
- A boolean `false` is stored empty, like an unanswered item (the PWA sends one boolean per `list_multiple`
  choice).
- A resend of the same dataset is stored as a second row (the server gives it a new `entryId`).

## Lines that held two records (history)

The old CSV files could hold a line with **two records run together** (a record appended without the newline before it). The database keeps
only the columns of the header, so it could not hold the extra cells; `sqlite_reconcile` listed these as `wide_rows`. The four live lines were split
exactly on 2026-11-05 (a one-off tool, since removed together with the CSV writing: the files it repaired are archive only). The 13 copies in
dated backup files are not repaired and are served from their own files. Nothing writes such lines any more.

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

## Snapshots, backups and privacy

Server snapshots contain a **transactionally consistent copy** of the database (made with `VACUUM INTO`), not
the live file, because a plain copy of a database in WAL mode can be torn. The database file lives in
`esmira_data/`, so a backup of that folder includes it, but copy the database with the snapshot recipe above
(or stop the container) rather than as a bare file while the server runs; `iemabot.sqlite-wal` and `-shm`
appear next to it then.

The database holds the same personal data as the CSV files, **unencrypted at rest**, including a copy of media
when `sqlite_store_media_content` is on. See [Data and privacy](./data-and-privacy.md).
