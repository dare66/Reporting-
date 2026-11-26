#!/usr/bin/env bash
# EMGS NetOps AI - start on Linux/macOS.  Usage: ./start_netops.sh [--mobile] [--dev] [--port 8750]
set -euo pipefail
cd "$(dirname "$0")"

PY="${PYTHON:-python3}"
command -v "$PY" >/dev/null 2>&1 || { echo "[netops] ERROR: Python 3.11+ is required (python3 not found)." >&2; exit 1; }
"$PY" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' || {
  echo "[netops] ERROR: Python 3.11+ is required (found $("$PY" -V 2>&1))." >&2; exit 1; }

if [ ! -x .venv/bin/python ]; then
  echo "[netops] Creating virtual environment in .venv ..."
  "$PY" -m venv .venv
fi
STAMP=.venv/.requirements.sha
SUM=$(sha256sum backend/requirements.txt 2>/dev/null | cut -d' ' -f1 || shasum -a 256 backend/requirements.txt | cut -d' ' -f1)
if [ ! -f "$STAMP" ] || [ "$(cat "$STAMP")" != "$SUM" ]; then
  echo "[netops] Installing Python dependencies ..."
  .venv/bin/python -m pip install --quiet --upgrade pip
  .venv/bin/python -m pip install --quiet -r backend/requirements.txt
  echo "$SUM" > "$STAMP"
fi

exec .venv/bin/python scripts/startup/netops.py start "$@"
