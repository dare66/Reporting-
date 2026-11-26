"""Password hashing with scrypt (memory-hard, standard library only)."""
from __future__ import annotations

import base64
import hashlib
import hmac
import re
import secrets

_N, _R, _P, _DKLEN = 2**14, 8, 1, 64


def hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    dk = hashlib.scrypt(password.encode(), salt=salt, n=_N, r=_R, p=_P, dklen=_DKLEN)
    return f"scrypt${_N}${_R}${_P}${base64.b64encode(salt).decode()}${base64.b64encode(dk).decode()}"


def verify_password(password: str, stored: str) -> bool:
    try:
        algo, n, r, p, salt_b64, dk_b64 = stored.split("$")
        if algo != "scrypt":
            return False
        dk = hashlib.scrypt(password.encode(), salt=base64.b64decode(salt_b64), n=int(n), r=int(r), p=int(p),
                            dklen=len(base64.b64decode(dk_b64)))
        return hmac.compare_digest(dk, base64.b64decode(dk_b64))
    except (ValueError, TypeError):
        return False


def password_problems(password: str, username: str = "") -> list[str]:
    """Local policy: 12+ chars, three character classes, not the username."""
    problems = []
    if len(password) < 12:
        problems.append("at least 12 characters")
    classes = sum(bool(re.search(p, password)) for p in (r"[a-z]", r"[A-Z]", r"\d", r"[^A-Za-z0-9]"))
    if classes < 3:
        problems.append("at least three of: lowercase, uppercase, digit, symbol")
    if username and username.lower() in password.lower():
        problems.append("must not contain the username")
    return problems
