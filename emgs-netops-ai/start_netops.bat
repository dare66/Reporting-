@echo off
REM EMGS NetOps AI - start on Windows.  Usage: start_netops.bat [--mobile] [--dev] [--port 8750]
setlocal
cd /d "%~dp0"

where py >nul 2>nul && (set "PY=py -3") || (set "PY=python")
%PY% -c "import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)" >nul 2>nul
if errorlevel 1 (
  echo [netops] ERROR: Python 3.11 or newer is required. Install it from python.org and tick "Add to PATH".
  exit /b 1
)

if not exist ".venv\Scripts\python.exe" (
  echo [netops] Creating virtual environment in .venv ...
  %PY% -m venv .venv || (echo [netops] ERROR: could not create .venv & exit /b 1)
)

for /f "delims=" %%H in ('certutil -hashfile backend\requirements.txt SHA256 ^| findstr /v ":"') do set "SUM=%%H"
set "OLD="
if exist ".venv\.requirements.sha" set /p OLD=<.venv\.requirements.sha
if not "%SUM%"=="%OLD%" (
  echo [netops] Installing Python dependencies ...
  ".venv\Scripts\python.exe" -m pip install --quiet --upgrade pip
  ".venv\Scripts\python.exe" -m pip install --quiet -r backend\requirements.txt || (echo [netops] ERROR: pip install failed & exit /b 1)
  > ".venv\.requirements.sha" echo %SUM%
)

".venv\Scripts\python.exe" scripts\startup\netops.py start %*
endlocal
