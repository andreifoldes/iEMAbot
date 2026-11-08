#!/usr/bin/env bash
# End-to-end test of the self-host kit (deploy/selfhost/) against a locally built image: everything a lab goes through,
# on this machine, with a private certificate for "localhost". Deletes the test install afterwards.
#
#   scripts/demo/selfhost-e2e.sh IMAGE:TAG     for example iemabot-local:e2e
#
# scripts/demo/lab-journey.py and the two browser journeys are the heart of it: can a new lab make a study, send its link and
# get a participant's answers, for every question type?
# Covers: unattended install, routing, what must stay private, a real study and response, running the installer again,
# backup and restore with a realistic number of files, failed and refused restores, updating between two versions and
# going back, the in-app updater being off, running behind another web server, and moving to a new server from a backup.
# Env: REQUIRE_UI=1 fails instead of skipping when Playwright is missing.
#
# Run it on Linux. On a Mac, Docker Desktop's file sharing is not reliable enough for SQLite's write-ahead log: writes fail
# and the database can get corrupted, which on a real disk does not happen. CI (selfhost-smoke.yml) is the one to believe.
set -uo pipefail

IMAGE_REF="${1:?usage: selfhost-e2e.sh IMAGE:TAG}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
KIT="$ROOT/deploy/selfhost"
WORK="$(mktemp -d "${RUNNER_TEMP:-$HOME}/iemabot-e2e.XXXXXX")"
HTTPS_PORT=18443
HTTP_PORT=18080
PROXY_PORT=18081
BASE="https://localhost:$HTTPS_PORT"
ADMIN=labadmin
PASS="e2e-$(head -c 16 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20)"
JAR="$WORK/cookies"
FAIL=0

export IEMABOT_IMAGE="${IMAGE_REF%:*}" IEMABOT_VERSION="${IMAGE_REF##*:}" IEMABOT_PULL=never IEMABOT_ADMIN_PASSWORD="$PASS"

# A newer version to update to, and the tags for going back by version number, as the registry would have them.
OLD_VERSION="$(docker run --rm --entrypoint cat "$IMAGE_REF" /var/www/html/VERSION | tr -d '[:space:]')"
NEW_VERSION=99.0.0
docker tag "$IMAGE_REF" "$IEMABOT_IMAGE:$OLD_VERSION"
printf 'FROM %s\nRUN printf %s > /var/www/html/VERSION\n' "$IMAGE_REF" "$NEW_VERSION" | docker build -q -t "$IEMABOT_IMAGE:$NEW_VERSION" - >/dev/null

check() { # check "name" command...
  local name="$1"; shift
  local out; out="$WORK/check-output"
  if "$@" > "$out" 2>&1; then echo "PASS  $name"; else echo "FAIL  $name"; tail -n 15 "$out" | sed 's/^/      /'; FAIL=1; fi
}
http_code() { curl -ksS --max-time 20 -o /dev/null -w '%{http_code}' "$1"; }
code_is() { [ "$(http_code "$1")" = "$2" ]; }
redirects_to() { [ "$(curl -ksS --max-time 20 -o /dev/null -w '%{redirect_url}' "$1")" = "$2" ]; }
in_app() { docker compose --project-directory "$DIR" exec -T "$@"; }

remove_install() { # Data files belong to the container's user, so they are deleted from inside a container.
  [ -d "${1:-}" ] || return 0
  ( cd "$1" && docker compose down -v --remove-orphans >/dev/null 2>&1 )
  docker run --rm --entrypoint sh -v "$1:/gone" "$IMAGE_REF" -c 'find /gone -mindepth 1 -delete' >/dev/null 2>&1
}
cleanup() {
  [ -z "${KIT_SERVER:-}" ] || kill "$KIT_SERVER" 2>/dev/null
  remove_install "$WORK/one"
  remove_install "$WORK/two"
  rm -rf "$WORK"
  docker rmi "$IEMABOT_IMAGE:$NEW_VERSION" >/dev/null 2>&1
}
trap cleanup EXIT

