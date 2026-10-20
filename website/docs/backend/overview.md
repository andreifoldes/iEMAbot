---
title: "Backend overview"
sidebar_label: "Overview"
description: "The basic features of the ESMira server that this fork builds on, and which parts the fork changed."
---

# Backend overview

The ESMira server is a PHP application with a TypeScript/Mithril admin UI. It receives study data, serves
study definitions, and gives researchers a designer and data export. This page covers the **inherited
basics**; fork-specific additions are flagged and have their own pages.

## Components

| Component | Location | Notes |
| --- | --- | --- |
| Public and admin API | `src/api/*.php` | One PHP file per endpoint; admin calls go through `admin.php?type=…`. See [API reference](./api-reference.md). |
| Backend library | `src/backend/` | Study store, response index, permissions, CSV creation. |
| Researcher designer | `src/frontend/ts/` | Mithril + TypeScript, built with webpack. |
| CLI scripts | `src/cli/` | Fork-added: VAPID key generation, push sender, wearable setup and sync, SQLite backfill. |
| Locales | `src/locales/` | 27 language files; Weblate-managed upstream. |
| Participant PWA | `web-pwa/` | **Fork-only.** See [PWA overview](../pwa/overview.md). |

## Storage: files plus one SQLite database

There is no database server. State is stored as files below the data folder (`esmira_data/`), and in this
fork everything collected from participants is **also** written to a single embedded SQLite database,
`esmira_data/iemabot.sqlite` (see [SQLite store](./sqlite-store.md)):

```text
esmira_data/
├── studies/<studyId>/
│   ├── .config.json            # the study definition
│   ├── responses/              # one CSV per questionnaire, plus events.csv, web_access.csv
│   ├── .responses_index/       # PHP-serialised column index
│   ├── media/                  # uploaded images, audio, (fork) keystrokes/
│   ├── .messages/  .userdata/  .statistics/  .reward_codes/  .langs/  .merlin_logs/
│   ├── .push_subscriptions/    # fork: Web Push subscriptions
│   ├── .push_events            # fork: delivery funnel log (JSONL)
│   ├── .client_info/           # fork: installed-vs-browser, device class
│   ├── .wearables_tokens/      # fork: encrypted OAuth tokens
│   └── .wearables_data/        # fork: synced measurements (CSV)
├── iemabot.sqlite              # fork: SQLite copy of all collected participant data
├── .logins  .permissions  .loginToken/
├── errors/  legal/  snapshots/  plugins/  fallbackStudies/
```

The CSV delimiter defaults to `;` (`backend/defaults/configs.default.php`).

:::caution[Never hand-edit `.config.json`]
Studies must be saved through the backend's study store (`saveStudy`), because saving also migrates the
response index and CSV headers. Editing the JSON on disk can desynchronise them. The repository's
`tools/live-study/*.php` scripts follow this rule: they are dry-run by default and write via `saveStudy()`
when passed `apply`.
:::

## Accounts and permissions

Login uses password hashes, rate-limited blocking, login history and cookie tokens.

| Permission | Scope |
| --- | --- |
| `admin` | Everything: accounts, server settings, snapshots, updates. |
| `create` | Create new studies. |
| `issueFallbackTokens` | Fallback-server tokens. |
| Per study: `publish`, `write` ("alter study"), `read` (data), `msg` (messages), `reward` | Granted per account per study. |

The fork's Push endpoints require **write** permission; the wearables download requires **read**.

## Data export

From the designer's data list a researcher can download:

- one **CSV per questionnaire**, plus `events.csv` and `web_access.csv`;
- an in-browser table viewer;
- a **media zip** (images, audio and, in this fork, keystroke logs);
- a **`wearables.zip`** of synced wearable CSVs (<span className="status status--shipped">Shipped</span>, fork-added);
- study backups, and a destructive "empty data" / **Reset study** action.

Server snapshots (zip of all server data) are admin-only.

## Server settings

Upload caps (100 MB default), login-block seconds, messages per user, statistics limits, server name,
languages, legal texts, and — fork-added — [`webOnlyMode`](./study-model.md#web-only-mode).

## What is inherited vs. changed

Inherited and unchanged: accounts, designer, statistics/charts, rewards, messaging, plugins, the fallback
system, `datasets.php`. Changed by the fork: see [Fork divergence](../fork-divergence.md).
