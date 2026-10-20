#!/usr/bin/env bash
set -euo pipefail

# Canonical "ship" action for this fork: deploy to the VPS, then push origin so
# it mirrors exactly what is now live.
#
# This is deploy.sh's native deploy-THEN-push order (DEPLOY_PUSH=1) — the SAFE
# inverse of a "push triggers deploy" hook. Push→deploy is wrong here because
# deploy.sh (a) creates a `chore(release): vX.Y.Z` version-bump commit mid-run,
# so a push-triggered deploy would leave origin behind local (divergence the
# next deploy's own guard then aborts on), and (b) is a multi-minute VPN-gated
# remote Docker build that would hang/time out inside a git or Claude hook.
#
# Any DEPLOY_*/A11Y_* overrides pass straight through to deploy.sh, e.g.
#   DEPLOY_NO_BUMP=1 ./ship.sh          # ship without bumping the patch version
#   A11Y_RUN=1 ./ship.sh                # force the accessibility gate (default: only when web-pwa/ or locales changed)
#   A11Y_SKIP=1 ./ship.sh               # emergency: never run the accessibility gate
#
# Usage: ./ship.sh [HOST]               HOST defaults to surrey-vps (see deploy.sh).
cd "$(dirname "$0")"
export DEPLOY_PUSH=1
exec ./deploy.sh "$@"