submit_response() { # submit_response BASE MARKER: the same upload the participant app sends
  local now; now="$(date +%s)"
  curl -ksS --max-time 20 -H 'Content-Type: application/json' "$1/esmira/api/datasets.php" -d "{
    \"userId\":\"e2e-$now\",\"appVersion\":\"e2e\",\"appType\":\"Web\",\"serverVersion\":12,
    \"dataset\":[{\"dataSetId\":$now,\"studyId\":4242,\"studyVersion\":1,\"studySubVersion\":0,\"studyLang\":\"en\",
      \"accessKey\":\"demo\",\"eventType\":\"questionnaire\",\"questionnaireName\":\"Daily check-in\",
      \"questionnaireInternalId\":424201,\"responseTime\":${now}000,\"timezone\":\"Etc/UTC\",\"group\":0,
      \"entryTime\":${now}000,\"responses\":{\"mood\":\"4\",\"note\":\"$2\"}}]}" \
    | jq -e '.success == true and .dataset.states[0].success == true'
}
logs_in() { curl -ksS -d "accountName=$ADMIN" --data-urlencode "pass=$1" "$BASE/esmira/api/admin.php?type=login" | jq -e '.success == true'; }
server_version() { in_app -u www-data app php cli/selfhost.php status | sed -n 's/^version=//p'; }
env_says() { grep -qxF "$1" "$DIR/.env"; }
# The database is where answers are kept: this looks for the text in any stored answer.
response_stored() {
  in_app app php -r '$d = new PDO("sqlite:/var/www/html/esmira_data/iemabot.sqlite"); $q = $d->prepare("select count(*) from responses where data like ?"); $q->execute(["%" . $argv[1] . "%"]); exit($q->fetchColumn() > 0 ? 0 : 1);' "$1"
}
study_listed() { curl -ksS --max-time 20 "$1/esmira/api/studies.php?access_key=demo" | jq -e '[.. | objects | select(.id? == 4242)] | length > 0'; }
config_says() { in_app -u www-data app php cli/selfhost.php status | grep -qxF "$1"; }

install_one() {
  HTTP_BIND="$HTTP_PORT" HTTPS_BIND="$HTTPS_PORT" "$KIT/install.sh" --yes --no-backup-schedule --dir "$DIR" \
    --domain localhost --email ops@example.org --admin "$ADMIN"
}

echo "== The kit's file list is complete"
listed="$(bash -c ". '$KIT/lib.sh'; printf '%s\n' \"\${KIT_FILES[@]}\"" | sort)"
actual="$(cd "$KIT" && find . -type f | sed 's|^\./||' | sort)"
check "lib.sh lists exactly the files in deploy/selfhost" test "$listed" = "$actual"

echo; echo "== Install the way the guide says: curl | bash"
DIR="$WORK/one"
( cd "$KIT" && exec python3 -m http.server 18099 --bind 127.0.0.1 ) >/dev/null 2>&1 &
KIT_SERVER=$!
for _ in $(seq 1 20); do curl -fsS -o /dev/null http://127.0.0.1:18099/lib.sh 2>/dev/null && break; sleep 0.5; done
export IEMABOT_KIT_URL=http://127.0.0.1:18099
piped_install() { # From an unrelated folder, so that nothing can be found next to the script.
  ( cd "$WORK" && curl -fsSL "$IEMABOT_KIT_URL/install.sh" | HTTP_BIND="$HTTP_PORT" HTTPS_BIND="$HTTPS_PORT" bash -s -- \
      --yes --no-backup-schedule --dir "$DIR" --domain localhost --email ops@example.org --admin "$ADMIN" )
}
check "the installer finishes" piped_install
check "it installed the whole kit" sh -c "cd '$KIT' && find . -type f | while read -r f; do cmp -s \"\$f\" '$DIR'/\"\$f\" || exit 1; done"
check "its scripts can be run" test -x "$DIR/doctor.sh"
kill "$KIT_SERVER" 2>/dev/null; KIT_SERVER=""
unset IEMABOT_KIT_URL
check "the installer never stores the admin password" sh -c "! grep -rq '$PASS' '$DIR/.env' '$DIR/compose.yml'"
check ".env is private" sh -c "ls -l '$DIR/.env' | grep -q '^-rw-------'"
check "doctor passes" "$DIR/doctor.sh"
check "doctor checks the database" sh -c "'$DIR/doctor.sh' | grep -q 'Database is intact'"
check "a second install cannot take over this one" sh -c "! HTTP_BIND=18082 HTTPS_BIND=18446 '$KIT/install.sh' --yes --no-backup-schedule --dir '$WORK/elsewhere' --domain localhost --email ops@example.org --admin other"
check "and it says where the server runs" sh -c "HTTP_BIND=18082 HTTPS_BIND=18446 '$KIT/install.sh' --yes --no-backup-schedule --dir '$WORK/elsewhere' --domain localhost --email ops@example.org --admin other 2>&1 | grep -q 'already runs from'"
# Only that function, in a real Linux shell with nobody to answer: DNS for a name that does not exist.
dns_explained() {
  local said # The check stops the installer, as it should with nobody to answer: what matters is what it said.
  said="$(docker run --rm --entrypoint bash -v "$KIT:/k:ro" "$IMAGE_REF" -c \
    'cp -R /k /tmp/k; sed "\$d" /k/install.sh > /tmp/k/install.sh; . /tmp/k/install.sh; DOMAIN=does-not-exist.invalid; check_dns' 2>&1)"
  printf '%s\n' "$said" | grep -q 'does not resolve yet'
}
check "a domain that does not resolve is explained" dns_explained

