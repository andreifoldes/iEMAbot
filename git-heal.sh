#!/usr/bin/env bash
set -euo pipefail

# git-heal.sh — repair iCloud-induced git corruption before a ship/deploy.
#
# This repo lives under ~/Documents, which iCloud Drive syncs — including the
# .git directory. On cross-machine races iCloud writes "conflict copies" whose
# names gain a " 2"/" 3" suffix: e.g. `.git/index 5`, `.git/refs/stash 2`,
# `.git/AUTO_MERGE 2`, and `foo 2.mts` in the worktree. The .git ones make git
# ABORT ("fatal: bad object refs/… 2") on fetch/clone/fsck/bundle; the worktree
# ones dirty the tree so deploy.sh's clean-tree guard refuses to ship.
#
# Real git never uses a space+digits suffix, so these copies are always safe to
# remove. This script QUARANTINES them (moves to /tmp — never destroys), then
# verifies the object graph and re-fetches from origin if anything went missing.
# It exits NON-ZERO if the repo can't be made healthy, so the caller aborts
# instead of shipping a broken repo. The real cure is moving the working copy
# OUT of iCloud; this is the safety net until then.
#
# Env: HEAL_FETCH_REMOTE=origin   remote to backfill missing objects from
#      HEAL_NO_FETCH=1            don't fetch even if objects are missing

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "git-heal: not a git repo." >&2; exit 1; }
cd "$(git rev-parse --show-toplevel)"
GIT_DIR="$(git rev-parse --git-dir)"
REMOTE="${HEAL_FETCH_REMOTE:-origin}"
QUAR="/tmp/git-heal-quarantine-$(date +%Y%m%d-%H%M%S)"

echo "=== git-heal: scanning for iCloud conflict-copies ==="

# .git conflict-copies: always junk (git never space+digit-suffixes its files).
git_junk=()
while IFS= read -r -d '' f; do git_junk+=("$f"); done \
  < <(find "$GIT_DIR" \( -name '* [0-9]' -o -name '* [0-9].*' \) -type f -print0 2>/dev/null)

# Worktree conflict-copies: only UNTRACKED files whose non-conflict sibling also
# exists (so a legitimately-named file is never touched).
wt_junk=()
while IFS= read -r -d '' f; do
  if [[ "$f" =~ ^(.*)\ [0-9]+(\.[^./]+)?$ ]]; then
    sib="${BASH_REMATCH[1]}${BASH_REMATCH[2]}"
    [ -e "$sib" ] && wt_junk+=("$f")
  fi
done < <(git ls-files -o --exclude-standard -z 2>/dev/null)

total=$(( ${#git_junk[@]} + ${#wt_junk[@]} ))
if [ "$total" -gt 0 ]; then
  echo "--- quarantining $total conflict-copy file(s) → $QUAR ---"
  for f in ${git_junk[@]+"${git_junk[@]}"} ${wt_junk[@]+"${wt_junk[@]}"}; do
    rel="${f#./}"; dest="$QUAR/$rel"
    mkdir -p "$(dirname "$dest")"
    mv "$f" "$dest" && echo "    moved: $rel"
  done
else
  echo "--- none found ---"
fi

echo "=== git-heal: verifying object graph ==="
git rev-parse -q --verify HEAD >/dev/null || { echo "git-heal: HEAD does not resolve." >&2; exit 1; }

# `rev-list --objects HEAD` walks every commit + tree + blob — fails if any
# reachable object is missing. That is the authoritative health gate.
if ! git rev-list --objects HEAD >/dev/null 2>&1; then
  echo "--- reachable objects missing; backfilling from '$REMOTE' ---"
  if [ "${HEAL_NO_FETCH:-0}" != "1" ]; then git fetch --prune "$REMOTE" || true; fi
fi
if ! git rev-list --objects HEAD >/dev/null 2>&1; then
  echo "git-heal: FAILED — history from HEAD still can't be traversed after fetch." >&2
  echo "  Recover by cloning fresh from '$REMOTE' into a NON-iCloud folder (e.g. ~/Dev)." >&2
  exit 1
fi

echo "=== git-heal: OK — $(git rev-list --count HEAD) commits reachable from HEAD, object graph intact ==="
