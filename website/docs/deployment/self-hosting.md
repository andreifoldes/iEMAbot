---
title: "Self-host for your lab"
sidebar_label: "Self-host for your lab"
description: "Run your own iEMAbot server: one command on a Linux VPS, then updates, backups, running behind an existing web server, and moving servers."
---

# Self-host for your lab

<span className="status status--shipped">Shipped</span> · the participant web app and the researcher dashboard. The
Android app is [separate](#android-app).

Your lab can run its own iEMAbot server, so study data stays on a machine you control. One command installs it.
You do not need to know PHP, Docker or web servers.

If your institution's IT department will host it for you, start with [Getting started: for researchers](../getting-started/for-researchers.md),
which has an email you can send them. [Their guide](../getting-started/for-it.md) has network requirements and security notes.

## What you need

- **A Linux server** with a public address, not Docker Desktop on a laptop, whose file sharing can damage the database: for a typical study of 20 to 200 participants, 2 CPU cores, 4 GB of memory and 32 GB of
  disk are recommended, with 64 GB if they record audio. The minimum is 2 CPU cores, 1 GB and 16 GB
  ([why, and what they cost](../getting-started/for-it.md#requirements)). Ubuntu or Debian on any provider, or your institution's cloud. amd64 and arm64 both work.
- **A domain name** with a DNS record pointing at it, for example `ema.your-lab.org`. Add the record first and allow a
  few minutes: browsers only allow notifications and installing the app over HTTPS, and HTTPS certificates need the name.
  You need not buy one to try things out: many cloud providers give a server a free name, such as Azure's
  `something.westeurope.cloudapp.azure.com` (Public IP address → Configuration → DNS name label). For a real study, use a
  name you will keep: participants' installed apps belong to it.
- **Ports 80 and 443 open**, in the server's firewall and in your provider's security group.
- **Login to the server** as a user with `sudo`, one that may use Docker, or root. If Docker is missing, the installer offers to
  install it. Unless you are root, it then asks you to log out and back in and run the same command again.

Already running a web server on that machine? See [Behind an existing web server](#behind-an-existing-web-server).

## Install

Log in to your server and run:

```bash
curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash
```

Prefer to read it first? `git clone https://github.com/andreifoldes/iEMAbot`, read `deploy/selfhost/`, and run
`deploy/selfhost/install.sh`.

It asks for three things: the domain name, a contact email (for certificate notices and browser push services), and
a name for the admin account. It then checks the machine, starts the server, gets an HTTPS certificate, schedules a
nightly backup, and tests the result. At the end it prints:

```text
Participant app:       https://ema.your-lab.org/pwa/
Researcher dashboard:  https://ema.your-lab.org/esmira/#admin

Admin account:  admin
Password:       (shown only this once)
```

Everything lives in `~/iemabot`. Running the installer again is safe: it keeps your settings, data, accounts and version.

:::tip[Unattended]
`install.sh --help` lists the options. For example:
`install.sh --yes --domain ema.your-lab.org --email you@example.org --admin alex`
:::

## Your first study

1. Open the researcher dashboard and log in. **Change the password** right away, under your account.
2. In **Server settings**, give the server a name, and add a privacy policy and legal notice if your institution
   requires them.
3. Create a study and add a questionnaire. It is open at any time until you plan something else under **Schedule**.
4. Under **Publication, Links & QR code**, choose **Study is published** and add an access key, then save.
5. Send participants the **Link for participants** shown there, or its QR code. It opens the study on their phone,
   where they can sign up straight away.

You are responsible for the data your server collects. Check what your ethics approval and data-protection rules
require; [Data and privacy](../backend/data-and-privacy.md) describes what is stored and which third parties are involved.

## Day to day

From `~/iemabot`:

| Command | What it does |
| --- | --- |
| `./doctor.sh` | Checks the server and says what to do about anything wrong. Start here when something seems off. |
| `./update.sh` | Updates to the newest release: downloads it, backs up, restarts, checks the result. |
| `./update.sh 3.7.31` | Moves to a specific version and stays there, for example for the length of a study. It refuses a version older than your data. `./update.sh latest` follows releases again. |
| `./backup.sh` | Backs up now. The installer also schedules one every night at 03:17. |
| `./restore.sh FILE` | Returns to a backup, and to the version it came from, after first backing up the current state. A failed restore leaves the server as it was. |
| `./reset-password.sh ACCOUNT` | Sets a new password for an account. There is no email reset, so this is how to get back in. |

**Updates.** The dashboard tells admins when a new release is out. It cannot update the server by itself: run
`./update.sh` on the server. To hear about releases by other means, use *Watch → Custom → Releases* on the
[GitHub repository](https://github.com/andreifoldes/iEMAbot).

**Backups** stop the server for the moment they take; participants' apps upload what they hold afterwards. The newest
seven are kept in `~/iemabot/backups` (change `BACKUP_KEEP` in `.env`). They hold participant data and the server's
keys in the clear, plus your `webapp` folder and `compose.override.yml`. The `safety-` files that `restore.sh` leaves are
yours to delete.

:::warning[Keep a copy elsewhere]
A backup on the same machine does not survive losing that machine. Copy the `backups` folder somewhere safe and
encrypted, for example with `rsync` to your institution's storage.
:::

## Behind an existing web server

If another web server already handles HTTPS on the machine (nginx, Apache, an institutional load balancer), install with:

```bash
curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash -s -- --behind-proxy
```

iEMAbot then serves plain HTTP on `127.0.0.1:8081`, reachable only from that machine. Have your web server forward
**every path** to it, keeping the `Host` header and sending `X-Forwarded-Proto: https`. Allow large uploads and do not
buffer responses: the dashboard streams progress.

```nginx title="nginx"
server {
    listen 443 ssl;
    server_name ema.your-lab.org;
    # ssl_certificate and ssl_certificate_key as for your other sites

    client_max_body_size 300m;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_buffering off;
        proxy_read_timeout 300s;
    }

    add_header Strict-Transport-Security "max-age=31536000" always;
}
```

```apache title="Apache (with mod_proxy, mod_proxy_http, mod_headers, mod_ssl)"
<VirtualHost *:443>
    ServerName ema.your-lab.org
    SSLEngine on
    # SSLCertificateFile and SSLCertificateKeyFile as for your other sites

    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    Header always set Strict-Transport-Security "max-age=31536000"
    ProxyPass / http://127.0.0.1:8081/ flushpackets=on
    ProxyPassReverse / http://127.0.0.1:8081/
</VirtualHost>
```

If your web server runs on another machine, change `HTTP_BIND` in `.env` to an address on a private network between the
two, then run `docker compose up -d`. Traffic to that port is unencrypted, logins included, and Docker opens it past `ufw`
(see below), so it must not face the internet. Only private addresses are trusted to say the connection is HTTPS.

## Moving to a new server

Your studies, participants, accounts and keys all move with a backup:

1. On the old server: `./backup.sh`, then copy the file it names to the new server. Stop the old server:
   `docker compose stop`. Responses sent after this point wait in participants' apps.
2. Point the domain's DNS record at the new server.
3. On the new server:
   ```bash
   curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh -o install.sh
   bash install.sh --restore /path/to/iemabot-....tar.gz
   ```

**Keep the same domain if you possibly can.** Participants' installed apps and notification subscriptions belong to
it, and wearable providers have it on file. With a new domain, participants must join again, and you must register the
new wearable callback address with each provider.

## Optional features

Run these from `~/iemabot`. Each works without touching any address: they follow from the domain you installed with.

**Push notifications** need no setup.

**Wearables** (Withings, Google Health): see [setting up provider apps](../backend/wearables.md#provider-setup). Your server's
callback address is `https://ema.your-lab.org/esmira/api/wearables_oauth.php`.
```bash
docker compose exec -u www-data app php cli/wearables_setup.php genkey
docker compose exec -u www-data app php cli/wearables_setup.php withings <client_id> <client_secret>
```

**Telegram**: participants can also answer in a Telegram chat. It stays off until you set up a bot: researchers see no
Telegram options and the server does no Telegram work. See [chat channels](../backend/channels/chat-channels.md), or
**Server settings → Messaging channels**. The token is read from the keyboard:
```bash
docker compose exec -u www-data app php cli/telegram_setup.php set-token
```

**Error reports as GitHub issues**: see [Docker and cron](./docker-and-cron.md#error-reports-as-github-issues).

**Cognitive tasks** are static web pages, embedded through a question's URL (see
[cognitive tasks](../backend/question-types/cognitive-tasks.md)). Use a copy someone else hosts, or put your own in the
`webapp` folder: `webapp/my-task/index.html` is served at `https://ema.your-lab.org/webapp/my-task/index.html`.

**Your own changes** to the setup go in a `compose.override.yml` next to `compose.yml`, which `update.sh` replaces.

### Android app {/* #android-app */}

The web app is enough for most studies, and participants install it from the browser with no app store. The Android app
adds phone sensing, but a build points at one server and must be signed with your own key, so it is not part of this
kit. To build one, see [the Android app](../backend/channels/android-app.md):
`ESMIRA_DEFAULT_SERVER=https://ema.your-lab.org android-app/scripts/build.sh`.

## When something is wrong

Run `./doctor.sh` first: each failed check says what to do.

| What you see | What it usually means |
| --- | --- |
| The browser warns about the certificate, or the site does not load | DNS does not point here yet, or ports 80 and 443 are closed in your provider's security group. `docker compose logs caddy` shows the certificate attempts. After fixing it, Caddy retries by itself. |
| `docker compose logs caddy` mentions rate limits | Too many failed certificate attempts. Let's Encrypt lets you try again after an hour or so. |
| "port is already allocated" during install | Something else uses port 80 or 443. Stop it, or use `--behind-proxy`. |
| `permission denied` on the Docker socket | Your account may not use Docker: `sudo usermod -aG docker $USER`, then log out and back in. |
| The server stopped accepting data | The disk is probably full: check with `df -h`. `docker system prune` frees old images. |
| An update went wrong | First see `docker compose logs app`: a lot of data can take minutes to start. To go back, `update.sh` printed the command: `./restore.sh backups/...`. This also returns to the version the backup came from, but loses what was collected since. |
| A password got lost | `./reset-password.sh ACCOUNT` |
| Notifications do not arrive | `./doctor.sh` shows whether the sender runs. Each study's **Push** panel shows what was sent and received. |

:::note[Firewalls]
Docker opens its published ports by itself, past `ufw`. Your provider's security group still applies. Behind an existing
web server on the same machine this does not matter, because the port only listens on `127.0.0.1`.
:::

## Removing it

```bash
cd ~/iemabot && docker compose down -v
```

This stops the server and removes the HTTPS certificates. Your data stays in `~/iemabot/data` and `~/iemabot/backups`;
its files belong to the container's user, so delete them with `sudo rm -rf ~/iemabot`. Also remove the backup line from `crontab -e`.
