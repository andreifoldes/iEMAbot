#!/usr/bin/env bash
# Checks that the server works and says what to do about anything that does not. Changes nothing.
#
#   ./doctor.sh          exits non-zero when a check fails
#   ./doctor.sh --wait   gives the HTTPS certificate up to 2 minutes to arrive
set -uo pipefail
cd "$(dirname "$0")" || exit 1
. ./lib.sh
require_installed

FAILED=0
pass() { printf 'PASS  %s\n' "$1"; }
note() { printf 'WARN  %s\n      %s\n' "$1" "$2"; }
fail() { printf 'FAIL  %s\n      %s\n' "$1" "$2"; FAILED=1; }

DOMAIN="$(env_get DOMAIN)"
PUBLIC_URL="$(env_get PUBLIC_URL)"
CADDYFILE="$(env_get CADDYFILE Caddyfile)"
HTTP_BIND="$(env_get HTTP_BIND 80)"

# How this machine reaches its own server. Behind another web server that means Caddy's local port, claiming HTTPS the
# way the other web server would; a name that is not public ("localhost") has a certificate from Caddy's own authority.
CURL=(curl -sS --max-time 20)
if [ "$CADDYFILE" = "Caddyfile.behind-proxy" ]; then
  case "$HTTP_BIND" in *:*) BASE="http://$HTTP_BIND" ;; *) BASE="http://127.0.0.1:$HTTP_BIND" ;; esac
  CURL+=(-H "Host: $DOMAIN" -H "X-Forwarded-Proto: https")
else
  BASE="$PUBLIC_URL"
  [ "$DOMAIN" != "localhost" ] || CURL+=(-k)
fi
http_code() { "${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE$1" 2>/dev/null; }

# --- The containers ---
if [ -n "$(docker compose ps -q --status running app)" ]; then
  health="$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q app)" 2>/dev/null)"
  if [ "$health" = "healthy" ]; then pass "App container is healthy"
  else fail "App container is $health" "See: docker compose logs app"; fi
else
  fail "App container is not running" "Start it: docker compose up -d. If it stops again: docker compose logs app"
fi
if [ -n "$(docker compose ps -q --status running caddy)" ]; then pass "Web server container is running"
elif [ "$(app_status init)" = "false" ]; then fail "Web server container is not running, and there is no admin account yet" "Run ./install.sh again: it creates the account first."
else fail "Web server container is not running" "Start it: docker compose up -d. If it stops again: docker compose logs caddy"; fi

# --- The server, from the outside ---
if [ "${1:-}" = "--wait" ]; then
  for _ in $(seq 1 24); do
    [ "$(http_code /pwa/)" = "200" ] && break
    sleep 5
  done
fi
code="$(http_code /pwa/)"
if [ "$code" = "200" ]; then
  pass "Participant app answers at $PUBLIC_URL/pwa/"
else
  hint="Got '$code'."
  if [ "$CADDYFILE" = "Caddyfile" ]; then
    hint="$hint Check that DNS for $DOMAIN points here and that ports 80 and 443 are open in the firewall and your provider's security group. Certificate errors: docker compose logs caddy | grep -i -E 'error|challenge'"
  fi
  fail "Participant app does not answer at $BASE/pwa/" "$hint"
fi
[ "$(http_code /esmira/)" = "200" ] && pass "Researcher dashboard answers" || fail "Researcher dashboard does not answer" "See: docker compose logs app caddy"
if "${CURL[@]}" "$BASE/esmira/api/app_version.php" 2>/dev/null | grep -q '"success":true'; then pass "API answers"
else fail "API does not answer" "See: docker compose logs app caddy"; fi
for private in /esmira/backend/config/configs.php /esmira/esmira_data/.htaccess /esmira/cli/selfhost.php; do
  code="$(http_code "$private")"
  case "$code" in
    200) fail "$private is reachable from outside" "This must never happen. Take the server offline (docker compose stop caddy) and report it." ;;
    000) fail "Could not check whether $private is private" "The server does not answer. Fix that first." ;;
    *) pass "$private is not reachable from outside ($code)" ;;
  esac
done

