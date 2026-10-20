#!/usr/bin/env bash
set -euo pipefail

# Idempotent first-run bootstrap of a fresh ESMira container over its real HTTP API:
#   1. InitESMira   - creates the admin account (skipped if the server is already initialised)
#   2. login        - session cookie
#   3. SaveStudy    - imports demo/demo-study.json (the same path the admin UI uses)
#
# Usage: bootstrap.sh BASE_URL        e.g. http://localhost:8080
# Env:   DEMO_ADMIN (default admin), DEMO_PASS (default: random, printed once)

BASE="${1:?usage: bootstrap.sh BASE_URL}"
BASE="${BASE%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ADMIN="${DEMO_ADMIN:-admin}"
PASS="${DEMO_PASS:-$(openssl rand -hex 12)}"
STUDY_ID=4242
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

api() { curl -fsS -c "$JAR" -b "$JAR" "$@"; }
json_get() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1], ""))' "$1"; }

# Server is initialised once GetPermissions answers without needing InitESMira.
PREP="$(api "$BASE/api/admin.php?type=InitESMiraPrep" || true)"
if [[ "$PREP" == *'"dirBase"'* ]]; then
  echo "== Initialising ESMira (admin: $ADMIN)"
  RESP="$(api -d "new_account=$ADMIN" --data-urlencode "pass=$PASS" \
    --data-urlencode "data_location=/var/www/html/" "$BASE/api/admin.php?type=InitESMira")"
  [ "$(echo "$RESP" | json_get success)" = "True" ] || { echo "InitESMira failed: $RESP" >&2; exit 1; }
  echo "   admin password: $PASS   (shown once)"
else
  echo "== Already initialised; logging in as $ADMIN"
  [ -n "${DEMO_PASS:-}" ] || { echo "Set DEMO_PASS to log in to an existing server" >&2; exit 1; }
  RESP="$(api -d "accountName=$ADMIN" --data-urlencode "pass=$PASS" "$BASE/api/admin.php?type=login")"
  [ "$(echo "$RESP" | json_get success)" = "True" ] || { echo "login failed: $RESP" >&2; exit 1; }
fi

# The admin UI runs legacy migrations (and crashes) on studies without serverVersion/packageVersion, so stamp the
# fixture with the live server's protocol version and this build's package version rather than hard-coding them.
SERVER_VERSION="$(echo "$RESP" | json_get serverVersion)"
PACKAGE_VERSION="$(jq -r .version "$ROOT/package.json")"
[ -n "$SERVER_VERSION" ] || { echo "Server did not report serverVersion" >&2; exit 1; }

# lastChanged is an optimistic-lock guard; a bootstrap/re-import should always win, so pass a far-future value.
echo "== Saving demo study $STUDY_ID"
RESP="$(jq --argjson sv "$SERVER_VERSION" --arg pv "$PACKAGE_VERSION" '._.serverVersion = $sv | ._.packageVersion = $pv' \
    "$ROOT/demo/demo-study.json" \
  | api -H 'Content-Type: application/json' --data-binary @- \
    "$BASE/api/admin.php?type=SaveStudy&study_id=$STUDY_ID&lastChanged=9999999999")"
[ "$(echo "$RESP" | json_get success)" = "True" ] || { echo "SaveStudy failed: $RESP" >&2; exit 1; }
echo "   done. Access key: demo"
