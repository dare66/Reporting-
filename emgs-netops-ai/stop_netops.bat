@echo off
REM EMGS NetOps AI - clean shutdown on Windows.
cd /d "%~dp0"
if exist ".venv\Scripts\python.exe" (".venv\Scripts\python.exe" scripts\startup\netops.py stop) else (python scripts\startup\netops.py stop)
