#!/usr/bin/env python3
"""Report what this laptop has for EMGS NetOps AI (Python, packages, ping/traceroute/nmap, Node.js)."""
import runpy
import sys
from pathlib import Path

sys.argv = [sys.argv[0], "check"]
runpy.run_path(str(Path(__file__).resolve().parents[1] / "startup" / "netops.py"), run_name="__main__")
