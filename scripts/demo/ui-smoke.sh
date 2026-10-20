#!/usr/bin/env bash
set -uo pipefail

# Runs ui-smoke.py (browser test of the participant PWA AND the researcher dashboard) if Playwright is available.
# Usage: DEMO_PASS=... ui-smoke.sh BASE_URL
# Set REQUIRE_UI=1 to fail instead of skipping when Playwright is missing.

if ! python3 -c 'import playwright' 2>/dev/null; then
  echo "SKIP  browser UI smoke (pip install playwright && playwright install chromium)"
  [ "${REQUIRE_UI:-0}" = "1" ] && exit 1
  exit 0
fi
exec python3 "$(dirname "$0")/ui-smoke.py" "$@"
