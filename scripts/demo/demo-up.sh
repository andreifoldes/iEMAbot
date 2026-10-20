#!/usr/bin/env bash
set -euo pipefail

# One-command local demo: build the fork's image, start it, create the admin and import the demo study.
#   scripts/demo/demo-up.sh          -> http://localhost:8080  (override with DEMO_PORT)
#   scripts/demo/demo-up.sh --down   -> stop and delete the demo containers AND volumes
# Set DEMO_REBUILD=1 to force `npm run build:all` even if dist/ already exists.

cd "$(dirname "$0")/../.."
COMPOSE=(docker compose -f docker-compose.demo.yml -p esmira-demo)
PORT="${DEMO_PORT:-8080}"

if [ "${1:-}" = "--down" ]; then
  "${COMPOSE[@]}" down -v
  exit 0
fi

if [ "${DEMO_REBUILD:-0}" = "1" ] || [ ! -d dist/pwa ]; then
  echo "== Building dist/ (npm run build:all)"
  npm ci
  npm run build:all
fi

echo "== Building and starting the container"
DEMO_PORT="$PORT" "${COMPOSE[@]}" up -d --build

echo "== Waiting for Apache on :$PORT"
for _ in $(seq 1 60); do
  curl -fsS -o /dev/null "http://localhost:$PORT/api/admin.php?type=InitESMiraPrep" && break
  sleep 2
done

export DEMO_PASS="${DEMO_PASS:-$(openssl rand -hex 12)}"
scripts/demo/bootstrap.sh "http://localhost:$PORT"
SMOKE_CONTAINER=esmira-demo-esmira-1 scripts/demo/smoke.sh "http://localhost:$PORT"
scripts/demo/ui-smoke.sh "http://localhost:$PORT"
echo
echo "Admin UI : http://localhost:$PORT/"
echo "Study    : http://localhost:$PORT/pwa/  (access key: demo)"
