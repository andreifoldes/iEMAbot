#!/usr/bin/env bash
# Installs iEMAbot on this machine: checks it, asks for the few things only you know, starts the server.
#
#   curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash
#
# Safe to run again: settings, data, accounts and the version are kept. Updating is what update.sh does.
# Run with --help for the options, for example for an unattended install.
set -Eeuo pipefail

REF="${IEMABOT_REF:-main}"

usage() {
  cat <<'EOF'
Usage: install.sh [options]

Run again on an existing install, it repairs the setup and keeps your settings, data, accounts and version.
To update, use ./update.sh, which backs up first.

  --domain NAME         The server's public name, for example ema.example.org
  --email ADDRESS       Contact address for HTTPS certificates and browser push services
  --admin NAME          Name of the first admin account (default: admin)
  --dir PATH            Where to install (default: ~/iemabot)
  --behind-proxy        Another web server handles HTTPS: serve plain HTTP on 127.0.0.1:8081 for it
  --restore FILE        Start from a backup made by backup.sh (moving from another server) instead of a new admin
  --skip-dns-check      Do not check that the domain points at this machine
  --no-backup-schedule  Do not schedule the nightly backup
  --yes                 Never ask: use the values above and the defaults
  --help                Show this

Environment: IEMABOT_ADMIN_PASSWORD (default: a random one, shown once), IEMABOT_IMAGE, IEMABOT_VERSION,
IEMABOT_PULL=never (use an image already on this machine), HTTP_BIND, HTTPS_BIND.
EOF
}

# --- Run through a pipe: fetch the kit, then continue from a real file. ---
# Through a pipe there is no script file, so nothing next to it either: not even when run from a folder holding a kit.
KIT_SOURCE=""
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then KIT_SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; fi
if [ -z "$KIT_SOURCE" ] || [ ! -f "$KIT_SOURCE/compose.yml" ] || [ ! -f "$KIT_SOURCE/lib.sh" ]; then
  command -v curl >/dev/null 2>&1 || { echo "ERROR: this needs curl." >&2; exit 1; }
  KIT_TMP="$(mktemp -d)"
  LIB_URL="${IEMABOT_KIT_URL:-https://raw.githubusercontent.com/${IEMABOT_REPO:-andreifoldes/iEMAbot}/$REF/deploy/selfhost}/lib.sh"
  curl -fsSL --retry 2 -o "$KIT_TMP/lib.sh" "$LIB_URL" || { echo "ERROR: could not download the kit from $LIB_URL" >&2; exit 1; }
  # shellcheck source=lib.sh
  . "$KIT_TMP/lib.sh"
  fetch_kit "$KIT_TMP" "$REF" || die "Could not download the kit."
  IEMABOT_KIT_TMP="$KIT_TMP" exec bash "$KIT_TMP/install.sh" "$@"
fi
# shellcheck source=lib.sh
. "$KIT_SOURCE/lib.sh"
[ -z "${IEMABOT_KIT_TMP:-}" ] || trap 'rm -rf "$IEMABOT_KIT_TMP"' EXIT

DOMAIN=""; EMAIL=""; ADMIN=""; DIR="$HOME/iemabot"; BEHIND_PROXY=""; RESTORE=""; SKIP_DNS=""; SCHEDULE_BACKUP=1; YES=""

ask() { # ask "Question" default: the answer, or the default when nobody can answer
  local answer=""
  if [ -n "$YES" ] || ! has_tty; then printf '%s' "$2"; return; fi
  printf '%s%s: ' "$1" "${2:+ [$2]}" > /dev/tty
  read -r answer < /dev/tty || true
  printf '%s' "${answer:-$2}"
}

confirm() { # confirm "Question": yes when told not to ask, no when nobody can answer
  [ -n "$YES" ] && return 0
  has_tty || return 1
  case "$(ask "$1 (y/n)" n)" in y|Y|yes|Yes) return 0 ;; *) return 1 ;; esac
}

parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      --domain) DOMAIN="${2:?--domain needs a value}"; shift 2 ;;
      --email) EMAIL="${2:?--email needs a value}"; shift 2 ;;
      --admin) ADMIN="${2:?--admin needs a value}"; shift 2 ;;
      --dir) DIR="${2:?--dir needs a value}"; shift 2 ;;
      --restore) RESTORE="${2:?--restore needs a file}"; shift 2 ;;
      --behind-proxy) BEHIND_PROXY=1; shift ;;
      --skip-dns-check) SKIP_DNS=1; shift ;;
      --no-backup-schedule) SCHEDULE_BACKUP=""; shift ;;
      --yes) YES=1; shift ;;
      --help|-h) usage; exit 0 ;;
      *) usage >&2; die "Unknown option: $1" ;;
    esac
  done
  if [ -n "$RESTORE" ]; then
    [ -f "$RESTORE" ] || die "No backup file at $RESTORE"
    RESTORE="$(cd "$(dirname "$RESTORE")" && pwd)/$(basename "$RESTORE")"
  fi
}

