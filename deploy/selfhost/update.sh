#!/usr/bin/env bash
# Updates iEMAbot: downloads the new image, backs up, restarts on it, checks the result.
#
#   ./update.sh                 update to what IEMABOT_VERSION in .env follows (normally the latest release)
#   ./update.sh 3.7.31          move to that version and stay on it (not to one older than the data)
#   ./update.sh latest          follow the latest release again
#   ./update.sh --no-backup     skip the backup
#
# The server and its data are not touched until the new image is downloaded and the backup is written, so failing before
# that is harmless.
set -euo pipefail
cd "$(dirname "$0")"
. ./lib.sh
require_installed

BACKUP=1; FORCE=""; VERSION=""
for arg in "$@"; do
  case "$arg" in
    --no-backup) BACKUP="" ;;
    --force) FORCE=1 ;;
    -*) die "Unknown option: $arg" ;;
    *) VERSION="$arg" ;;
  esac
done

take_lock

target="${VERSION:-$(env_get IEMABOT_VERSION latest)}"
step "Downloading version $target"
fetch_image "$target" || die "Could not download version $target. Nothing was changed."
target_version="$(image_version "$target")"

data="$(data_version)"
if [ -n "$data" ] && [ -n "$target_version" ] && version_lt "$target_version" "$data"; then
  die "The data was last written by version $data, and older code cannot read newer data, so the server stays where it is. To go back, restore a backup from that version: ./restore.sh FILE"
fi

pin_note() { [ "$target" = "latest" ] || say "This server stays on $target. ./update.sh latest follows releases again."; }

running="$(docker inspect --format '{{.Image}}' "$(docker compose ps -q app)" 2>/dev/null || true)"
wanted="$(docker image inspect --format '{{.Id}}' "$(image_ref "$target")")"
if [ -z "$FORCE" ] && [ -n "$running" ] && [ "$running" = "$wanted" ]; then
  env_set IEMABOT_VERSION "$target"
  say "Already on the newest image for this setting (version ${target_version:-unknown})."
  pin_note
  exit 0
fi

# From here on this script is the new image's own, so a fix to the update process applies to the update itself.
if [ -z "${IEMABOT_KIT_SYNCED:-}" ]; then
  sync_kit "$target"
  if [ -n "$KIT_CHANGED" ]; then
    export IEMABOT_KIT_SYNCED=1
    exec ./update.sh "$@"
  fi
fi

latest_backup=""
if [ -n "$BACKUP" ]; then
  step "Backing up first"
  latest_backup="$(./backup.sh)" || die "The backup failed, so the update did not go ahead. The server still runs the old version."
fi

step "Restarting on version ${target_version:-$target}"
previous_setting="$(env_get IEMABOT_VERSION latest)"
env_set IEMABOT_VERSION "$target"
if ! docker compose up -d --wait --wait-timeout "$START_TIMEOUT"; then
  say ""
  # Docker may have failed before replacing anything, and .env must say what really runs.
  if [ -n "$running" ] && [ "$(docker inspect --format '{{.Image}}' "$(docker compose ps -q app)" 2>/dev/null || true)" = "$running" ]; then
    env_set IEMABOT_VERSION "$previous_setting"
    say "Docker could not start the new version; the server still runs the old one. See the error above."
    exit 1
  fi
  say "The server did not come back up. See what it says first: docker compose logs app"
  [ -z "$latest_backup" ] || say "To return to the version from before, with the data from before: ./restore.sh $latest_backup"
  exit 1
fi
# Caddy reads its config when it starts, and `up` leaves it running if only its files changed.
docker compose restart caddy >/dev/null
# Only this project's old images: the machine may run other things.
docker image prune --force --filter "label=org.opencontainers.image.title=iEMAbot" >/dev/null 2>&1 || true

step "Checking the result"
./doctor.sh --wait || {
  [ -z "$latest_backup" ] || say "To return to the version from before, with the data from before: ./restore.sh $latest_backup"
  exit 1
}
say ""
say "Now on version $(app_status version)."
pin_note
