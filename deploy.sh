#!/usr/bin/env bash
set -euo pipefail

# Deploy ESMira (this fork — incl. the web-push backend) to a VPS.
#
# Usage: ./deploy.sh [HOST]      HOST defaults to surrey-vps.
#
# Unlike the upstream docker-compose (which pulls jodlidev/esmira:latest), this
# builds an image FROM THIS REPO's Dockerfile on the server, so our backend
# (api/push_*.php, backend/notifications/*, cli/*, composer web-push, cron) and
# the participant PWA are what actually run.
#
# Safe by design: data/config live in bind-mounted volumes that are never
# touched; the server docker-compose.yml is backed up before its image line is
# repointed. Rollback = restore the .bak compose and `docker compose up -d`.
#
# Deploy guard: every successful deploy records its exact commit in
# $REMOTE_DIR/DEPLOYED_SHA on the server. Before building, we read it back and
# REFUSE to ship unless that live commit is an ancestor of HEAD (i.e. we are
# strictly ahead of live) — this catches "shipping a stale local while live is
# ahead", the exact regression a bare version number can't see. Each deploy also
# auto-bumps package.json's PATCH so dist/VERSION + the injected PACKAGE_VERSION
# advance (PWA/bundle cache-bust + a visible "what's live" marker); minor/major
# stay reserved for intentional releases.
#
# Env overrides: DEPLOY_SKIP_GUARD=1 (bypass ancestry check — emergencies),
# DEPLOY_ALLOW_DIRTY=1 (ship an uncommitted tree), DEPLOY_NO_BUMP=1 (don't bump),
# DEPLOY_PUSH=1 (git push HEAD to origin after a successful deploy).

HOST="${1:-surrey-vps}"
case "$HOST" in
  surrey-vps|rp-vms-iema-01)
    HOST="surrey-vps"
    REMOTE_DIR="/home/tf0011/esmira"
    ;;
  iema-ts)
    # Same VPS reached over Tailscale (ssh alias in ~/.ssh/config) — works
    # without the Surrey VPN. Same remote layout as surrey-vps.
    REMOTE_DIR="/home/tf0011/esmira"
    ;;
  *)
    echo "Unknown host: $HOST" >&2
    echo "Valid hosts: surrey-vps, iema-ts" >&2
    exit 1
    ;;
esac

IMAGE="esmira-fork:latest"
CONTAINER_SVC="esmira"

# ── Heal iCloud-induced git corruption BEFORE the guard/build/push ────────────
# This repo lives under iCloud, which writes " 2"/" 3" conflict-copies into .git
# (breaking fetch/fsck) and the worktree (dirtying the guard). git-heal quarantines
# them and re-fetches any missing objects; it exits non-zero if it can't make the
# repo healthy, so we abort rather than ship a broken repo. Skip with HEAL_SKIP=1.
if [ "${HEAL_SKIP:-0}" = "1" ]; then
  echo "=== git-heal SKIPPED (HEAL_SKIP=1) ==="
else
  "$(cd "$(dirname "$0")" && pwd)/git-heal.sh" \
    || { echo "ABORT: git-heal could not make the repository healthy (see above)." >&2; exit 1; }
fi

# ── Deploy guard: refuse to ship if live is ahead of us ──────────────────────
if [ "${DEPLOY_SKIP_GUARD:-0}" = "1" ]; then
  echo "=== Deploy guard SKIPPED (DEPLOY_SKIP_GUARD=1) ==="
