#!/usr/bin/env sh
cd "$(dirname "$0")" && docker compose down && echo "AIXBI stopped. Your data is kept; run ./start.sh to continue."
