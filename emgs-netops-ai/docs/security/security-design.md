# Security design

## Threat model (summary)

| Threat | Control |
|---|---|
| Diagnostics used to probe arbitrary or public networks | Target allowlist (approved networks / domains / inventory hostnames). Resolved addresses are re-checked. Discovery is limited to private RFC1918/CGNAT space inside an approved network with discovery enabled. Public networks can never be scanned. |
| Command injection through targets | Strict grammar validation (IP literal or RFC 1123 hostname; no spaces, shell metacharacters or leading `-`). Commands run with `create_subprocess_exec` and a fixed argv, never a shell. Tested with `;`, `$( )`, backticks and option injection. |
| Excessive or intrusive scanning | Approved-port list only. Scans are limited to /24 by default (admin-configurable, with a hard cap of 65,536 and a second confirmation for large scans). Concurrency and packets-per-second limits apply. One scan at a time. No vulnerability scripts; Nmap runs without NSE. OS fingerprinting must be enabled per network. |
| Unauthorised use of the dashboard | Local accounts with scrypt hashes, a 12-character / 3-class password policy, and lockout after 5 failures (15 min). Server-side sessions (hashed tokens, 8 h default). Three roles: admin / operator / viewer. |
| CSRF / cross-site WebSocket hijacking | SameSite=Strict cookies plus a double-submit `X-CSRF-Token` on every unsafe request. WebSockets check `Origin` and require the CSRF token for commands. |
| Session theft over the network | Default bind is 127.0.0.1 only. Mobile access binds HTTPS to one approved private IP (never 0.0.0.0); cookies are `Secure` there; HSTS is set. |
| Abuse / brute force | Rate limits: login 10/min/IP, API 600/min/IP, diagnostics per user (default 60/min). |
| Secret leakage | No secrets in source. The per-install secret is in `data/secret.key` (0600). The Claude key is Fernet-encrypted at rest and write-only through the API. API tokens are shown once and stored hashed. Certificate checks expose public fields only. |
| Malicious output / XSS | Subprocess and network output is sanitised (ANSI/control characters stripped, length limited). React escapes rendering. A strict CSP (`default-src 'self'`), `X-Frame-Options: DENY` and `nosniff` are set. |
| Spreadsheet formula injection | The audit CSV export prefixes cells that start with `= + - @`. |
| AI acting beyond policy | Claude only proposes a structured intent. Validation and policy are applied afterwards, exactly as for a manual request. Discovery always needs explicit user confirmation. Raw output is not sent to the API unless an admin enables it. |
| Sample data mistaken for real infrastructure | Sample rows are flagged `is_sample`, named `SAMPLE…`, labelled in the UI and on reports, and ignored by policy in Live mode. |
| Tampering / repudiation | The audit log records user, time, interface, client IP, action, destination, port, source network, status and reference for every diagnostic, discovery, login, policy, settings, inventory, report and backup action. |

## First-time setup

The first administrator can only be created from the laptop itself (`127.0.0.1`), and only while no user exists. No
default password ships with the product.

## Operational guidance

* Keep the laptop's disk encrypted (BitLocker / LUKS). `data/` contains the database, reports and the secret key.
* Back up `data/` with the backup script; backups contain diagnostic history. Store them as internal records.
* Rotate API tokens (default 90-day expiry). Revoke a token when a device is lost.
* Use Live mode only on the authorised EMGS network, and confirm approved ranges with the network team.
* Run without administrator/root rights. ARP, ICMP and TCP checks don't need them. Nmap OS fingerprinting does, and is off by default.

## Security tests

`backend/tests/test_security_api.py` and `test_validation_policy.py` cover: unauthorised target, unapproved port, invalid
input, injection strings, excessive requests (429), expired session, invalid/revoked API token, CSRF, roles, lockout,
setup-once, write-only AI key, audit content and security headers.