echo; echo "== Routing"
check "/ goes to the participant app" redirects_to "$BASE/" "$BASE/pwa/"
check "/esmira gets its slash" redirects_to "$BASE/esmira" "$BASE/esmira/"
check "participant app served" code_is "$BASE/pwa/" 200
check "service worker served" code_is "$BASE/pwa/sw.js" 200
check "dashboard served" code_is "$BASE/esmira/" 200
check "anything else is not found" code_is "$BASE/some/other/path" 404
check "the first-run page is gone" sh -c "! curl -ksS '$BASE/esmira/api/admin.php?type=InitESMiraPrep' | grep -q dirBase"

echo "<p>task</p>" > "$DIR/webapp/e2e.html"
check "self-hosted tasks served from the webapp folder" code_is "$BASE/webapp/e2e.html" 200

echo; echo "== What must stay private"
for path in backend/config/configs.php esmira_data/.htaccess esmira_data/.logins cli/selfhost.php .htaccess; do
  check "/esmira/$path is not served" code_is "$BASE/esmira/$path" 404
done

echo; echo "== A real study and response"
export DEMO_ADMIN="$ADMIN" DEMO_PASS="$PASS" BOOTSTRAP_CURL_ARGS=-k
check "log in and import a study" "$ROOT/scripts/demo/bootstrap.sh" "$BASE/esmira"
check "study listed by access key" study_listed "$BASE"
check "participant upload accepted" submit_response "$BASE" e2e-before-backup
check "response stored" response_stored e2e-before-backup
check "response stored in SQLite" in_app app php -r '$d=new PDO("sqlite:/var/www/html/esmira_data/iemabot.sqlite"); exit($d->query("select count(*) from responses")->fetchColumn() > 0 ? 0 : 1);'
check "login cookie is marked Secure" sh -c "curl -ksS -D - -o /dev/null -d 'accountName=$ADMIN' --data-urlencode 'pass=$PASS' '$BASE/esmira/api/admin.php?type=login' | grep -i '^set-cookie:' | grep -qi secure"

echo; echo "== Every question type, over the API"
check "the demo study's answers all arrive, media included, on a study of its own" \
  python3 "$ROOT/scripts/demo/lab-journey.py" "$BASE" --user "$ADMIN" --password "$PASS" --insecure

echo; echo "== Address-dependent config"
check "wearable callback follows the address" config_says "wearablesRedirectUri=$BASE/esmira/api/wearables_oauth.php"
check "chat channels follow the address" config_says "publicBaseUrl=$BASE/esmira/"
check "cron is running" in_app app pgrep cron
# Wait for a cron round, so the next check would see what the chat channel jobs had done.
for _ in $(seq 1 45); do in_app app test -e /var/www/html/esmira_data/.push_heartbeat.json && break; sleep 2; done
check "cron has run" in_app app test -e /var/www/html/esmira_data/.push_heartbeat.json
check "without a bot, the chat channel jobs write nothing" sh -c "! docker compose --project-directory '$DIR' exec -T app sh -c 'test -e /var/www/html/esmira_data/.channels || grep -q . /var/log/esmira_channels.log'"

