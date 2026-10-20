#!/usr/bin/env bash
set -euo pipefail

# Clean-room check on Azure: build this repo's image in a throwaway Azure Container Registry, run it on
# Azure Container Instances, bootstrap the demo study and run the smoke test against the public URL.
# Everything lives in ONE resource group created by this script, which is deleted on exit (KEEP=1 to keep it).
#
# Needs: `az login` (MFA fresh), providers Microsoft.ContainerRegistry + Microsoft.ContainerInstance
# registered, and a populated dist/ (npm run build:all). Docker is only needed for the push fallback.
# Env: AZ_LOCATION (default swedencentral - the Students subscription only allows
#      francecentral, spaincentral, swedencentral, denmarkeast, austriaeast), AZ_SUBSCRIPTION, KEEP=1

cd "$(dirname "$0")/../.."
LOCATION="${AZ_LOCATION:-swedencentral}"
SUFFIX="$(openssl rand -hex 3)"
RG="rg-esmira-verify-$SUFFIX"
ACR="esmiraverify$SUFFIX"
ACI="esmira-verify-$SUFFIX"
IMAGE="esmira-fork:demo"
ADMIN_PASS="$(openssl rand -hex 12)"

[ -d dist/pwa ] || { echo "dist/pwa missing - run: npm run build:all" >&2; exit 1; }
[ -z "${AZ_SUBSCRIPTION:-}" ] || az account set --subscription "$AZ_SUBSCRIPTION"
echo "== Subscription: $(az account show --query name -o tsv) | region: $LOCATION | group: $RG"

cleanup() {
  if [ "${KEEP:-0}" = "1" ]; then
    echo "== KEEP=1: leaving $RG in place. Delete with: az group delete -n $RG --yes"
  else
    echo "== Deleting resource group $RG"
    az group delete -n "$RG" --yes --no-wait >/dev/null 2>&1 || true
  fi
}

az group create -n "$RG" -l "$LOCATION" --tags purpose=esmira-verify -o none
trap cleanup EXIT

echo "== Creating registry $ACR"
az acr create -n "$ACR" -g "$RG" --sku Basic --admin-enabled true -o none
LOGIN_SERVER="$(az acr show -n "$ACR" --query loginServer -o tsv)"

echo "== Building image in ACR (ACR Tasks)"
if ! az acr build -r "$ACR" -t "$IMAGE" --platform linux/amd64 . ; then
  echo "== ACR Tasks unavailable; falling back to local build + push (linux/amd64)"
  az acr login -n "$ACR"
  docker buildx build --platform linux/amd64 -t "$LOGIN_SERVER/$IMAGE" --push .
fi

echo "== Starting container instance $ACI"
ACR_USER="$(az acr credential show -n "$ACR" --query username -o tsv)"
ACR_PASS="$(az acr credential show -n "$ACR" --query 'passwords[0].value' -o tsv)"
az container create -g "$RG" -n "$ACI" --image "$LOGIN_SERVER/$IMAGE" \
  --registry-login-server "$LOGIN_SERVER" --registry-username "$ACR_USER" --registry-password "$ACR_PASS" \
  --os-type Linux --cpu 1 --memory 1.5 --ports 80 --ip-address Public --dns-name-label "$ACI" \
  --environment-variables ESMIRA_PATH_ALIAS=/esmira --restart-policy Never -o none
FQDN="$(az container show -g "$RG" -n "$ACI" --query ipAddress.fqdn -o tsv)"
BASE="http://$FQDN"

echo "== Waiting for $BASE"
for _ in $(seq 1 60); do
  curl -fsS -o /dev/null --max-time 5 "$BASE/api/admin.php?type=InitESMiraPrep" && break
  sleep 5
done

DEMO_PASS="$ADMIN_PASS" scripts/demo/bootstrap.sh "$BASE"
STATUS=0
scripts/demo/smoke.sh "$BASE" || STATUS=$?
DEMO_PASS="$ADMIN_PASS" scripts/demo/ui-smoke.sh "$BASE" || STATUS=$?

echo "== Container state: $(az container show -g "$RG" -n "$ACI" --query instanceView.state -o tsv)"
az container logs -g "$RG" -n "$ACI" 2>&1 | tail -5 || true
[ "$STATUS" = 0 ] && echo "AZURE VERIFY OK ($BASE)" || echo "AZURE VERIFY FAILED ($BASE)"
exit "$STATUS"
