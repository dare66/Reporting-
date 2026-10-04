# Security

Everything listed as built here is enforced in code and covered by tests (mainly `SecurityTest`, `AuthTest`, `AdministrationTest`, `MetricStoreTest`, `DataTrustTest` and `AutoBiTest`). The operational checklist (secrets rotation, database roles, backups) is in [OPERATIONS.md](OPERATIONS.md#security-checklist).

## Identity (built)

- JWT access tokens (15 minutes) with rotating, single-use refresh tokens. Reusing a revoked refresh token revokes the whole token family ([ADR 0002](adr/0002-jwt-with-rotating-refresh.md)).
- TOTP two-factor authentication, which administrators can require for administrators or for everyone.
- Organisation password policy (minimum length, allowed email domains). New and reset accounts must choose their own password before doing anything else.
- Session list with per-device sign-out; administrators can sign a person out everywhere.
- Rate limits:
  - login: 10 a minute per address and email;
  - MFA: 10 a minute;
  - API: 240 a minute per user;
  - AI: 30 a minute per user.

**Not built yet (brief §46):** SSO through OIDC or SAML, SCIM provisioning, API keys and service accounts. Login is isolated in `AuthController` and `JwtService`, so an external identity provider can issue the same session tokens after its own verification.

## Tenancy (built)

Every tenant table has `organisation_id` and a global scope that **fails closed**: a request with no resolved tenant sees no rows. Cross-tenant code paths (login lookup, seeding, schedulers) use the explicit, searchable `TenantScopeBypass::run()`. Tests check that one tenant cannot read another's data through the API.

**Not built yet (brief §63–64):** workspaces and projects inside a tenant. Today dashboards are private or organisation-wide, and reports are draft or published.

## Authorisation (built)

- **Permissions and roles:** 26 permissions (`metrics.certify` was added with the metric store) and 9 platform roles, plus custom roles per organisation. Every route group requires a named permission.
- **Row-level security:** user attributes map to dimensions, and a missing attribute means no rows.
- **Column-level security:** sensitive fields are masked in previews and refused in queries without `data.sensitive`.
- **Four-eyes certification:** whoever approved a metric definition cannot also certify it.

## Data protection (built)

- Data-source credentials are encrypted at rest (AES-256 with `APP_KEY`) and never serialised.
- Analytical queries run as a read-only database role, in read-only transactions with a timeout.
- **SSRF guard:** connectors refuse loopback, link-local and cloud-metadata addresses and do not follow redirects. Private networks can also be refused with `CONNECTORS_BLOCK_PRIVATE=true`.
- Uploaded workbooks are never recalculated, and the file reader is chosen from the validated extension.
- Destructive operations are guarded:
  - A data source cannot be removed while anything uses its data.
  - Only tables AIXBI created are dropped.
- Usage and impact lists count other people's private dashboards and reports but never name them.

## AI security (built)

See [AIXBI_AI_ARCHITECTURE.md](AIXBI_AI_ARCHITECTURE.md). In short: the AI has no database access, and acts as the user through the governed API. Its numbers are grounding-checked, and its plans are validated against the catalog.

## Transport and browser (built)

The gateway sets a strict Content-Security-Policy (scripts only from the app itself). The API sets `X-Frame-Options: DENY` and, on secure requests, HSTS. TLS terminates at the ingress.

## Audit (built)

Logins, queries (with query hash), exports, AI runs, data-source changes, uploads, metric lifecycle actions, drift acknowledgements and administrative actions are written to `audit_logs`. They can be viewed in Governance by people with `audit.view`.

## Not built yet

| Capability (brief §45) | Status |
|---|---|
| Automatic PII detection on ingestion (beyond fields declared sensitive) | ⏭ |
| Export policies (who may export what, watermarking) | ⏭ (`reports.export` permission exists) |
| AI policies per role (which models, which data) | ⏭ (with the AI gateway) |
| Action permissions | ⏭ (with the action engine) |
