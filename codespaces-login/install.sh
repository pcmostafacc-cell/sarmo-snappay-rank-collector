#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update
sudo apt-get install -y --no-install-recommends   xvfb xauth x11vnc novnc websockify openbox nginx-light fonts-dejavu-core
npm install --no-audit --no-fund --package-lock=false
npx playwright install --with-deps chromium
