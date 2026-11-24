#!/usr/bin/env bash
set -euo pipefail
test "${CODESPACES:-}" = true
umask 077
runtime="$(mktemp -d)"
# GitHub forwards the portal only. Internal services listen on loopback.
nohup flock --nonblock "${TMPDIR:-/tmp}/sarmo-codespaces-login.lock" \
  bash codespaces-login/serve.sh "$runtime" >"$runtime/status.log" 2>&1 </dev/null &
printf 'Private login portal: https://%s-6080.%s\n' "$CODESPACE_NAME" "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
