#!/usr/bin/env bash
# Build the Android app from the current PWA sources. Single entry point so the APK can never
# be assembled from a stale web bundle: PWA (native mode) -> cap sync -> Gradle.
# Usage: scripts/build.sh [debug|release]   (default: debug)
set -euo pipefail

VARIANT="${1:-debug}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"

export JAVA_HOME="${JAVA_HOME:-/opt/homebrew/opt/openjdk@21}"
export ANDROID_HOME="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-/opt/homebrew/share/android-commandlinetools}}"

# Server pre-filled on first run (editable). Override per build: ESMIRA_DEFAULT_SERVER=https://host
export VITE_DEFAULT_SERVER="${ESMIRA_DEFAULT_SERVER-https://iemabot.surrey.ac.uk}"

(cd "$ROOT/web-pwa" && npm ci --ignore-scripts && npx vite build --mode native)
[ "$VARIANT" = "debug" ] && export ESMIRA_DEBUG=1
(cd "$HERE" && npm ci --ignore-scripts && npx cap sync android)

TASK="assemble$(echo "${VARIANT:0:1}" | tr '[:lower:]' '[:upper:]')${VARIANT:1}"
(cd "$HERE/android" && ./gradlew "$TASK")
echo "APK: $(find "$HERE/android/app/build/outputs/apk" -name '*.apk' | head -1)"
