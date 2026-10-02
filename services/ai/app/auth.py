"""Authentication: end users present the API's JWT; the API calls us with an internal token."""

from dataclasses import dataclass
from typing import Annotated

import jwt
from fastapi import Header, HTTPException

from .config import settings


@dataclass(frozen=True)
class Principal:
    user_id: str
    organisation_id: str
    roles: tuple[str, ...]
    token: str  # forwarded to the API so every data access is authorised as this user


def current_user(authorization: Annotated[str, Header()] = "") -> Principal:
    token = authorization.removeprefix("Bearer ").strip()
    if not token:
        raise HTTPException(401, detail="Authentication required.")
    try:
        claims = jwt.decode(token, settings().jwt_secret, algorithms=["HS256"], issuer=settings().jwt_issuer)
    except jwt.PyJWTError:
        # Token internals are not echoed to the caller.
        raise HTTPException(401, detail="Your session has expired. Please sign in again.") from None
    if claims.get("typ") != "access":
        raise HTTPException(401, detail="Invalid token type.")
    return Principal(claims["sub"], claims["org"], tuple(claims.get("roles", [])), token)


def internal_caller(authorization: Annotated[str, Header()] = "") -> None:
    if authorization.removeprefix("Bearer ").strip() != settings().internal_token:
        raise HTTPException(401, detail="Invalid service token.")
