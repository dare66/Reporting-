#!/usr/bin/env sh
# AIXBI - start everything with Docker (macOS / Linux). First run builds images and seeds demo data (5-15 min).
set -e
cd "$(dirname "$0")"
docker version >/dev/null 2>&1 || { echo "Docker is not running. Start Docker Desktop, then run this again."; exit 1; }
[ -f .env ] || cp .env.example .env
docker compose up -d --build
echo "Waiting for first-time setup (database + demo data)..."
docker compose wait setup >/dev/null 2>&1 || true
echo "AIXBI is running: http://localhost:${AIXBI_PORT:-8080}   (demo password: Demo@2026!)"
(command -v open >/dev/null && open "http://localhost:${AIXBI_PORT:-8080}") || (command -v xdg-open >/dev/null && xdg-open "http://localhost:${AIXBI_PORT:-8080}") || true
