#!/usr/bin/env bash
# Backs up everything the server cannot rebuild: studies, responses, media, the config with its keys, your own tasks in
# webapp/ and your compose.override.yml. The server is stopped while it runs, so the data is consistent. Participants'
# apps retry uploads afterwards.
#
#   ./backup.sh              back up now; prints the file's name
#   ./backup.sh --schedule   also back up every night at 03:17 (cron)
#   ./backup.sh --safety     a backup that is never rotated away (restore.sh takes one before replacing anything)
#
# The file holds participant data and the server's keys. Keep copies somewhere safe, and off this machine.
set -euo pipefail
cd "$(dirname "$0")"
. ./lib.sh
require_installed

RESERVE_KB=1048576 # Left free after a backup: uploads stop when the disk is full.
MARKER=.stopped-by-backup
PREFIX=iemabot

schedule() {
  command -v crontab >/dev/null 2>&1 || { say "cron is not installed, so no nightly backup can be scheduled."; return 1; }
  local marker="# iemabot-backup $PWD" current
  current="$(crontab -l 2>/dev/null | grep -vF "$marker" || true)"
  # Cron's PATH is minimal, and may not hold docker.
  printf '%s\n17 3 * * * cd "%s" && PATH="$PATH:/usr/local/bin:/snap/bin" ./backup.sh >> backups/backup.log 2>&1 %s\n' "$current" "$PWD" "$marker" \
    | sed '/^$/d' | crontab -
  say "Nightly backup scheduled (03:17, keeps the newest $(keep_count))."
}

keep_count() {
  local keep
  keep="$(env_get BACKUP_KEEP 7)"
  case "$keep" in ''|*[!0-9]*|0) printf 7 ;; *) printf '%s' "$keep" ;; esac
}

case "${1:-}" in
  --schedule) schedule; exit ;;
  --safety) PREFIX=safety ;;
  '') ;;
  *) die "Unknown option: $1" ;;
esac

take_lock
mkdir -p backups webapp
chmod 700 backups
rm -f backups/*.partial # Left by a run that was killed: the lock says none is running now.

free_kb="$(df -Pk backups | awk 'NR==2 {print $4}')"
used_kb="$(in_kit 'du -sk /kit/data/esmira_data | cut -f1' | tr -d '[:space:]')" || die "Could not start a container to read the data. Is the image still there? See: docker images"
[ "$((${used_kb:-0} + RESERVE_KB))" -lt "$free_kb" ] \
  || die "The data takes $((${used_kb:-0} / 1024)) MB and only $((free_kb / 1024)) MB are free, too little for a backup. Free up space or enlarge the disk."

version="$(data_version)"
name="backups/$PREFIX-$(date +%Y%m%d-%H%M%S)-v${version:-unknown}.tar.gz"

# A reboot during a backup leaves the server stopped for good, because Docker respects the stop: the marker notices that.
was_running=""
if [ -n "$(docker compose ps -q --status running app)" ] || [ -f "$MARKER" ]; then was_running=1; fi
restart() {
  set +e
  [ -n "$was_running" ] || return 0
  if resume_app; then rm -f "$MARKER"; else warn "The server did not come back up. See: docker compose logs app"; fi
}
trap restart EXIT
trap 'exit 1' HUP INT TERM
if [ -n "$was_running" ]; then touch "$MARKER"; fi
docker compose stop app >/dev/null 2>&1 || die "Could not stop the server for the backup. See: docker compose ps"

umask 077
# shellcheck disable=SC2016 # expanded inside the container
in_kit 'extra=""; [ -f /kit/compose.override.yml ] && extra=compose.override.yml
  tar czf - --transform "s,^config,backend/config," -C /kit/data esmira_data config -C /kit webapp $extra' > "$name.partial" \
  || { rm -f "$name.partial"; die "The backup could not be written. Nothing was changed."; }
gzip -t "$name.partial" || { rm -f "$name.partial"; die "The backup came out damaged, so it was thrown away. Check the disk: df -h"; }
mv "$name.partial" "$name"

if [ "$PREFIX" = "iemabot" ]; then
  # shellcheck disable=SC2012 # the names are ours: dates and versions only
  ls -1t backups/iemabot-2*.tar.gz | tail -n +"$(($(keep_count) + 1))" | while IFS= read -r old; do rm -f "$old"; done
fi

say "Backup written: $PWD/$name ($(du -h "$name" | awk '{print $1}'))"
printf '%s\n' "$name"