echo; echo "== In-app updater"
curl -ksS -c "$JAR" -b "$JAR" -d "accountName=$ADMIN" --data-urlencode "pass=$PASS" "$BASE/esmira/api/admin.php?type=login" >/dev/null
check "dashboard is told it runs from an image" sh -c "curl -ksS -b '$JAR' '$BASE/esmira/api/admin.php?type=GetPermissions' | jq -e '.dataset.containerImage == true'"
check "the one-click update refuses" sh -c "curl -ksS -b '$JAR' -d 'url=https://github.com/andreifoldes/iEMAbot/releases/download/x/y.zip' '$BASE/esmira/api/admin.php?type=UpdateStepDownload' | grep -q 'container image'"

echo; echo "== A new lab's journey, in a browser"
if python3 -c 'import playwright' 2>/dev/null; then
  check "create a study by hand, send its link, a participant signs up, the admin sees the answer" \
    python3 "$ROOT/scripts/demo/lab-journey-browser.py" "$BASE" --user "$ADMIN" --password "$PASS" --insecure
  check "a participant answers every question type the app shows" \
    python3 "$ROOT/scripts/demo/all-types-browser.py" "$BASE" --user "$ADMIN" --password "$PASS" --insecure
  check "and with the voice memo skipped, its typed fallback" \
    python3 "$ROOT/scripts/demo/all-types-browser.py" "$BASE" --user "$ADMIN" --password "$PASS" --insecure --skip-voice
  check "an answer survives the app being closed during its upload, and arrives once" \
    python3 "$ROOT/scripts/demo/upload-failsafe-browser.py" "$BASE" --user "$ADMIN" --password "$PASS" --insecure
elif [ "${REQUIRE_UI:-0}" = "1" ]; then
  check "Playwright is installed" false
else
  echo "SKIP  browser journeys (pip install playwright && playwright install chromium)"
fi

echo; echo "== Running the installer again changes nothing"
check "the installer finishes" install_one
check "the admin can still log in" sh -c "curl -ksS -d 'accountName=$ADMIN' --data-urlencode 'pass=$PASS' '$BASE/esmira/api/admin.php?type=login' | jq -e '.success == true'"
check "the study is still there" study_listed "$BASE"

echo; echo "== A lost password"
NEW_PASS="e2e-new-$(head -c 16 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20)"
check "a too short password is refused" sh -c "cd '$DIR' && ! printf 'short\n' | ./reset-password.sh --stdin '$ADMIN'"
check "an unknown account is refused" sh -c "cd '$DIR' && ! printf 'long-enough-password\n' | ./reset-password.sh --stdin nobody"
check "the password can be reset" sh -c "cd '$DIR' && printf '%s\n' '$NEW_PASS' | ./reset-password.sh --stdin '$ADMIN'"
check "the new password works" logs_in "$NEW_PASS"
check "the account is still an admin" sh -c "curl -ksS -c '$JAR' -d 'accountName=$ADMIN' --data-urlencode 'pass=$NEW_PASS' '$BASE/esmira/api/admin.php?type=login' >/dev/null; curl -ksS -b '$JAR' '$BASE/esmira/api/admin.php?type=GetPermissions' | jq -e '.dataset.isAdmin == true'"
check "the old password no longer works" sh -c "! curl -ksS -d 'accountName=$ADMIN' --data-urlencode 'pass=$PASS' '$BASE/esmira/api/admin.php?type=login' | jq -e '.success == true'"
check "a reset also lifts the lockout from failed logins" sh -c "cd '$DIR' && printf '%s\n' '$PASS' | ./reset-password.sh --stdin '$ADMIN'"
check "so the password works right away" logs_in "$PASS"

