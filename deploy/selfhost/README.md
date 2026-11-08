# iEMAbot self-host kit

Run your own iEMAbot server. You need a Linux VPS (2 CPU cores, with 1 GB of memory and 16 GB of disk at the minimum, or 4 GB and 32 GB recommended, with 64 GB if participants record audio), a domain name pointing at it, and
ports 80 and 443 open. Then:

```bash
curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash
```

Or, to read it first, clone this repository and run `deploy/selfhost/install.sh`. Either way it asks for the domain, an
email address and an admin name, and installs into `~/iemabot`.

| In `~/iemabot` | What it does |
| --- | --- |
| `./doctor.sh` | Checks the server and says what to do about anything wrong. |
| `./update.sh` | Updates to the newest release, with a backup first. |
| `./backup.sh` | Backs up now. `--schedule` makes it nightly (the installer does this). |
| `./restore.sh FILE` | Restores a backup, also on a new server: `install.sh --restore FILE`. |
| `./reset-password.sh ACCOUNT` | Sets a new password for an account, when one gets lost. |

The full guide, with running behind an existing web server, optional features and moving servers, is at
<https://andreifoldes.github.io/iEMAbot/deployment/self-hosting>.
