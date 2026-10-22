---
title: "Docker and cron"
sidebar_label: "Docker and cron"
description: "The container image, its PHP extensions, the cron jobs, volumes, and what a host needs to run the fork."
---

# Docker and cron

<span className="status status--shipped">Shipped</span> · cost and capacity figures are
<span className="status status--tbc">TBC</span> (see [Current status](../current-status.md))

The fork ships as a **single container** built from the repository's `Dockerfile`. There is no separate
database, queue or worker service: ESMira stores files plus one embedded SQLite database (see
[SQLite store](../backend/sqlite-store.md)), and the background jobs run as cron inside the same container.

## What the image contains

| Layer | Detail |
| --- | --- |
| Base | `php:8.3.36-apache` |
| PHP extensions | `zip`, `gmp`, `mbstring`, `curl`, `sodium`; `pdo_sqlite` (built in, asserted at build time) |
| Why | `gmp`, `mbstring`, `curl` for `minishlink/web-push` (VAPID signing, payload encryption, HTTP client); `sodium` encrypts stored wearable tokens; `pdo_sqlite` backs the collected-data database |
| App files | `COPY ./dist /var/www/html`, so the ESMira webpack build **and** the PWA (`dist/pwa/`) must exist before `docker build` |
| Composer | `composer update --no-dev` in `backend/` installs web-push into `backend/vendor` |
| Apache | `rewrite`, `md`, `ssl` modules enabled; production `php.ini` |

## Cron jobs

All use the **absolute** PHP path, because cron's minimal `PATH` excludes `/usr/local/bin` and a bare `php`
fails with "php: not found", silently, so the sender would never run.

| Schedule | Command | Log |
| --- | --- | --- |
| Every minute | `/usr/local/bin/php /var/www/html/cli/push_send_due.php` | `/var/log/esmira_push.log` |
| Hourly (`0 * * * *`) | `/usr/local/bin/php /var/www/html/cli/wearables_sync.php` | `/var/log/esmira_wearables.log` |
| Every minute | `/usr/local/bin/php /var/www/html/cli/channels_dispatch.php` (due questionnaires for [chat channels](../backend/chat-channels.md)) | `/var/log/esmira_channels.log` |
| Every minute | `/usr/local/bin/php /var/www/html/cli/telegram_poll.php` (long-polls Telegram for ~55 s; exits at once without a bot or with the webhook transport) | `/var/log/esmira_channels.log` |

`docker-entrypoint.sh` fixes volume ownership, runs the storage migrations (`MigrationManager::autoRun()`),
starts cron, then runs Apache in the foreground. The researcher panel's **sender heartbeat** warns if the
push job has not run for more than 180 s.

## Volumes

| Mount | Holds |
| --- | --- |
| `backend/config/` | Server config, including VAPID keys, wearable client secrets and the token key |
| `esmira_data/` | All studies, responses, media, tokens, and the collected-data database `iemabot.sqlite` |
| `/etc/apache2/sites-enabled/` | Apache vhosts |

Back up `esmira_data/` **and** `backend/config/`. Losing the token key makes stored wearable tokens
unreadable; rotating the VAPID keys invalidates every push subscription.

## First-time setup

```bash
# inside the container (as www-data)
php cli/generate_vapid.php                     # Web Push keys
php cli/wearables_setup.php genkey             # wearable token key
php cli/wearables_setup.php withings <id> <secret>   # repeat per provider
```

Then register the redirect URI shown in the study's **Wearables** panel with each provider.

After the first deploy that includes the SQLite store, import the data collected before it existed (safe to
repeat):

```bash
php cli/sqlite_backfill.php
```

## Host requirements

- Docker with the Compose plugin.
- **HTTPS in front of the container.** Service workers and Web Push need a secure origin, and the app does
  not enforce TLS itself (see [Data and privacy](../backend/data-and-privacy.md#transport-security)).
  Terminate TLS in Apache or in a reverse proxy; when proxying, set `wearables_redirect_uri` explicitly.
- Outbound HTTPS to browser push services and, if used, the wearable providers.
- Serve `/pwa/` (static) next to the ESMira API root.

:::note[The repo's `docker-compose.yml` is upstream's]
It references the upstream image and has no mount for `/pwa/`. The fork's production compose file is not in
the repository. To run **this** repository's image with a demo study, use `docker-compose.demo.yml` via
[Demo and verification](./demo-and-verification.md).
:::

## Local development

```bash
npm install && npm run prod          # ESMira build (cleans dist/)
cd web-pwa && npm install && npm run dev   # PWA dev server on :5174, proxies /esmira/api
```

Point the proxy at a local ESMira with `ESMIRA_PROXY=http://localhost:8081`.
