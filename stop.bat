@echo off
cd /d "%~dp0"
docker compose down
echo AIXBI stopped. Your data is kept; run start.bat to continue.
pause