install_docker() {
  [ "$(uname -s)" = "Linux" ] || die "Install Docker first: https://docs.docker.com/get-docker/"
  confirm "Docker is not installed. Install it now with Docker's official script?" \
    || die "iEMAbot runs in Docker. Install it (https://docs.docker.com/engine/install/) and run this again."
  if [ "$(id -u)" = "0" ]; then
    curl -fsSL https://get.docker.com | sh
  elif command -v sudo >/dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$(id -un)"
    say ""
    say "Docker is installed. Log out and back in so your account may use it, then run this again."
    exit 0
  else
    die "Installing Docker needs root. Run this again as root, or install Docker first."
  fi
}

install_cron() { # The nightly backup needs cron, which some cloud images leave out (Debian's on Azure).
  command -v crontab >/dev/null 2>&1 && return 0
  command -v apt-get >/dev/null 2>&1 || return 1
  confirm "The nightly backup needs cron, which is not installed. Install it now?" || return 1
  local install='apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq cron'
  if [ "$(id -u)" = "0" ]; then
    sh -c "$install"
  elif command -v sudo >/dev/null 2>&1; then
    sudo sh -c "$install"
  else
    return 1
  fi
}

check_machine() {
  step "Checking this machine"
  case "$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ;;
    *) die "The iEMAbot image is built for amd64 and arm64, not $(uname -m)." ;;
  esac
  command -v curl >/dev/null 2>&1 || die "This needs curl."
  [ "$(uname -s)" = "Linux" ] || warn "This is for Linux servers. Docker Desktop's file sharing is not reliable enough for the database, which can get damaged: for trying things out only, never for real data."
  command -v docker >/dev/null 2>&1 || install_docker
  docker info >/dev/null 2>&1 \
    || die "Docker is installed but this user cannot use it. Is it running? Use root, or add yourself: sudo usermod -aG docker $(id -un)"
  docker compose version >/dev/null 2>&1 \
    || die "The Docker Compose plugin is missing: https://docs.docker.com/compose/install/linux/"
  local compose_version
  compose_version="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
  [ "$(printf '2.20.0\n%s\n' "$compose_version" | sort -V | head -n 1)" = "2.20.0" ] \
    || die "Docker Compose $compose_version is too old: 2.20 or newer is needed. https://docs.docker.com/compose/install/linux/"

  local parent free_gb mem_mb
  parent="$(dirname "$DIR")"
  [ -d "$parent" ] || die "$parent does not exist."
  free_gb="$(df -Pk "$parent" | awk 'NR==2 {print int($4 / 1048576)}')"
  [ "$free_gb" -ge 3 ] || die "Only ${free_gb} GB free on the disk holding $parent; the images and first data need at least 3 GB."
  [ "$free_gb" -ge 10 ] || warn "Only ${free_gb} GB free. 20 GB or more is sensible once participants upload audio or media."
  if [ -r /proc/meminfo ]; then
    mem_mb="$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)"
    [ "$mem_mb" -ge 900 ] || warn "Only ${mem_mb} MB of memory. 1 GB or more is recommended."
  fi
  say "OK: $(uname -m), $(docker compose version --short 2>/dev/null || echo compose), ${free_gb} GB free"
}

port_in_use() { # port_in_use PORT
  if command -v ss >/dev/null 2>&1; then
    ss -ltnH 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"
  elif command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
  else
    return 1
  fi
}

