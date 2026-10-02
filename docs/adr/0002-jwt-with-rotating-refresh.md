# ADR 0002 — JWT access tokens with rotating refresh tokens

**Status:** accepted

Short-lived (15 min) HS256 access JWTs carry tenant and role claims so the stateless AI service can authenticate users; the API still re-loads the user on every request so suspension is immediate. Refresh tokens are random, stored hashed, single-use and rotated; replaying a used token revokes the whole family. TOTP MFA (RFC 6238) is supported. OIDC federation (Entra ID, Okta) is planned as an additional identity provider that issues the same internal tokens. For multi-service production deployments, switch to RS256 with key rotation (the `JwtService` isolates this).
