#!/usr/bin/env bash
# Replaces this server's data and keys with a backup made by backup.sh, here or on another server.
#
#   ./restore.sh [--yes] [--no-safety-backup] backups/iemabot-....tar.gz
#
# First checks the backup and backs up the current state. The backup is unpacked next to the live data and only swapped
# in once that worked, so a failure on the way (a full disk, a lost connection) leaves the server as it was.
# The server also returns to the version the backup came from, if that can still be downloaded.
set -euo pipefail
ORIGIN="$PWD"
cd "$(dirname "$0")"
. ./lib.sh
require_installed

YES=""; SAFETY=1; FILE=""
while [ $# -gt 0 ]; do
  case "$1" in
    --yes) YES=1; shift ;;
    --no-safety-backup) SAFETY=""; shift ;;
    -*) die "Unknown option: $1" ;;
    *) FILE="$1"; shift ;;
  esac
done
[ -n "$FILE" ] || die "Usage: ./restore.sh [--yes] [--no-safety-backup] BACKUP_FILE"
case "$FILE" in
  /*) ;;
  *) if [ -f "$ORIGIN/$FILE" ]; then FILE="$ORIGIN/$FILE"; else FILE="$PWD/$FILE"; fi ;;
esac
[ -f "$FILE" ] || die "No file at $FILE"

take_lock

# Reads the whole archive, which is what finds a damaged one.
step "Checking $FILE"
listing="$(mktemp)"
trap 'rm -f "$listing"' EXIT
tar tzf "$FILE" > "$listing" 2>/dev/null || die "$FILE is damaged or not a backup. Nothing was changed."
grep -q '^backend/config/configs\.php$' "$listing" && grep -q '^esmira_data/' "$listing" \
  || die "$FILE is not a backup made by backup.sh. Nothing was changed."
# A config without this is a server that was never set up: restoring it would leave the first-run page open to anyone.
case "$(tar xzOf "$FILE" backend/config/configs.php)" in
  *dataFolder_path*) ;;
  *) die "$FILE comes from a server without an admin account, so it cannot be restored. Nothing was changed." ;;
esac
grep -q '^esmira_data/\.logins$' "$listing" || die "$FILE comes from a server without an admin account, so it cannot be restored. Nothing was changed."

if [ -z "$YES" ]; then
  has_tty || die "Pass --yes to run this without a terminal."
  printf 'This replaces all data on this server with %s. Type yes to continue: ' "$FILE" > /dev/tty
  read -r answer < /dev/tty
  [ "$answer" = "yes" ] || die "Nothing was changed."
fi

wanted="$(tar xzOf "$FILE" esmira_data/VERSION 2>/dev/null | tr -d '[:space:]' || true)"
current="$(image_version)"
pin=""
if [ -n "$wanted" ] && [ "$wanted" != "$current" ]; then
  if fetch_image "$wanted" 2>/dev/null; then
    pin="$wanted"
  elif version_lt "$wanted" "$current"; then
    say "Version $wanted cannot be downloaded any more; version $current will upgrade this older backup."
  else
    die "This backup is from version $wanted, newer than this server's $current, which cannot read it. Run ./update.sh first. Nothing was changed."
  fi
fi

safety=""
if [ -n "$SAFETY" ] && has_data; then
  step "Backing up the current state first"
  safety="$(./backup.sh --safety)"
fi

step "Restoring"
SWAP=""
DONE=""
caddy_running="$(docker compose ps -q --status running caddy)"
app_running="$(docker compose ps -q --status running app)"
finish() {
  set +e # Whatever fails in here, the rest of it still has to happen.
  rm -f "$listing"
  [ -z "$DONE" ] || return 0
  say ""
  if [ -z "$SWAP" ]; then
    say "The restore did not go through and the data was not touched. Starting the server again."
    if [ -n "$app_running" ]; then resume_app || warn "The server did not come back up. See: docker compose logs app"; fi
    if [ -n "$caddy_running" ]; then docker compose start caddy >/dev/null 2>&1; fi
  else
    say "The restore stopped halfway, so the server stays offline. See: docker compose logs app"
    say "The data from before is in data/.restore-old. To try again, or to go back to the state from before:"
    say "  ./restore.sh $FILE"
    [ -z "$safety" ] || say "  ./restore.sh $safety"
  fi
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
# Offline for the duration: nobody may reach a server in a half-restored state.
docker compose stop caddy app >/dev/null 2>&1
# shellcheck disable=SC2016 # expanded inside the container
in_kit 'set -e; cd /kit/data; rm -rf .restore; mkdir .restore; tar xzf - -C .restore
  test -f .restore/backend/config/configs.php; test -d .restore/esmira_data' < "$FILE" \
  || { in_kit 'rm -rf /kit/data/.restore' || true; die "Could not unpack the backup (is the disk full? df -h)."; }

# Each step checks what is there, so that this can be run again after being stopped between any two of them.
SWAP=1
# shellcheck disable=SC2016 # expanded inside the container
in_kit "set -e; cd /kit/data
  if [ -e .restore-old ]; then mv .restore-old .restore-old-\$(date +%s); fi
  mkdir .restore-old
  if [ -e esmira_data ]; then mv esmira_data .restore-old/esmira_data; fi
  if [ -e config ]; then mv config .restore-old/config; fi
  mv .restore/esmira_data esmira_data; mv .restore/backend/config config
  if [ -d .restore/webapp ]; then
    if [ -e /kit/webapp ]; then mv /kit/webapp .restore-old/webapp; fi
    mv .restore/webapp /kit/webapp; chown -R $(id -u):$(id -g) /kit/webapp
  fi
  if [ -f .restore/compose.override.yml ] && [ ! -e /kit/compose.override.yml ]; then
    mv .restore/compose.override.yml /kit/compose.override.yml; chown $(id -u):$(id -g) /kit/compose.override.yml
  fi
  rm -rf .restore"

if [ -n "$pin" ]; then
  env_set IEMABOT_VERSION "$pin"
  say "This backup is from version $pin: the server now runs that version. ./update.sh latest moves it on again."
fi
start_app
[ "$(app_status init)" = "true" ] || die "The server does not recognise the restored data."
docker compose up -d --wait --wait-timeout "$START_TIMEOUT" caddy
DONE=1 # From here on the server is back: nothing below is a reason to call this failed.
in_kit 'rm -rf /kit/data/.restore-old' || warn "Could not remove the old copy in data/.restore-old. ./doctor.sh will remind you."
say "Restored."
[ -z "$safety" ] || say "The state from before is kept in $safety. Delete it when you no longer need it."