else
  echo "=== Deploy guard: verifying live is not ahead of HEAD ==="
  git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    || { echo "ABORT: not inside a git repo." >&2; exit 1; }

  # HEAD must represent exactly what we ship, so the recorded SHA is meaningful.
  # (The auto version bump below is the only change we make to a clean tree.)
  if [ -n "$(git status --porcelain)" ] && [ "${DEPLOY_ALLOW_DIRTY:-0}" != "1" ]; then
    echo "ABORT: working tree has uncommitted changes." >&2
    echo "       Commit them first (deploy ships committed code), or set DEPLOY_ALLOW_DIRTY=1." >&2
    exit 1
  fi

  git fetch -q origin || echo "    (warning: git fetch failed; comparing against local refs)"

  # Fail fast if the box is unreachable (VPN down) — before the local build.
  if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" true 2>/dev/null; then
    echo "ABORT: cannot reach $HOST over SSH (VPN down?). Connect and retry." >&2
    exit 1
  fi

  LIVE_SHA="$(ssh "$HOST" "cat '$REMOTE_DIR/DEPLOYED_SHA' 2>/dev/null" || true)"
  HEAD_SHA="$(git rev-parse HEAD)"
  if [ -z "$LIVE_SHA" ]; then
    # Bootstrap: no server record yet. Fall back to origin/main as the presumed
    # live line so this first guarded deploy is still protected from divergence.
    echo "    no DEPLOYED_SHA on $HOST yet (first guarded deploy) — will record after this run."
    if git rev-parse -q --verify origin/main >/dev/null 2>&1; then
      ORIGIN_SHA="$(git rev-parse origin/main)"
      if ! git merge-base --is-ancestor "$ORIGIN_SHA" "$HEAD_SHA"; then
        echo "ABORT: origin/main ($ORIGIN_SHA) is not an ancestor of HEAD ($HEAD_SHA)." >&2
        echo "       You are behind the shared branch. git fetch && git rebase origin/main, then retry." >&2
        exit 1
      fi
      echo "    bootstrap check ok: HEAD is ahead of origin/main."
    fi
  elif ! git cat-file -e "${LIVE_SHA}^{commit}" 2>/dev/null; then
    echo "ABORT: live commit $LIVE_SHA is not in your local history." >&2
    echo "       Run 'git fetch' (and pull the branch it is on), then retry." >&2
    exit 1
  elif ! git merge-base --is-ancestor "$LIVE_SHA" "$HEAD_SHA"; then
    echo "ABORT: live is running $LIVE_SHA, which is NOT an ancestor of HEAD ($HEAD_SHA)." >&2
    echo "       Someone shipped newer work. Integrate it first, e.g.:" >&2
    echo "         git fetch && git rebase origin/main    # or merge" >&2
    echo "       then retry the deploy." >&2
    exit 1
  else
    echo "    ok: live $LIVE_SHA is behind HEAD $HEAD_SHA — safe to ship."
  fi
fi

# ── Auto patch-bump so every deploy advances dist/VERSION + PACKAGE_VERSION ───
# Committed only AFTER the build + a11y gate pass (below), so a failed build
# never leaves a release commit behind. Skip with DEPLOY_NO_BUMP=1.
if [ "${DEPLOY_NO_BUMP:-0}" = "1" ]; then
  NEW_VERSION="$(node -p "require('./package.json').version")"
  echo "=== Version bump SKIPPED (DEPLOY_NO_BUMP=1) — staying at v$NEW_VERSION ==="
else
  NEW_VERSION="$(npm version patch --no-git-tag-version | tr -d 'v')"
  echo "=== Version bumped to v$NEW_VERSION (package.json, uncommitted for now) ==="
fi

echo "=== Building locally (webpack dist/ + participant PWA) ==="
# `prod` cleans dist/ and copies backend/api/cli/locales into it; build:pwa must
# run after (it writes dist/pwa). build:all does both in the right order.
npm run build:all

# ── Accessibility gate (hard, automatic) ─────────────────────────────────────
# Audit the participant PWA we just built against WCAG 2.2 A/AA (axe-core driving
# the real build through every question type in light + dark). A critical or
# serious violation aborts the deploy (set -e). Runs fully offline against a
# fixture study, so no VPN/server is needed; tune severities with A11Y_FAIL_ON
# (default critical,serious).
#
# The audit takes minutes and only tests the participant PWA, so by default it runs
# only when something that can change what participants see has changed since the
# live commit (web-pwa/, src/locales/). Backend-only deploys skip it.
#   A11Y_RUN=1   always run it            A11Y_SKIP=1   never run it (emergency)
# With no known live commit to compare against it always runs.
A11Y_PATHS="web-pwa src/locales"
if [ "${A11Y_SKIP:-0}" = "1" ]; then
  RUN_A11Y=0; A11Y_WHY="A11Y_SKIP=1"
elif [ "${A11Y_RUN:-0}" = "1" ]; then
  RUN_A11Y=1; A11Y_WHY="A11Y_RUN=1"
elif [ -z "${LIVE_SHA:-}" ] || ! git cat-file -e "${LIVE_SHA}^{commit}" 2>/dev/null; then
  RUN_A11Y=1; A11Y_WHY="no known live commit to compare against"
