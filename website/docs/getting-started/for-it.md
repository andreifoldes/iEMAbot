---
title: "Getting started: for IT"
sidebar_label: "For IT teams"
description: "How to host iEMAbot for a research team: requirements, network access, installation, security, backups and handover."
---

# Getting started: for IT

A research team has asked you to host iEMAbot, an open-source ecological momentary assessment platform: participants answer
short questionnaires on their own phones, and researchers run studies from a web dashboard. This page covers what you need
to decide and do.

**In short:** one small Linux VM, one DNS name and ports 80 and 443 inbound. Two containers, with no external database and no
inbound connections except HTTPS. Installing takes one command, run twice if Docker has to be installed first. Expect about an
hour in total, including DNS and handover.

## What runs

| Container | Image | Role |
| --- | --- | --- |
| `app` | `ghcr.io/andreifoldes/iemabot` | PHP 8.5 and Apache, with cron jobs for reminders. Data is stored in SQLite and files. |
| `caddy` | `caddy` (pinned by digest) | Terminates HTTPS, routes requests, and is the only container with published ports. |

Data lives in bind mounts under the install folder (default `~/iemabot/data`), so it is easy to find and back up. Compose
files, scripts and settings are in the same folder.

## Requirements

For a typical study of 20 to 200 participants, mostly through the web app:

| | Minimum | Recommended |
| --- | --- | --- |
| **vCPU** | 2 (burstable is fine) | 2 (burstable is fine) |
| **Memory** | 1 GB | 4 GB |
| **Disk** | 16 GB, questionnaires only | 32 GB, or 64 GB if the study collects voice recordings |
| **Azure equivalent** | `Standard_B2pts_v2` (arm64) | `Standard_B2s` (amd64) |
| **Azure UK list price, with disk** | About £7 a month | About £28 a month, or £30 with the larger disk |
| **OS** | Ubuntu 24.04 LTS or Debian 13 | Ubuntu 24.04 LTS or Debian 13 |
| **Architecture** | amd64 or arm64 | amd64 or arm64 |
| **Docker** | Engine with Compose 2.20 or newer; the installer can install it | The same |

**Tested:** the minimum's CPU and memory, end to end on real Ubuntu 24.04 and Debian 13 VMs with real certificates. The system used under 4 GB of its 30 GB
disk; 16 GB itself was not tested. In a **simulated** load test, a script acted as 200 participants each uploading a short check-in
at the same instant. All succeeded (median 0.26 s, slowest 3.5 s, peak memory about 500 MB).

**Not tested:** a real study at this size, real phones and mobile networks, loading the full app, sending reminders, sustained load,
media uploads under load, or more than 200 participants. For a real study we would choose the recommended size.

**Storage:** the OS, Docker and images take about 4 GB (measured). At about 1.3 KB per answer record, a study of 200 participants
over 30 days stays under 200 MB. Voice memos are about 1 MB a minute, as a planning figure. Local backups keep seven copies by
default, so allow about eight times the data.

**Availability:** it is a single host, with no clustering. Short outages are tolerated, because the participant app saves answers on
the device and sends them when the server returns. Reminders are not sent while the server is off, so turn off any scheduled VM
shutdown. After a reboot the containers start by themselves. A `backup.sh` run stops the app container briefly every night at 03:17, server time.

:::warning[Use a real disk]
Keep the data folder on a local or block-storage disk, not on NFS or SMB. SQLite's write-ahead log needs a local filesystem.
We have seen it corrupt the database on Docker Desktop's shared folders, which is why this is for Linux servers only.
:::

## Network

**Inbound**

| Port | From | Why |
| --- | --- | --- |
| 443/tcp (and 443/udp for HTTP/3) | Internet | Participants use their own phones on mobile data or wifi, so restricting this to a campus network or VPN breaks the study. |
| 80/tcp | Internet | Redirects to HTTPS, and answers certificate challenges. Not needed behind your own proxy. |
| 22/tcp | Your admin network | Administration. Nothing in the kit needs it. |

The researcher dashboard is on the same address as the participant app, at `/esmira/`. If your policy requires it, restrict
that path at your own web server, but leave `/esmira/api/` open, because participants' apps use it.

**Outbound from the server (HTTPS)**

