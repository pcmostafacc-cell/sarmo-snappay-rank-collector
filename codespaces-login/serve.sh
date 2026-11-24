#!/usr/bin/env bash
set -euo pipefail
runtime="$1"
export DISPLAY=:96
export XAUTHORITY="$runtime/xauth"
export SARMO_LOGIN_ORIGIN="https://$CODESPACE_NAME-6080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
[[ "$SARMO_LOGIN_ORIGIN" =~ ^https://[a-z0-9-]+-6080\.app\.github\.dev$ ]]
touch "$XAUTHORITY"
xauth add "$DISPLAY" . "$(mcookie)"
children=()
cleanup() {
  if (("${#children[@]}")); then kill "${children[@]}" 2>/dev/null || true; fi
  rm -rf -- "$runtime"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
Xvfb "$DISPLAY" -auth "$XAUTHORITY" -screen 0 1280x800x24 -nolisten tcp & children+=("$!")
for attempt in {1..40}; do
  [[ -S /tmp/.X11-unix/X96 ]] && break
  sleep .25
done
[[ -S /tmp/.X11-unix/X96 ]]
openbox & children+=("$!")
x11vnc -display "$DISPLAY" -auth "$XAUTHORITY" -localhost -rfbport 5966 -forever -nopw -noshm & children+=("$!")
websockify --web /usr/share/novnc 127.0.0.1:6082 127.0.0.1:5966 & children+=("$!")
sed "s|PORTAL_ORIGIN|$SARMO_LOGIN_ORIGIN|g" codespaces-login/nginx.conf > "$runtime/nginx.conf"
nginx -c "$runtime/nginx.conf" -p "$runtime/" -g 'daemon off;' & children+=("$!")
node codespaces-login/server.mjs & children+=("$!")
wait -n "${children[@]}"
