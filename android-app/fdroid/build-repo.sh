#!/usr/bin/env bash
# Build the iEMAbot F-Droid repository from one signed APK (the deploy's release build).
#
#   build-repo.sh <app.apk> <out-dir>
#
# Writes <out-dir>/repo/ (index-v1.jar, index-v2.json, entry.jar, the APK, icons, and index.html with the
# "add this repo" link and QR code). The server serves it at <server>/fdroid/repo (deploy.yml), and participants add that
# URL once in the F-Droid app (or any client: Droid-ify, Neo Store, ...), which then offers every new deploy.
#
# Environment:
#   FDROID_REPO_URL           public URL of the repo, ending in /repo (required)
#   FDROID_KEYSTORE_PATH      keystore (.jks/.p12) that signs the index (required). Must be the same key on every
#   FDROID_KEYSTORE_PASSWORD  build: clients pin its fingerprint when the repo is added, and refuse an index signed
#   FDROID_KEY_ALIAS          by any other key.
#   FDROID_KEY_PASSWORD       (defaults to the keystore password)
#   ANDROID_HOME              optional; lets fdroidserver use the SDK's apksigner
#
# Needs fdroidserver on PATH (pip install fdroidserver) and keytool (any JDK).
# Prints the repo's signing-key fingerprint on the last line.
set -euo pipefail

APK="${1:?usage: build-repo.sh <app.apk> <out-dir>}"
OUT="${2:?usage: build-repo.sh <app.apk> <out-dir>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${FDROID_REPO_URL:?set FDROID_REPO_URL (ending in /repo)}"
: "${FDROID_KEYSTORE_PATH:?set FDROID_KEYSTORE_PATH}"
: "${FDROID_KEYSTORE_PASSWORD:?set FDROID_KEYSTORE_PASSWORD}"
: "${FDROID_KEY_ALIAS:?set FDROID_KEY_ALIAS}"
FDROID_KEY_PASSWORD="${FDROID_KEY_PASSWORD:-$FDROID_KEYSTORE_PASSWORD}"
case "$FDROID_REPO_URL" in */repo) ;; *) echo "FDROID_REPO_URL must end in /repo: $FDROID_REPO_URL" >&2; exit 1 ;; esac
[ -f "$APK" ] || { echo "No APK at $APK" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/repo" "$WORK/metadata"
cp "$HERE"/metadata/*.yml "$WORK/metadata/"
cp "$APK" "$WORK/repo/"
cp "$(realpath "$FDROID_KEYSTORE_PATH")" "$WORK/keystore"

# Repo icon: the app's launcher icon (fdroid copies it into repo/icons/).
ICON="$HERE/../android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png"
[ -f "$ICON" ] && cp "$ICON" "$WORK/iemabot.png"

# App icon on the F-Droid listing. fdroid extracts none from the release APK (its log has no icon step and the repo
# gets no icons-*/ files), so the client showed its placeholder robot. An icon.png in metadata/<appid>/en-US/ is
# used instead (index-v2 metadata.icon): 512x512 is what F-Droid asks for, so the PWA's icon, the same artwork.
APP_ICON="$HERE/../../web-pwa/public/pwa-512x512.png"
[ -f "$APP_ICON" ] || APP_ICON="$ICON"
for yml in "$WORK"/metadata/*.yml; do
	mkdir -p "${yml%.yml}/en-US"
	cp "$APP_ICON" "${yml%.yml}/en-US/icon.png"
done

# fdroidserver reads secrets from the environment when config.yml names them ({env: VAR}), so they are never
# written to disk. YAML-quoted with JSON escaping, which YAML accepts.
q() { python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"; }
{
	echo "repo_url: $(q "$FDROID_REPO_URL")"
	echo 'repo_name: "iEMAbot"'
	echo 'repo_description: "The iEMAbot participant app, updated with every deploy of the iEMAbot server."'
	[ -f "$WORK/iemabot.png" ] && echo 'repo_icon: "iemabot.png"'
	echo 'archive_older: 0'
	echo 'make_current_version_link: false'
	echo 'keystore: keystore'
	echo "repo_keyalias: $(q "$FDROID_KEY_ALIAS")"
	echo 'keystorepass: {env: FDROID_KEYSTORE_PASSWORD}'
	echo 'keypass: {env: FDROID_KEY_PASSWORD}'
	[ -n "${ANDROID_HOME:-}" ] && echo "sdk_path: $(q "$ANDROID_HOME")"
} > "$WORK/config.yml"
chmod 0600 "$WORK/config.yml" "$WORK/keystore"
export FDROID_KEYSTORE_PASSWORD FDROID_KEY_PASSWORD

(cd "$WORK" && fdroid update --verbose)

[ -f "$WORK/repo/index-v1.jar" ] && [ -f "$WORK/repo/entry.jar" ] || { echo "fdroid update wrote no signed index" >&2; exit 1; }
# fdroid skips (with only a log line) an APK whose package has no metadata: an index without the app is a failure.
python3 - "$WORK/repo/index-v2.json" <<'PY'
import json, sys
packages = json.load(open(sys.argv[1])).get("packages", {})
if not any(p.get("versions") for p in packages.values()):
    sys.exit("The index lists no app: does the APK's package name match a file in android-app/fdroid/metadata/?")
no_icon = sorted(name for name, p in packages.items() if not p.get("metadata", {}).get("icon"))
if no_icon:
    sys.exit("No icon in the index for " + ", ".join(no_icon) + ": F-Droid would show its placeholder")
print("Index lists:", ", ".join(sorted(packages)))
PY
# Run logs (runner paths, tool versions): not for publishing.
rm -rf "$WORK/repo/status"

mkdir -p "$OUT"
rm -rf "$OUT/repo"
cp -r "$WORK/repo" "$OUT/repo"

# The fingerprint clients pin: SHA-256 of the signing certificate, hex without colons.
keytool -list -v -keystore "$WORK/keystore" -storepass "$FDROID_KEYSTORE_PASSWORD" -alias "$FDROID_KEY_ALIAS" \
	| sed -n 's/^[[:space:]]*SHA256:[[:space:]]*//p' | tr -d ':' | tr '[:lower:]' '[:upper:]'