ask_settings() {
  step "Settings"
  [ -n "$DOMAIN" ] || DOMAIN="$(ask "Public name of this server (for example ema.example.org)" "")"
  [ -n "$EMAIL" ] || EMAIL="$(ask "Contact email (HTTPS certificate notices, browser push services)" "")"
  [ -n "$DOMAIN" ] || die "The server needs a public name: pass --domain."
  [ -n "$EMAIL" ] || die "The server needs a contact email: pass --email."

  printf '%s' "$DOMAIN" | grep -qE '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$' \
    || die "$DOMAIN is not a server name. Give the bare name, without https:// or a path."
  printf '%s' "$DOMAIN" | grep -qE '^[0-9.]+$' \
    && die "HTTPS certificates need a name, not an IP address. Point a domain at this machine first."
  printf '%s' "$EMAIL" | grep -qE '^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$' || die "$EMAIL is not an email address."

  if [ -z "$RESTORE" ]; then
    [ -n "$ADMIN" ] || ADMIN="$(ask "Name of the first admin account" admin)"
    printf '%s' "$ADMIN" | grep -qE '^[A-Za-z0-9._-]{3,}$' || die "Use at least 3 letters, digits, dots, dashes or underscores for the account name."
  fi

  if [ -z "$BEHIND_PROXY" ] && [ -z "${HTTP_BIND:-}" ] && { port_in_use 80 || port_in_use 443; }; then
    say "Something on this machine already uses port 80 or 443."
    if [ -z "$YES" ] && confirm "Is it a web server that should forward to iEMAbot (it keeps handling HTTPS)?"; then
      BEHIND_PROXY=1
    else
      die "Free ports 80 and 443 and run this again, or use --behind-proxy to run behind that web server."
    fi
  fi
}

check_dns() {
  [ -n "$SKIP_DNS" ] || [ -n "$BEHIND_PROXY" ] || [ "$DOMAIN" = "localhost" ] || [ -n "${HTTP_BIND:-}" ] && return 0
  command -v getent >/dev/null 2>&1 || return 0
  step "Checking that $DOMAIN points at this machine"
  local resolved public
  # Not resolving at all is what this check is for. IPv4 only: that is what the address below is compared with.
  resolved="$( { getent ahostsv4 "$DOMAIN" || true; } | awk '{print $1}' | sort -u | tr '\n' ' ')"
  public="$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"
  if [ -z "$resolved" ]; then
    say "$DOMAIN does not resolve yet. Add a DNS A record for it pointing at ${public:-this machine}."
    confirm "Continue anyway?" || die "Add the record, wait a few minutes, and run this again."
    return 0
  fi
  case " $resolved" in
    *" $public "*) say "OK: $DOMAIN -> $public" ;;
    *)
      say "$DOMAIN points at $resolved, but this machine appears as ${public:-unknown}."
      say "Certificate requests fail until it points here, and repeated failures can get the domain rate limited."
      confirm "Continue anyway?" || die "Fix the DNS record and run this again."
      ;;
  esac
}

refuse_second_install() { # The project name is fixed, so a second folder would quietly take over the running server.
  local existing
  existing="$(docker ps -a --filter label=com.docker.compose.project=iemabot --format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u | head -n 1)"
  if [ -n "$existing" ] && [ ! "$existing" -ef "$DIR" ]; then
    die "iEMAbot already runs from $existing. To work on that server, use --dir $existing. Nothing was changed."
  fi
}

