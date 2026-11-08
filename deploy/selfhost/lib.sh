# shellcheck shell=bash
# Shared by the kit's scripts. Sourced, not run.

# The kit's files, for install.sh when it runs through a pipe. From then on the kit comes out of the image.
KIT_FILES=(README.md backup.sh caddy/Caddyfile caddy/Caddyfile.behind-proxy caddy/routes.caddy compose.yml
  doctor.sh env.example install.sh lib.sh reset-password.sh restore.sh update.sh)
START_TIMEOUT=900

say()  { printf '%s\n' "$*" >&2; }
step() { printf '\n== %s\n' "$*" >&2; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# /dev/tty exists without a terminal too (for example under `ssh host command`): only opening it tells.
has_tty() { { : > /dev/tty; } 2>/dev/null; }

env_get() { # env_get KEY [default]: a value from .env
  local line
  line="$(grep -E "^$1=" .env 2>/dev/null | tail -n 1)" || true
  if [ -n "$line" ]; then printf '%s' "${line#*=}"; else printf '%s' "${2:-}"; fi
}

env_set() { # env_set KEY VALUE: replaces the line or adds it, keeping the file private
  local tmp
  tmp="$(mktemp .env.XXXXXX)"
  grep -vE "^$1=" .env > "$tmp" 2>/dev/null || true
  printf '%s=%s\n' "$1" "$2" >> "$tmp"
  chmod 600 "$tmp"
  mv "$tmp" .env
}

require_installed() {
  [ -f .env ] && [ -f compose.yml ] || die "Run this from the folder iEMAbot was installed in (no .env or compose.yml here)."
  docker info >/dev/null 2>&1 || die "Cannot reach Docker. Is it running, and may this user use it?"
  # .env decides. Compose would prefer these from the environment, which would hide a version set by update or restore.
  unset IEMABOT_IMAGE IEMABOT_VERSION
}

take_lock() { # Only one of backup, restore and update at a time. They call each other, so a held lock is passed on.
  [ -z "${IEMABOT_LOCK_HELD:-}" ] || return 0
  command -v flock >/dev/null 2>&1 || return 0
  exec 9> .kit.lock
  flock -n 9 || die "Another backup, restore or update is running. Try again when it has finished."
  export IEMABOT_LOCK_HELD=1
}

version_lt() { # version_lt A B: A is an older version than B
  [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n 1)" = "$1" ]
}

image_ref() { printf '%s:%s' "$(env_get IEMABOT_IMAGE ghcr.io/andreifoldes/iemabot)" "${1:-$(env_get IEMABOT_VERSION latest)}"; }

fetch_image() { # fetch_image [VERSION]: gets it, unless IEMABOT_PULL=never asks to use what is on this machine
  if [ "${IEMABOT_PULL:-}" = "never" ]; then
    docker image inspect "$(image_ref "${1:-}")" >/dev/null 2>&1
  else
    docker pull --quiet "$(image_ref "${1:-}")" >/dev/null
  fi
}

in_image() { # in_image VERSION COMMAND...: runs a command in that image, without starting the server
  local version="$1"; shift
  docker run --rm --log-driver none --entrypoint "$1" "$(image_ref "$version")" "${@:2}"
}

in_kit() { # in_kit SCRIPT: runs a script as root in the configured image, with this folder at /kit and stdin passed on
  # No log driver: stdout can be a whole backup, which would otherwise take up the disk a second time in Docker's logs.
  docker run --rm -i --log-driver none --entrypoint sh -v "$PWD:/kit" "$(image_ref)" -c "$1"
}

image_version() { in_image "${1:-}" cat /var/www/html/VERSION 2>/dev/null | tr -d '[:space:]'; }

# The version that last wrote the data, which is not necessarily the image configured now.
data_version() { in_kit 'cat /kit/data/esmira_data/VERSION 2>/dev/null || true' | tr -d '[:space:]'; }

has_data() { in_kit 'test -e /kit/data/esmira_data/.logins || [ -n "$(find /kit/data/esmira_data/studies -mindepth 1 -print -quit 2>/dev/null)" ]'; }

app_php() { docker compose exec -T -u www-data app php "$@"; }

app_status() { # app_status KEY: one value from `cli/selfhost.php status`
  app_php cli/selfhost.php status 2>/dev/null | sed -n "s/^$1=//p"
}

start_app() { docker compose up -d --wait --wait-timeout "$START_TIMEOUT" app; }

wait_healthy() { # waits for the app's container, however it was started
  local status="" waited=0
  while [ "$waited" -lt "$START_TIMEOUT" ]; do
    status="$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q app)" 2>/dev/null || true)"
    case "$status" in
      healthy) return 0 ;;
      unhealthy) return 1 ;;
    esac
    sleep 3
    waited=$((waited + 3))
  done
  return 1
}

resume_app() { # The very container that was stopped: unlike `up`, this never moves to a newly configured image.
  docker compose start app >/dev/null 2>&1 && wait_healthy
}

sync_kit() { # sync_kit VERSION: takes the kit's files from that image, so that scripts and image always belong together
  local tmp file
  # shellcheck disable=SC2034 # read by update.sh
  KIT_CHANGED=""
  tmp="$(mktemp -d .kit-new.XXXXXX)" # Inside this folder, so that the moves below are real renames.
  if ! in_image "$1" tar -C /opt/iemabot-kit -cf - . 2>/dev/null | tar xf - --no-same-owner -C "$tmp" 2>/dev/null || [ ! -f "$tmp/lib.sh" ]; then
    rm -rf "$tmp"
    warn "This image holds no kit files; keeping the ones here."
    return 0
  fi
  mkdir -p caddy
  while IFS= read -r file; do
    file="${file#./}"
    if ! cmp -s "$tmp/$file" "$file"; then
      # shellcheck disable=SC2034 # read by update.sh
      KIT_CHANGED=1
      mv "$tmp/$file" "$file"
    fi
  done < <(cd "$tmp" && find . -type f)
  rm -rf "$tmp"
}

kit_url() { # kit_url REF: where the kit's files are published
  printf '%s' "${IEMABOT_KIT_URL:-https://raw.githubusercontent.com/${IEMABOT_REPO:-andreifoldes/iEMAbot}/$1/deploy/selfhost}"
}

fetch_kit() { # fetch_kit TARGET_DIR REF: all of the kit's files, or fails
  local file
  mkdir -p "$1/caddy"
  for file in "${KIT_FILES[@]}"; do
    curl -fsSL --retry 2 -o "$1/$file" "$(kit_url "$2")/$file" || { warn "Could not download $file from $(kit_url "$2")"; return 1; }
  done
  chmod +x "$1"/*.sh
}