# --- The application ---
if [ -n "$(docker compose ps -q --status running app)" ]; then
  version="$(app_status version)"; init="$(app_status init)"; ready="$(app_status ready)"; keys="$(app_status pushKeys)"
  redirect="$(app_status wearablesRedirectUri)"; heartbeat="$(app_status pushSenderLastRunSecondsAgo)"
  pass "Running version ${version:-unknown}"
  data_version="$(app_status dataVersion)"
  if [ -n "$data_version" ] && [ "$data_version" != "$version" ]; then
    fail "The data is at version $data_version, the server at ${version:-unknown}" "An upgrade of the data did not finish: docker compose logs app. To go back to before it: ./restore.sh with the backup update.sh made."
  fi
  pinned="$(env_get IEMABOT_VERSION latest)"
  [ "$pinned" = "latest" ] || note "Staying on version $pinned" "It gets no updates, security fixes included. To follow releases again: ./update.sh latest"
  [ "$init" = "true" ] && pass "Admin account exists" || fail "No admin account yet" "Run ./install.sh again to create it."
  [ "$ready" = "true" ] && pass "Server reports ready" || fail "Server is not ready" "It may be in maintenance mode or out of disk space. See: docker compose logs app"
  [ "$keys" = "true" ] && pass "Push notification keys exist" || fail "Push notification keys are missing" "Run: docker compose exec -u www-data app php cli/generate_vapid.php"
  case "$redirect" in
    "$PUBLIC_URL"/*) pass "Server config matches $PUBLIC_URL" ;;
    *) fail "Server config does not match PUBLIC_URL" "It says '$redirect'. Check PUBLIC_URL in .env, then: docker compose up -d --force-recreate app" ;;
  esac
  if db_result="$(app_php cli/selfhost.php db-check 2>&1)"; then pass "Database is intact"
  else fail "The database is damaged: ${db_result:0:200}" "New answers may not be saved. Go back to the last good backup: ./restore.sh backups/... , and tell the study's data manager."; fi
  if docker compose exec -T app pgrep cron >/dev/null 2>&1; then pass "Background jobs (cron) are running"
  else fail "Background jobs (cron) are not running" "Questionnaire prompts stop without them. Restart: docker compose restart app"; fi
  if [ -z "$heartbeat" ]; then note "Push sender has not run yet" "It runs every minute; check again shortly."
  elif [ "$heartbeat" -le 180 ]; then pass "Push sender ran ${heartbeat}s ago"
  elif [ "$(docker compose exec -T app ps -o etimes= 1 2>/dev/null | tr -d '[:space:]')" -lt 180 ] 2>/dev/null; then
    note "Push sender has not run since the server started" "It runs every minute; check again shortly."
  else fail "Push sender last ran ${heartbeat}s ago" "See inside the container: /var/log/esmira_push.log"; fi
fi

# --- The machine ---
for leftover in data/.restore-old*; do
  [ -e "$leftover" ] || continue
  note "An old copy of the data is still here: $leftover" "A restore left it. If the server's data looks right, it still holds participant data, so remove it: docker run --rm -v \"\$PWD/data:/d\" alpine rm -rf /d/$(basename "$leftover")"
done
free_gb="$(df -Pk . | awk 'NR==2 {print int($4 / 1048576)}')"
if [ "$free_gb" -lt 2 ]; then fail "Only ${free_gb} GB of disk free" "The server stops accepting data when the disk fills. Free space: docker system prune, or enlarge the disk."
elif [ "$free_gb" -lt 5 ]; then note "Only ${free_gb} GB of disk free" "Updates and backups need room. Free space: docker system prune, or enlarge the disk."
else pass "${free_gb} GB of disk free"; fi
# shellcheck disable=SC2012 # the names are ours: dates and versions only
newest="$(ls -1t backups/iemabot-2*.tar.gz 2>/dev/null | head -n 1)"
if [ -z "$newest" ]; then note "No backups yet" "Run ./backup.sh, and copy the backups folder off this machine."
elif [ -n "$(find "$newest" -mtime +2 2>/dev/null)" ]; then note "Newest backup is more than 2 days old: $newest" "Run ./backup.sh and see backups/backup.log. A nightly one: ./backup.sh --schedule"
else pass "Newest backup: $newest"; fi
if ! command -v crontab >/dev/null 2>&1; then note "No nightly backup is scheduled, because cron is not installed" "Install cron (on Debian and Ubuntu: sudo apt-get install cron), then run ./backup.sh --schedule, or back up another way."
elif crontab -l 2>/dev/null | grep -F "# iemabot-backup $PWD" >/dev/null; then pass "Nightly backup is scheduled"
else note "No nightly backup is scheduled" "Run ./backup.sh --schedule, or back up another way."; fi

printf '\n'
if [ "$FAILED" = 0 ]; then printf 'All checks passed.\n'; else printf 'Some checks failed; each says what to do.\n'; fi
exit "$FAILED"
