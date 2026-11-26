#!/usr/bin/env bash
# EMGS NetOps AI - clean shutdown on Linux/macOS.
set -euo pipefail
cd "$(dirname "$0")"
PYBIN=.venv/bin/python; [ -x "$PYBIN" ] || PYBIN=python3
exec "$PYBIN" scripts/startup/netops.py stop