| Destination | When | Why |
| --- | --- | --- |
| `fcm.googleapis.com`, `android.googleapis.com`, `*.push.services.mozilla.com`, `*.push.apple.com`, `*.notify.windows.com` | Always | Sending reminders through browser push services. The content is encrypted, but they see that a message was sent. |
| `ghcr.io`, `pkg-containers.githubusercontent.com` | Installing and updating | The application image. |
| `registry-1.docker.io`, `auth.docker.io`, `production.cloudflare.docker.com` | Installing and updating | The Caddy image. |
| `raw.githubusercontent.com` | Installing | The installer, unless you clone the repository yourself. |
| `get.docker.com`, `download.docker.com` | Installing Docker | Not needed if Docker is already installed. |
| `acme-v02.api.letsencrypt.org`, `acme.zerossl.com` | Certificates | Not needed behind your own proxy. |
| `wbsapi.withings.net`, `account.withings.com`, `health.googleapis.com`, `oauth2.googleapis.com` | Only if the study links wearables | Also receives webhooks from the provider. |
| `api.telegram.org` | Only if a Telegram bot is set up | Off by default. |
| `api.github.com` | Only if error reports are sent to GitHub | Off by default. |

Outbound traffic through an authenticating proxy has not been tested. The reminders run inside the container, so a proxy
would need to be configured for the container as well as for Docker.

**In the participant's browser**, the app also loads the Inter font from Google Fonts. Tasks embedded in a study, such as
cognitive games, load from wherever the study's author points them.

## Install

Before you start:

1. **Give the VM a static public IP**, and create the DNS record for the name the research team wants. Choose a name you will
   keep, because participants' installed apps belong to it. The installer checks the record, because repeated failed certificate
   requests can get the name rate limited.
2. **Open ports 80 and 443** in your provider's firewall or security group (on Azure, the network security group). The installer
   doesn't check this, and it can't get a certificate while they are closed.

Log in as a user with `sudo`, one that may use Docker, or root, then:

```bash
curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash
```

If Docker is missing, the installer offers to install it. Unless you are root, it then asks you to log out and back in and run
the same command again. If `cron` is missing, as on Debian's Azure image, it offers to install that too, because the nightly backup needs it.

To review it first, or to run it unattended:

```bash
git clone --depth 1 https://github.com/andreifoldes/iEMAbot
less iEMAbot/deploy/selfhost/install.sh
iEMAbot/deploy/selfhost/install.sh --yes --domain ema.your-department.ac.uk --email it-team@example.org --admin research-lead
```

The email receives certificate notices, and identifies the server to push services, so use a team mailbox. The admin
password is **printed once**; `./reset-password.sh ACCOUNT` sets a new one if it is lost. The installer finishes by running
`doctor.sh`, which should end with `All checks passed`.

A new server starts with a **demo study** (access key `demo`, at `/pwa/?key=demo`), so the research team has something to try
at once. Its access key is public, so it must be deleted in the dashboard before recruitment starts. `--no-demo` leaves it out.

### Behind your existing web server or load balancer

