#!/usr/bin/env bash
# Start the test emulator (AVD created for this project).
# - Audio is disabled so read-aloud / voice features can be exercised during testing without sound.
# - Headless with software rendering by default: the windowed host-GPU emulator hung repeatedly
#   (QEMU CPU thread stalls). Set EMU_WINDOW=1 to get the window back.
set -euo pipefail
export ANDROID_HOME="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-/opt/homebrew/share/android-commandlinetools}}"

ARGS=(-avd "${1:-esmira_api35}" -no-audio -no-snapshot-load -no-boot-anim -netdelay none -netspeed full)
if [ "${EMU_WINDOW:-0}" = "1" ]; then
  ARGS+=(-gpu auto)
else
  ARGS+=(-no-window -gpu swiftshader_indirect)
fi
exec "$ANDROID_HOME/emulator/emulator" "${ARGS[@]}"
