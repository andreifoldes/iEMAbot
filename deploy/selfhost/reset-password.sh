#!/usr/bin/env bash
# Sets a new password for an account. There is no email reset, so this is how to get back into the dashboard.
#
#   ./reset-password.sh ACCOUNT            asks for the password; leave it empty for a random one
#   ... | ./reset-password.sh --stdin ACCOUNT
set -euo pipefail
cd "$(dirname "$0")"
. ./lib.sh
require_installed

FROM_STDIN=""
if [ "${1:-}" = "--stdin" ]; then FROM_STDIN=1; shift; fi
ACCOUNT="${1:-}"
[ -n "$ACCOUNT" ] || die "Usage: ./reset-password.sh ACCOUNT"

if [ -n "$FROM_STDIN" ]; then
  IFS= read -r password || true
  printf '%s\n' "$password" | app_php cli/selfhost.php set-password "$ACCOUNT"
  exit
fi

has_tty || die "Run this in a terminal, or pass the password with --stdin."
printf 'New password for %s (at least 12 characters; empty for a random one): ' "$ACCOUNT" > /dev/tty
IFS= read -rs password < /dev/tty
printf '\n' > /dev/tty
if [ -z "$password" ]; then
  password="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20)"
  say "Password: $password"
fi
printf '%s\n' "$password" | app_php cli/selfhost.php set-password "$ACCOUNT"
