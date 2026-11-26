"""Users, sessions, API tokens and first-run setup."""
from __future__ import annotations

from datetime import datetime, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.context import ExecutionContext
from app.models import entities as m
from app.security.passwords import hash_password, password_problems, verify_password
from app.security.tokens import hash_token, new_token
from app.security.validation import ValidationError
from app.services import audit
from app.services.settings import get_setting

LOCKOUT_THRESHOLD = 5
LOCKOUT_MINUTES = 15
ROLES = ("admin", "operator", "viewer")


class AuthError(PermissionError):
    pass


def setup_required(db: Session) -> bool:
    return (db.scalar(select(func.count(m.User.id))) or 0) == 0


def _roles(db: Session, names: list[str]) -> list[m.Role]:
    names = [n for n in names if n in ROLES]
    if not names:
        raise ValidationError("At least one valid role is required (admin, operator, viewer).")
    return list(db.scalars(select(m.Role).where(m.Role.name.in_(names))))


def create_user(db: Session, username: str, password: str, roles: list[str], full_name: str = "",
                actor: ExecutionContext | None = None) -> m.User:
    username = (username or "").strip().lower()
    if not username or not username.replace(".", "").replace("_", "").replace("-", "").isalnum() or len(username) > 64:
        raise ValidationError("Username may contain letters, digits, '.', '_' and '-' only.")
    if db.scalar(select(m.User).where(m.User.username == username)):
        raise ValidationError("That username already exists.")
    problems = password_problems(password, username)
    if problems:
        raise ValidationError("Password needs " + "; ".join(problems) + ".")
    user = m.User(username=username, full_name=full_name[:128], password_hash=hash_password(password))
    user.roles = _roles(db, roles)
    db.add(user)
    db.flush()
    audit.record(db, actor, "user.create", destination=username, detail=f"roles={roles}")
    return user


def complete_setup(db: Session, username: str, password: str, full_name: str = "") -> m.User:
    """First-run wizard: creates the local administrator. Only works while no user exists."""
    if not setup_required(db):
        raise AuthError("Setup has already been completed.")
    user = create_user(db, username, password, ["admin"], full_name)
    audit.record(db, None, "setup.complete", destination=user.username)
    return user


def authenticate(db: Session, username: str, password: str, client_ip: str = "") -> m.User:
    user = db.scalar(select(m.User).where(m.User.username == (username or "").strip().lower()))
    now = datetime.utcnow()
    ctx = ExecutionContext(None, (username or "")[:64], interface="web", client_ip=client_ip)
    if user and user.locked_until and user.locked_until > now:
        audit.record(db, ctx, "auth.login", status="locked")
        raise AuthError("Account temporarily locked after repeated failures. Try again later.")
    if not user or not user.is_active or not verify_password(password or "", user.password_hash):
        if user:
            user.failed_logins += 1
            if user.failed_logins >= LOCKOUT_THRESHOLD:
                user.locked_until = now + timedelta(minutes=LOCKOUT_MINUTES)
                user.failed_logins = 0
        audit.record(db, ctx, "auth.login", status="failed")
        raise AuthError("Invalid username or password.")
    user.failed_logins = 0
    user.locked_until = None
    user.last_login_at = now
    return user


def create_session(db: Session, user: m.User, client_ip: str, user_agent: str) -> tuple[str, m.UserSession]:
    token = new_token()
    hours = int(get_setting(db, "session_hours") or 8)
    sess = m.UserSession(token_hash=hash_token(token), csrf_token=new_token(24), user_id=user.id,
                         expires_at=datetime.utcnow() + timedelta(hours=hours), client_ip=client_ip,
                         user_agent=(user_agent or "")[:255])
    db.add(sess)
    db.flush()
    return token, sess


def session_user(db: Session, token: str) -> tuple[m.User, m.UserSession] | None:
    if not token:
        return None
    sess = db.scalar(select(m.UserSession).where(m.UserSession.token_hash == hash_token(token)))
    now = datetime.utcnow()
    if not sess or sess.revoked or sess.expires_at < now:
        return None
    user = db.get(m.User, sess.user_id)
    if not user or not user.is_active:
        return None
    if (now - sess.last_seen_at).total_seconds() > 60:  # avoid a write on every read request
        sess.last_seen_at = now
    return user, sess


def revoke_session(db: Session, token: str) -> None:
    sess = db.scalar(select(m.UserSession).where(m.UserSession.token_hash == hash_token(token)))
    if sess:
        sess.revoked = True


def create_api_token(db: Session, user: m.User, name: str, days: int | None = 90) -> tuple[str, m.ApiToken]:
    token = "nop_" + new_token(32)
    row = m.ApiToken(name=(name or "token")[:64], token_hash=hash_token(token), prefix=token[:10], user_id=user.id,
                     expires_at=datetime.utcnow() + timedelta(days=days) if days else None)
    db.add(row)
    db.flush()
    return token, row


def api_token_user(db: Session, token: str) -> m.User | None:
    if not token or not token.startswith("nop_"):
        return None
    row = db.scalar(select(m.ApiToken).where(m.ApiToken.token_hash == hash_token(token)))
    now = datetime.utcnow()
    if not row or row.revoked or (row.expires_at and row.expires_at < now):
        return None
    user = db.get(m.User, row.user_id)
    if not user or not user.is_active:
        return None
    if not row.last_used_at or (now - row.last_used_at).total_seconds() > 60:
        row.last_used_at = now
    return user


def change_password(db: Session, user: m.User, current: str, new: str) -> None:
    if not verify_password(current, user.password_hash):
        raise AuthError("Current password is incorrect.")
    problems = password_problems(new, user.username)
    if problems:
        raise ValidationError("Password needs " + "; ".join(problems) + ".")
    user.password_hash = hash_password(new)
    for s in db.scalars(select(m.UserSession).where(m.UserSession.user_id == user.id)):
        s.revoked = True


def user_to_dict(u: m.User) -> dict:
    return {"id": u.id, "username": u.username, "full_name": u.full_name, "roles": u.role_names,
            "is_active": u.is_active, "last_login_at": u.last_login_at.isoformat() + "Z" if u.last_login_at else None,
            "created_at": u.created_at.isoformat() + "Z"}


def context_for(user: m.User, interface: str, client_ip: str = "") -> ExecutionContext:
    return ExecutionContext(user.id, user.username, user.role_names, interface, client_ip)