elif [ -n "$(git diff --name-only "$LIVE_SHA" HEAD -- $A11Y_PATHS)" ]; then
  RUN_A11Y=1; A11Y_WHY="PWA files changed since live ${LIVE_SHA:0:7}"
else
  RUN_A11Y=0; A11Y_WHY="no changes under $A11Y_PATHS since live ${LIVE_SHA:0:7}"
fi
if [ "$RUN_A11Y" = "0" ]; then
  echo "=== Accessibility gate SKIPPED ($A11Y_WHY; A11Y_RUN=1 forces it) ==="
else
  echo "=== Accessibility gate (WCAG 2.2, participant PWA): $A11Y_WHY ==="
  ( cd web-pwa/a11y \
      && { [ -d node_modules ] || npm install --no-audit --no-fund; } \
      && node audit.mjs )
  echo "    accessibility gate passed"
fi

# Build + a11y gate passed — commit the version bump so HEAD (recorded on the
# server after deploy) matches the version we are about to ship. Only the
# version files are committed; any other tree state is left untouched.
if [ "${DEPLOY_NO_BUMP:-0}" != "1" ] && ! git diff --quiet -- package.json; then
  git add -- package.json
  [ -f package-lock.json ] && git add -- package-lock.json || true
  git commit -q -m "chore(release): v$NEW_VERSION"
  echo "=== Committed chore(release): v$NEW_VERSION ==="
fi

echo "=== Syncing Docker build context to $HOST:$REMOTE_DIR/build ==="
ssh "$HOST" "mkdir -p '$REMOTE_DIR/build'"
rsync -avz --delete \
  ./dist ./Dockerfile ./docker-entrypoint.sh \
  "$HOST:$REMOTE_DIR/build/"

echo "=== Syncing participant PWA into the bind-mounted pwa volume ==="
# The compose mounts ./esmira/pwa -> /var/www/html/pwa, so keep it current too.
rsync -avz --delete ./dist/pwa/ "$HOST:$REMOTE_DIR/esmira/pwa/"

echo "=== Building image on $HOST (composer install + cron baked in) ==="
ssh "$HOST" "cd '$REMOTE_DIR/build' && docker build -t '$IMAGE' ."

echo "=== Pointing docker-compose at the locally-built image (idempotent) ==="
ssh "$HOST" "cd '$REMOTE_DIR' \
  && if grep -q 'jodlidev/esmira:latest' docker-compose.yml; then \
       cp docker-compose.yml \"docker-compose.yml.bak.\$(date +%Y%m%d-%H%M%S)\"; \
       sed -i 's#image: jodlidev/esmira:latest#image: $IMAGE#' docker-compose.yml; \
       echo 'compose image repointed to $IMAGE (backup written)'; \
     else \
       echo 'compose already using a custom image'; \
     fi"

echo "=== Restarting via docker compose (data/config volumes preserved) ==="
ssh "$HOST" "cd '$REMOTE_DIR' && docker compose up -d"

echo "=== Ensuring a VAPID keypair exists (idempotent) ==="
ssh "$HOST" "cd '$REMOTE_DIR' && docker compose exec -T '$CONTAINER_SVC' php /var/www/html/cli/generate_vapid.php || true"

echo ""
echo "=== Container status + recent push-sender log ==="
ssh "$HOST" "cd '$REMOTE_DIR' && docker compose ps; echo '--- push log ---'; docker compose exec -T '$CONTAINER_SVC' sh -c 'tail -n 10 /var/log/esmira_push.log 2>/dev/null' || true"

echo ""
echo "=== Recording deployed commit on $HOST (the guard reads this next time) ==="
DEPLOYED_SHA="$(git rev-parse HEAD)"
printf '%s\n' "$DEPLOYED_SHA" | ssh "$HOST" "cat > '$REMOTE_DIR/DEPLOYED_SHA'"
echo "    live is now $DEPLOYED_SHA (v$NEW_VERSION)"

# Keep origin mirroring what's live so other machines don't diverge. Opt-in push
# (DEPLOY_PUSH=1) to respect the "push only when asked" norm; otherwise remind.
if [ "${DEPLOY_PUSH:-0}" = "1" ]; then
  git push -q origin HEAD && echo "    pushed HEAD to origin"
else
  echo "    reminder: 'git push origin HEAD' to sync origin with what is now live."
fi

echo ""
echo "=== Deploy complete (v$NEW_VERSION @ $DEPLOYED_SHA) ==="
