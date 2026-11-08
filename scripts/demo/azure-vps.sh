#!/usr/bin/env bash
# A real lab's setup on a throwaway Azure VM: Ubuntu, the installer doing everything including Docker, a real HTTPS
# certificate on Azure's free hostname (no domain to buy). Everything is in one resource group; `down` deletes all of it.
#
#   scripts/demo/azure-vps.sh up IMAGE:TAG     IMAGE:TAG is built here and must match the VM (arm64 by default)
#   scripts/demo/azure-vps.sh ssh              a shell on the VM
#   scripts/demo/azure-vps.sh down             delete the VM and everything with it
#
# Env: AZ_SUBSCRIPTION (required: never the default, so that it cannot land in the wrong one), AZ_LOCATION (swedencentral),
#      AZ_VM_SIZE (Standard_B2pts_v2: arm64, 1 GB, the guide's stated minimum), AZ_IMAGE (matching Ubuntu image)
# The VM also shuts itself down every night at 23:00 UTC, in case `down` gets forgotten.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
STATE="${AZ_VPS_STATE:-$HOME/.iemabot-azure-vps}"
LOCATION="${AZ_LOCATION:-swedencentral}"
SIZE="${AZ_VM_SIZE:-Standard_B2pts_v2}"
OS_IMAGE="${AZ_IMAGE:-Canonical:ubuntu-24_04-lts:server-arm64:latest}"
VM_USER=labadmin
: "${AZ_SUBSCRIPTION:?Set AZ_SUBSCRIPTION to the subscription ID to use}"

az_() { az "$@" --subscription "$AZ_SUBSCRIPTION"; }
say() { printf '\n== %s\n' "$*" >&2; }

load_state() { [ -f "$STATE/state" ] || { echo "No VM known in $STATE. Run: up" >&2; exit 1; }; . "$STATE/state"; }
ssh_() { ssh -i "$STATE/key" -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$STATE/known_hosts" -o ConnectTimeout=20 "$VM_USER@$FQDN" "$@"; }

up() {
  local image="${1:?usage: azure-vps.sh up IMAGE:TAG}" suffix group my_ip password
  docker image inspect "$image" >/dev/null || { echo "No image $image here." >&2; exit 1; }
  [ ! -f "$STATE/state" ] || { echo "A VM already exists ($STATE/state). Run: down" >&2; exit 1; }
  mkdir -p "$STATE"; chmod 700 "$STATE"
  suffix="$(openssl rand -hex 3)"
  group="rg-iemabot-vps-$suffix"
  ssh-keygen -q -t ed25519 -N '' -f "$STATE/key"
  my_ip="$(curl -4 -fsS --max-time 10 https://api.ipify.org)"

  say "Creating the VM ($SIZE in $LOCATION, group $group)"
  az_ group create -n "$group" -l "$LOCATION" -o none
  printf 'GROUP=%s\n' "$group" > "$STATE/state" # From here on `down` can clean up, whatever happens next.
  FQDN="$(az_ vm create -g "$group" -n iemabot --image "$OS_IMAGE" --size "$SIZE" --admin-username "$VM_USER" \
    --ssh-key-values "$STATE/key.pub" --public-ip-sku Standard --public-ip-address-dns-name "iemabot-$suffix" \
    --nsg-rule NONE --query fqdns -o tsv)"
  printf 'FQDN=%s\n' "$FQDN" >> "$STATE/state"
  az_ network nsg rule create -g "$group" --nsg-name iemabotNSG -n web --priority 100 --destination-port-ranges 80 443 --access Allow --protocol '*' -o none
  az_ network nsg rule create -g "$group" --nsg-name iemabotNSG -n ssh-from-here --priority 110 --destination-port-ranges 22 --source-address-prefixes "$my_ip" --access Allow --protocol Tcp -o none
  az_ vm auto-shutdown -g "$group" -n iemabot --time 2300 -o none

  say "Waiting for $FQDN"
  for _ in $(seq 1 40); do ssh_ true 2>/dev/null && break; sleep 5; done
  ssh_ 'cloud-init status --wait >/dev/null 2>&1 || true'

  say "Copying the kit"
  tar czf - -C "$ROOT/deploy" selfhost | ssh_ 'tar xzf - && chmod +x selfhost/*.sh'

  # What a lab sees: the installer finds no Docker, installs it, and asks to log in again.
  say "First run of the installer: it installs Docker"
  ssh_ 'selfhost/install.sh --yes --domain '"$FQDN"' --email admin@example.org --admin labadmin'

  say "Copying the image (it is not published yet; a lab would download it)"
  docker save "$image" | gzip -1 | ssh_ 'gunzip | sudo docker load'

  say "Second run of the installer: certificate, admin account, checks"
  password="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9')"
  printf '%s\n' "$password" > "$STATE/admin-password"; chmod 600 "$STATE/admin-password"
  ssh_ "IEMABOT_IMAGE='${image%:*}' IEMABOT_VERSION='${image##*:}' IEMABOT_PULL=never IEMABOT_ADMIN_PASSWORD='$password' selfhost/install.sh --yes --domain '$FQDN' --email admin@example.org --admin labadmin"

  say "Importing the demo study"
  DEMO_ADMIN=labadmin DEMO_PASS="$password" "$ROOT/scripts/demo/bootstrap.sh" "https://$FQDN/esmira"

  say "Running the journey from outside, with the real certificate"
  python3 "$ROOT/scripts/demo/lab-journey.py" "https://$FQDN" --user labadmin --password "$password"

  cat >&2 <<EOF

Participant app:  https://$FQDN/pwa/?key=demo
Dashboard:        https://$FQDN/esmira/#admin   (labadmin, password in $STATE/admin-password)
Delete it all:    AZ_SUBSCRIPTION=$AZ_SUBSCRIPTION AZ_VPS_STATE=$STATE scripts/demo/azure-vps.sh down
EOF
}

down() {
  load_state
  say "Deleting resource group $GROUP"
  az_ group delete -n "$GROUP" --yes
  rm -rf "$STATE"
}

case "${1:-}" in
  up) shift; up "$@" ;;
  down) down ;;
  ssh) load_state; shift; ssh_ "$@" ;;
  *) echo "usage: azure-vps.sh up IMAGE:TAG | ssh [COMMAND] | down" >&2; exit 2 ;;
esac
