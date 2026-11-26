#!/usr/bin/env python3
"""Create a consistent online backup of the local database (safe while the app runs).

Schedule it with Task Scheduler (Windows) or cron (Linux), e.g. daily:
  0 18 * * *  /path/to/emgs-netops-ai/.venv/bin/python /path/to/emgs-netops-ai/scripts/backup/backup_netops.py
"""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "backend"))
from app.database.migrations import run_migrations  # noqa: E402
from app.database.session import session_scope  # noqa: E402
from app.services.maintenance import create_backup  # noqa: E402

if __name__ == "__main__":
    run_migrations()
    with session_scope() as db:
        print(json.dumps(create_backup(db, None, sys.argv[1] if len(sys.argv) > 1 else "scheduled"), indent=2))