echo; echo "== Backup and restore, with a realistic number of files"
in_app -u www-data app sh -c 'mkdir -p /var/www/html/esmira_data/studies/4242/filler && cd /var/www/html/esmira_data/studies/4242/filler && i=0; while [ $i -lt 5000 ]; do : > f$i; i=$((i+1)); done'
printf '<p>own task</p>\n' > "$DIR/webapp/own-task.html"
BACKUP="$(cd "$DIR" && ./backup.sh)"
check "backup written" test -s "$DIR/$BACKUP"
check "backup is private" sh -c "ls -l '$DIR/$BACKUP' | grep -q '^-rw-------'"
check "backup holds the data, the keys and own tasks" sh -c "tar tzf '$DIR/$BACKUP' > '$WORK/list'; grep -q 'esmira_data/studies/4242/filler/f4999' '$WORK/list' && grep -q '^backend/config/configs.php' '$WORK/list' && grep -q '^webapp/own-task.html' '$WORK/list'"
check "backup is named after the data's version" sh -c "echo '$BACKUP' | grep -q 'v$OLD_VERSION.tar.gz'"
check "server is back after the backup" code_is "$BASE/esmira/api/app_version.php" 200
check "a response uploaded after the backup" submit_response "$BASE" e2e-after-backup
rm -f "$DIR/webapp/own-task.html"

echo; echo "== Restores that must be refused, and leave everything as it was"
( cd "$DIR" && echo nope > bad.tar.gz )
check "something that is no backup" sh -c "cd '$DIR' && ! ./restore.sh --yes bad.tar.gz"
head -c 20000 "$DIR/$BACKUP" > "$WORK/cut.tar.gz"
check "a backup cut off halfway" sh -c "cd '$DIR' && ! ./restore.sh --yes '$WORK/cut.tar.gz'"
# Lists fine, then fails while unpacking: as a full disk would, after the checks.
python3 - "$WORK/bad-extract.tar.gz" <<'PYTHON'
import io, sys, tarfile
with tarfile.open(sys.argv[1], "w:gz") as t:
    def add(name, data=b"x"):
        i = tarfile.TarInfo(name); i.size = len(data); t.addfile(i, io.BytesIO(data))
    add("esmira_data/VERSION", b"")
    add("esmira_data/.logins", b"x")
    add("backend/config/configs.php", b"<?php return ['dataFolder_path' => '/x/'];")
    link = tarfile.TarInfo("esmira_data/broken"); link.type = tarfile.LNKTYPE; link.linkname = "esmira_data/not-there"
    t.addfile(link)
PYTHON
check "a backup that fails while unpacking" sh -c "cd '$DIR' && ! ./restore.sh --yes --no-safety-backup '$WORK/bad-extract.tar.gz'"
up_again() { for _ in $(seq 1 20); do code_is "$BASE/esmira/api/app_version.php" 200 && return 0; sleep 1; done; return 1; }
check "after that the server is up" up_again
check "and its data is untouched" response_stored e2e-after-backup
check "and an admin can still log in" logs_in "$PASS"
check "and nothing was left behind" sh -c "! docker run --rm --entrypoint sh -v '$DIR:/kit' '$IMAGE_REF' -c 'ls -A /kit/data' | grep -q restore"
python3 - "$WORK/no-admin.tar.gz" <<'PYTHON'
import io, sys, tarfile
with tarfile.open(sys.argv[1], "w:gz") as t:
    def add(name, data=b"x"):
        i = tarfile.TarInfo(name); i.size = len(data); t.addfile(i, io.BytesIO(data))
    add("esmira_data/VERSION", b"")
    add("backend/config/configs.php", b"<?php return [];")
PYTHON
check "a backup that would open the first-run page" sh -c "cd '$DIR' && ! ./restore.sh --yes --no-safety-backup '$WORK/no-admin.tar.gz'"

echo; echo "== Restoring"
check "restore finishes" sh -c "cd '$DIR' && ./restore.sh --yes '$BACKUP'"
check "restore took a safety backup first" sh -c "ls '$DIR'/backups/safety-*.tar.gz"
check "the backed-up response is there" response_stored e2e-before-backup
later_response_gone() { ! response_stored e2e-after-backup; }
check "the later response is gone" later_response_gone
check "all 5000 files are back" sh -c "[ \$(docker compose --project-directory '$DIR' exec -T app sh -c 'ls /var/www/html/esmira_data/studies/4242/filler | wc -l' | tr -d '[:space:]') = 5000 ]"
check "own tasks are back" test -f "$DIR/webapp/own-task.html"
check "the admin can log in after the restore" logs_in "$PASS"
check "the web server is up again" code_is "$BASE/pwa/" 200
check "nothing was left behind" sh -c "! docker run --rm --entrypoint sh -v '$DIR:/kit' '$IMAGE_REF' -c 'ls -A /kit/data' | grep -q restore"
check "doctor passes" "$DIR/doctor.sh"