Add `--behind-proxy`. The stack then serves plain HTTP on `127.0.0.1:8081`, and your web server handles HTTPS. Forward every
path, keep the `Host` header and send `X-Forwarded-Proto: https`. There are
[nginx and Apache examples](../deployment/self-hosting.md#behind-an-existing-web-server) in the main guide. Allow uploads of up to
300 MB, and don't buffer responses, because the dashboard streams progress.

The proxy is also where to send HSTS in this mode; see [security headers](../deployment/security-headers.md).

## Security

**What to know before you approve it**

- **Data is not encrypted at rest by the application.** Responses, media and the SQLite database are plain files, and backups
  are unencrypted archives. Use an encrypted disk, and encrypt backups when you copy them off the server. Wearable tokens are the
  exception, and are encrypted by the application.
- **The server config holds secrets in plain text**: push keys and any provider credentials, in `data/config/configs.php`, which
  is readable by the container's web user only. The installer makes `data/` accessible to its owner alone.
- **Docker publishes ports past `ufw`.** Use your provider's firewall or security group, not only the host's.
- **Membership of the `docker` group is equivalent to root** on the host. Treat accounts accordingly.
- **Both containers start as root.** The app's entrypoint fixes file ownership, then Apache and cron run the application as
  `www-data`. Caddy uses its image's defaults.
- **Participants are pseudonymous** by default: a random ID, with no names or email addresses required. Free-text answers and voice
  recordings can of course contain anything.
- **The dashboard** uses its own accounts, with passwords of at least 12 characters and a lockout on failed attempts. There is
  no SSO or multi-factor login, so consider restricting `/esmira/` to your network if the researchers can use a VPN, while leaving
  `/esmira/api/` open.

More detail is in [Data and privacy](../backend/data-and-privacy.md), [security headers](../deployment/security-headers.md) and
[the security audit](../deployment/security-audit.md). A DPIA template is not available yet.

**Recommended on the host**

- Automatic OS security updates (`unattended-upgrades` on Ubuntu)
- SSH by key only, restricted to your admin network
- Disk encryption, from your platform if it offers it
- Your usual monitoring and log collection

## Backups

Nightly backups run at 03:17 and land in `~/iemabot/backups`, keeping the newest seven (`BACKUP_KEEP` in `.env`). Each archive
holds all study data **and the server's keys**.

:::warning[These backups are on the same machine]
They protect against mistakes and failed updates, not against losing the server. Copy `~/iemabot/backups` off the machine each
night, or use your platform's VM or disk snapshots, encrypted either way. Files are owned by the install user with mode 600.
:::

To restore, on this server or a new one:

```bash
./restore.sh backups/iemabot-....tar.gz           # This server. Backs up the current state first.
install.sh --restore /path/to/iemabot-....tar.gz  # A new server, with the same address.
```

A restore unpacks next to the live data and swaps it in only once that has worked, so a failure leaves the server as it was.
Please test a restore before the study starts; participants' answers usually can't be collected twice.

## Keeping it running

| Command (from `~/iemabot`) | What it does |
| --- | --- |
| `./doctor.sh` | Checks containers, HTTPS, private paths, database integrity, reminders, disk and backup age. Exits non-zero on failure, so it suits monitoring. |
| `./update.sh` | Downloads the newest release, backs up, restarts, checks. The server is down during the backup and restart, so longer with a lot of data. |
| `./update.sh 3.7.38` | Moves to a version and stays there, for example to keep a study on one version. Refuses versions older than the data. |
| `./restore.sh FILE` | Goes back to a backup, and to the version it came from. |
| `./reset-password.sh ACCOUNT` | Sets a new password. There is no email reset. |
| `docker compose logs app` | Application logs. Cron job logs are inside the container, in `/var/log/esmira_*.log`. |

**Updates:** the dashboard tells admins when a release is out, but cannot update itself, so someone on your side has to run
`./update.sh`. To get notified, use *Watch → Custom → Releases* on the
[GitHub repository](https://github.com/andreifoldes/iEMAbot). Agree with the researchers when to update, because it's best not done in
the middle of a day with a lot of check-ins. Answers are not lost during a restart, since the app keeps them until the
server is back.

A useful cron job for monitoring:

```bash
*/15 * * * * cd ~/iemabot && ./doctor.sh > /dev/null || your-alert-command
```

## Check it works

From your own computer, with Python and nothing else, run the journey test against the server. It creates a throwaway study,
sends answers to every question type and real media files as a participant would, downloads the data, checks it, and deletes
the study. It takes about a second:

```bash
git clone --depth 1 https://github.com/andreifoldes/iEMAbot && cd iEMAbot
python3 scripts/demo/lab-journey.py https://ema.your-department.ac.uk --user ACCOUNT --password 'PASSWORD'
```

It should end with `LAB JOURNEY OK`.

## Hand over

Give the research team:

- [ ] The dashboard address: `https://ema.your-department.ac.uk/esmira/#admin`
- [ ] The admin account and its password, by your usual secure route. They should change it on first login.
- [ ] Who to contact, and how they'll hear about planned downtime
- [ ] Where backups go, and how long they are kept
- [ ] **The demo study's link**, so they can see every question type on a phone and test a reminder:
  `https://ema.your-department.ac.uk/pwa/?key=demo`. Remind them to delete the demo study before real recruitment starts.

[Getting started: for researchers](./for-researchers.md) tells them what to do next.

## When the study ends

Agree on an end date at the start. When it comes:

1. The researchers download their data from the dashboard, and confirm they have it.
2. Take a final `./backup.sh`, and store or delete it according to your retention policy.
3. Remove the install:
   ```bash
   cd ~/iemabot && docker compose down -v
   sudo rm -rf ~/iemabot
   ```
4. Remove the backup line from the install user's crontab (`crontab -e`), and the DNS record.

Deleting the DNS record too soon means anyone still on the study can't reach the server; their apps keep unsent answers until they can.
