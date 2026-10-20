#!/usr/bin/env bash
set -uo pipefail

# Black-box smoke test of a running ESMira container. Exits non-zero if any check fails.
# Usage: smoke.sh BASE_URL        (run bootstrap.sh first so the demo study exists)
# Env:   SMOKE_CONTAINER=name     also check cron and the stored CSV row inside that local container

BASE="${1:?usage: smoke.sh BASE_URL}"
BASE="${BASE%/}"
FAIL=0

check() { # check "name" command...
  local name="$1"; shift
  if "$@" >/dev/null 2>&1; then echo "PASS  $name"; else echo "FAIL  $name"; FAIL=1; fi
}
get() { curl -fsS --max-time 20 "$@"; }

check "admin UI served"            sh -c "curl -fsS --max-time 20 '$BASE/' | grep -qi '<html'"
check "PWA served at /pwa/"        sh -c "curl -fsS --max-time 20 '$BASE/pwa/' | grep -qi '<html'"
check "PWA service worker present" get -o /dev/null "$BASE/pwa/sw.js"
check "server reports ready"       sh -c "curl -fsS --max-time 20 '$BASE/api/studies.php?access_key=demo' | grep -vq 'not ready'"
check "demo study listed by access key" \
  sh -c "curl -fsS --max-time 20 '$BASE/api/studies.php?access_key=demo' | jq -e '[.. | objects | select(.id? == 4242)] | length > 0'"
check "PWA API path /esmira/api/ resolves (what participants hit)" \
  sh -c "curl -fsS --max-time 20 '$BASE/esmira/api/studies.php?access_key=demo' | jq -e '[.. | objects | select(.id? == 4242)] | length > 0'"
check "demo study has a questionnaire" \
  sh -c "curl -fsS --max-time 20 '$BASE/api/studies.php?access_key=demo' | jq -e '[.. | objects | select(has(\"pages\"))] | length > 0'"


submit_response() {
  local now; now="$(date +%s)"
  curl -fsS --max-time 20 -H 'Content-Type: application/json' "$BASE/api/datasets.php" -d "{
    \"userId\":\"smoke-$now\",\"appVersion\":\"smoke\",\"appType\":\"Web\",\"serverVersion\":12,
    \"dataset\":[{\"dataSetId\":$now,\"studyId\":4242,\"studyVersion\":1,\"studySubVersion\":0,\"studyLang\":\"en\",
      \"accessKey\":\"demo\",\"eventType\":\"questionnaire\",\"questionnaireName\":\"Daily check-in\",
      \"questionnaireInternalId\":424201,\"responseTime\":${now}000,\"timezone\":\"Etc/UTC\",\"group\":0,
      \"entryTime\":${now}000,\"responses\":{\"mood\":\"4\",\"note\":\"smoke\"}}]}" \
    | jq -e '.success == true and .dataset.states[0].success == true'
}
check "response accepted by datasets API" submit_response

if [ -n "${SMOKE_CONTAINER:-}" ]; then
  C="$SMOKE_CONTAINER"
  check "cron daemon running"           docker exec "$C" pgrep cron
  check "push + wearables cron jobs installed" \
    docker exec "$C" sh -c 'test -f /etc/cron.d/esmira-push && test -f /etc/cron.d/esmira-wearables'
  check "response persisted to CSV"     docker exec "$C" grep -q smoke /var/www/html/esmira_data/studies/4242/responses/424201.csv
  check "SQLite store has rows"         docker exec "$C" php -r '$d=new PDO("sqlite:/var/www/html/esmira_data/iemabot.sqlite"); exit($d->query("select count(*) from responses")->fetchColumn() > 0 ? 0 : 1);'
fi

echo
[ "$FAIL" = 0 ] && echo "SMOKE OK ($BASE)" || echo "SMOKE FAILED ($BASE)"
exit "$FAIL"