echo; echo "== The oldest backup survives its own restore"
rm -f "$DIR"/backups/iemabot-2*.tar.gz "$DIR"/backups/safety-*.tar.gz
( cd "$DIR" && sed -i.bak 's/^BACKUP_KEEP=.*/BACKUP_KEEP=2/' .env && rm -f .env.bak )
OLDEST="$(cd "$DIR" && ./backup.sh)"; sleep 1
( cd "$DIR" && ./backup.sh >/dev/null )
check "restoring it works" sh -c "cd '$DIR' && ./restore.sh --yes '$OLDEST'"
check "and it is still there" test -s "$DIR/$OLDEST"
( cd "$DIR" && sed -i.bak '/^BACKUP_KEEP=/d' .env && rm -f .env.bak && printf 'BACKUP_KEEP=\n' >> .env )
check "a blank BACKUP_KEEP deletes nothing recent" sh -c "cd '$DIR' && ./backup.sh >/dev/null && ls backups/iemabot-2*.tar.gz"

echo; echo "== A server stopped on purpose stays stopped"
( cd "$DIR" && docker compose stop app >/dev/null 2>&1; ./backup.sh >/dev/null 2>&1; ./backup.sh >/dev/null 2>&1 )
check "two nights of backups do not start it" test -z "$(cd "$DIR" && docker compose ps -q --status running app)"
( cd "$DIR" && docker compose up -d --wait app >/dev/null 2>&1 )

echo; echo "== A failed backup stops the update"
check "the server runs the old version" test "$(server_version)" = "$OLD_VERSION"
mv "$DIR/backups" "$DIR/backups.saved"; : > "$DIR/backups" # Whoever runs this, nothing can be written there now.
check "the update refuses" sh -c "cd '$DIR' && ! ./update.sh $NEW_VERSION"
rm -f "$DIR/backups"; mv "$DIR/backups.saved" "$DIR/backups"
check "the server still runs the old version" test "$(server_version)" = "$OLD_VERSION"
check "and .env still says so" env_says "IEMABOT_VERSION=$IEMABOT_VERSION"
check "and the data was not upgraded" sh -c "docker run --rm --entrypoint cat -v '$DIR/data/esmira_data:/d:ro' '$IMAGE_REF' /d/VERSION | grep -qx '$OLD_VERSION'"

echo; echo "== Updating to a new version, and going back"
UPDATE_OUTPUT="$WORK/update.txt"
check "an update with the same image changes nothing" sh -c "cd '$DIR' && ./update.sh --no-backup 2>&1 | grep -q 'Already on the newest'"
check "the update finishes" sh -c "cd '$DIR' && ./update.sh $NEW_VERSION > '$UPDATE_OUTPUT' 2>&1"
check "the server runs the new version" test "$(server_version)" = "$NEW_VERSION"
check "the backup carries the version that wrote its data" sh -c "ls '$DIR'/backups/iemabot-2*-v$OLD_VERSION.tar.gz"
check "the data survived" response_stored e2e-before-backup
check "doctor says the server stays on this version" sh -c "'$DIR/doctor.sh' | grep -q 'Staying on version $NEW_VERSION'"
check "an older version is refused now" sh -c "cd '$DIR' && ! ./update.sh $OLD_VERSION"
check "and the server still runs the new version" test "$(server_version)" = "$NEW_VERSION"
# shellcheck disable=SC2012 # our own file names
ROLLBACK="$(cd "$DIR" && ls -1t backups/iemabot-2*-v"$OLD_VERSION".tar.gz | head -n 1)"
check "the backup goes back" sh -c "cd '$DIR' && ./restore.sh --yes '$ROLLBACK'"
check "to the old version" test "$(server_version)" = "$OLD_VERSION"
check "and .env says so" env_says "IEMABOT_VERSION=$OLD_VERSION"
check "and the data's version too" sh -c "docker run --rm --entrypoint cat -v '$DIR/data/esmira_data:/d:ro' '$IMAGE_REF' /d/VERSION | grep -qx '$OLD_VERSION'"
check "doctor passes" "$DIR/doctor.sh"
printf '# changed by hand\n' >> "$DIR/README.md"
check "a forced update finishes" sh -c "cd '$DIR' && ./update.sh --force $OLD_VERSION"
check "and puts the kit back to what the image holds" cmp -s "$KIT/README.md" "$DIR/README.md"

