@echo off
rem AIXBI - start everything with Docker Desktop (Windows). First run builds images and seeds demo data (5-15 min).
cd /d "%~dp0"
docker version >nul 2>&1 || (echo Docker Desktop is not running. Start it, then run this again. & pause & exit /b 1)
if not exist .env copy .env.example .env >nul
docker compose up -d --build || (echo Start failed - see the messages above. & pause & exit /b 1)
echo.
echo Waiting for first-time setup (database + demo data)...
docker compose wait setup >nul 2>&1
echo AIXBI is running: http://localhost:8080   (demo password: Demo@2026!)
start http://localhost:8080
pause