place_kit() {
  step "Installing into $DIR"
  mkdir -p "$DIR/caddy" "$DIR/data/esmira_data" "$DIR/data/config" "$DIR/webapp" "$DIR/backups"
  # The keys in data/config and the participant data in data/esmira_data are no business of other users on this machine.
  chmod 700 "$DIR/backups" "$DIR/data"
  if [ ! "$KIT_SOURCE" -ef "$DIR" ]; then
    local file
    for file in "${KIT_FILES[@]}"; do
      cp "$KIT_SOURCE/$file" "$DIR/$file"
    done
    chmod +x "$DIR"/*.sh
  fi
}

write_env() {
  cd "$DIR"
  if [ -f .env ]; then
    say "Keeping the existing .env."
    return 0
  fi
  local url caddyfile http_bind https_bind
  url="https://$DOMAIN"; caddyfile=Caddyfile; http_bind="${HTTP_BIND:-80}"; https_bind="${HTTPS_BIND:-443}"
  if [ -n "$BEHIND_PROXY" ]; then
    caddyfile=Caddyfile.behind-proxy; http_bind="${HTTP_BIND:-127.0.0.1:8081}"; https_bind="${HTTPS_BIND:-127.0.0.1:8443}"
  fi
  # A port other than 443 is part of the address, unless another web server is what participants reach.
  if [ -z "$BEHIND_PROXY" ] && [ "${https_bind##*:}" != "443" ]; then url="$url:${https_bind##*:}"; fi
  ( umask 077; cat > .env <<EOF
DOMAIN=$DOMAIN
ACME_EMAIL=$EMAIL
ADMIN_ACCOUNT=$ADMIN
PUBLIC_URL=$url
IEMABOT_IMAGE=${IEMABOT_IMAGE:-ghcr.io/andreifoldes/iemabot}
IEMABOT_VERSION=${IEMABOT_VERSION:-latest}
CADDYFILE=$caddyfile
HTTP_BIND=$http_bind
HTTPS_BIND=$https_bind
BACKUP_KEEP=7
EOF
  )
}

start_server() {
  unset IEMABOT_IMAGE IEMABOT_VERSION # They are in .env now, which decides from here on.
  # A server already in use is only updated by update.sh, which backs up first. So: fetch the image only if it is missing.
  if ! docker image inspect "$(image_ref)" >/dev/null 2>&1 || [ -z "$EXISTING" ]; then
    step "Downloading the server (the first time takes a few minutes)"
    fetch_image || die "Could not download $(image_ref). Check the connection and run this again."
  fi
  docker compose pull --quiet caddy 2>/dev/null || [ "${IEMABOT_PULL:-}" = "never" ] || warn "Could not download the web server image; trying with what is here."
  sync_kit "$(env_get IEMABOT_VERSION latest)"

  step "Starting the server"
  start_app
  if [ -n "$RESTORE" ]; then
    ./restore.sh --yes "$RESTORE"
  else
    create_admin
  fi
  # Only now does the server face the internet: until an admin exists, its first-run page lets anyone claim it.
  docker compose up -d --wait --wait-timeout "$START_TIMEOUT" caddy
}

ADMIN_PASSWORD_SHOWN=""
create_admin() {
  local password status=0
  ADMIN="$(env_get ADMIN_ACCOUNT)"
  ADMIN="${ADMIN:-admin}"
  password="${IEMABOT_ADMIN_PASSWORD:-$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20)}"
  printf '%s\n' "$password" | app_php cli/selfhost.php init "$ADMIN" || status=$?
  case "$status" in
    0)
      if [ -z "${IEMABOT_ADMIN_PASSWORD:-}" ]; then
        ADMIN_PASSWORD_SHOWN="$password"
        # Now, in case anything below fails.
        say "Admin account: $ADMIN  Password: $password  (save it; it is not stored anywhere)"
      fi
      ;;
    3) ADMIN="" ;;
    *) die "Could not create the admin account (see above)." ;;
  esac
}

summary() {
  local url
  url="$(env_get PUBLIC_URL)"
  step "Done"
  say "Participant app:       $url/pwa/"
  say "Researcher dashboard:  $url/esmira/#admin"
  if [ -n "$ADMIN_PASSWORD_SHOWN" ]; then
    say ""
    say "Admin account:  $ADMIN"
    say "Password:       $ADMIN_PASSWORD_SHOWN"
    say "This is the only time it is shown. Log in and change it under your account settings."
  fi
  if [ "$(env_get CADDYFILE)" = "Caddyfile.behind-proxy" ]; then
    say ""
    say "Point your web server at http://$(env_get HTTP_BIND) (forward every path, keep the Host header, send"
    say "X-Forwarded-Proto: https). The self-hosting guide has nginx and Apache examples."
  fi
  say ""
  say "Next: create a study in the dashboard. Day to day, from $DIR:"
  say "  ./doctor.sh    check the server"
  say "  ./update.sh    update to the newest release (takes a backup first)"
  say "  ./backup.sh    back up now"
  say "  ./reset-password.sh ACCOUNT    if a password gets lost"
  say "Backups stay on this machine; copy the backups folder elsewhere too. Guide: https://andreifoldes.github.io/iEMAbot/deployment/self-hosting"
}

main() {
  trap 'say ""; say "The installation did not finish. Fix the problem above and run this again: it picks up where it stopped. A lost admin password: ./reset-password.sh"' ERR
  parse_args "$@"
  check_machine
  mkdir -p "$DIR" || die "Could not create $DIR."
  DIR="$(cd "$DIR" && pwd)"
  refuse_second_install
  EXISTING=""
  if [ -f "$DIR/.env" ]; then
    EXISTING=1
    [ -z "$DOMAIN$EMAIL$BEHIND_PROXY" ] || warn "Keeping the settings in $DIR/.env; --domain, --email and --behind-proxy only apply to a new install. Edit .env to change them."
  else
    ask_settings
    check_dns
  fi
  place_kit
  write_env
  start_server
  if [ -n "$SCHEDULE_BACKUP" ]; then
    install_cron || true
    ./backup.sh --schedule || warn "No nightly backup is scheduled. Install cron, then run ./backup.sh --schedule, or back up another way."
  fi
  step "Checking the result"
  ./doctor.sh --wait || warn "Some checks did not pass; see above. ./doctor.sh repeats them."
  summary
}

main "$@"