if command -v flock >/dev/null 2>&1; then
  echo; echo "== One operation at a time"
  ( cd "$DIR" && flock .kit.lock sleep 25 ) &
  LOCK_HOLDER=$!
  sleep 2
  check "a backup waits its turn" sh -c "cd '$DIR' && ./backup.sh 2>&1 | grep -q 'Another backup, restore or update'"
  kill "$LOCK_HOLDER" 2>/dev/null; wait "$LOCK_HOLDER" 2>/dev/null
fi

if [ "${CI:-}" = "true" ]; then # Docker Desktop's file sharing fakes ownership, and this should not touch a developer's crontab.
  check "the web user owns the config" sh -c "docker compose --project-directory '$DIR' exec -T app stat -c %U /var/www/html/backend/config/configs.php | grep -qx www-data"
  check "other users on the machine cannot read the data" sh -c "stat -c %a '$DIR/data' | grep -qx 700"
  check "doctor warns while no nightly backup is scheduled" sh -c "cd '$DIR' && ./doctor.sh | grep -F 'WARN  No nightly backup is scheduled'"
  check "the nightly backup can be scheduled" sh -c "cd '$DIR' && ./backup.sh --schedule && ./backup.sh --schedule && [ \$(crontab -l | grep -c iemabot-backup) = 1 ]"
  check "doctor sees the nightly backup" sh -c "cd '$DIR' && ./doctor.sh | grep -F 'PASS  Nightly backup is scheduled'"
fi

COPY="$WORK/moved.tar.gz"
cp "$DIR/$ROLLBACK" "$COPY"
remove_install "$DIR"

echo; echo "== Moving to a new server, behind another web server"
DIR="$WORK/two"
PROXY="http://127.0.0.1:$PROXY_PORT"
proxy_get() { curl -sS --max-time 20 -H 'Host: localhost' -H 'X-Forwarded-Proto: https' "$@"; }
check "the installer restores the backup" env HTTP_BIND="127.0.0.1:$PROXY_PORT" HTTPS_BIND=127.0.0.1:18444 \
  "$KIT/install.sh" --yes --no-backup-schedule --dir "$DIR" --behind-proxy --domain localhost --email ops@example.org --restore "$COPY"
check "doctor passes" "$DIR/doctor.sh"
check "only this machine can reach it" sh -c "docker compose --project-directory '$DIR' port caddy 80 | grep -q '^127.0.0.1:'"
check "the study moved along" sh -c "curl -sS -H 'Host: localhost' -H 'X-Forwarded-Proto: https' '$PROXY/esmira/api/studies.php?access_key=demo' | jq -e '[.. | objects | select(.id? == 4242)] | length > 0'"
check "the response moved along" response_stored e2e-before-backup
check "the config follows the new address" config_says "wearablesRedirectUri=https://localhost/esmira/api/wearables_oauth.php"
check "the admin moved along" sh -c "curl -sS -H 'Host: localhost' -H 'X-Forwarded-Proto: https' -d 'accountName=$ADMIN' --data-urlencode 'pass=$PASS' '$PROXY/esmira/api/admin.php?type=login' | jq -e '.success == true'"
check "it trusts the web server's HTTPS (Secure cookie)" sh -c "curl -sS -D - -o /dev/null -H 'Host: localhost' -H 'X-Forwarded-Proto: https' -d 'accountName=$ADMIN' --data-urlencode 'pass=$PASS' '$PROXY/esmira/api/admin.php?type=login' | grep -i '^set-cookie:' | grep -qi secure"

echo
if [ "$FAIL" = 0 ]; then echo "SELF-HOST E2E OK"; else echo "SELF-HOST E2E FAILED"; fi
exit "$FAIL"
