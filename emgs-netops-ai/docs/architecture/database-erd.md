# Database design (SQLite)

File: `data/database/netops.db` (override with `NETOPS_DATA_DIR` / `NETOPS_DB_PATH`). Pragmas: `foreign_keys=ON`,
`journal_mode=WAL`, `busy_timeout=5000`. Schema changes are versioned in `backend/app/database/migrations.py` and recorded
in `schema_migrations`.

```mermaid
erDiagram
    users ||--o{ user_roles : has
    roles ||--o{ user_roles : grants
    users ||--o{ user_sessions : "signs in"
    users ||--o{ api_tokens : owns
    users ||--o{ diagnostic_jobs : runs
    users ||--o{ discovery_scans : runs
    users ||--o{ report_history : generates
    applications ||--o{ servers : categorises
    diagnostic_jobs ||--o{ diagnostic_results : produces
    discovery_scans ||--o{ discovered_devices : finds
    discovered_devices ||--o{ discovered_ports : exposes
    discovery_scans ||--o{ inventory_comparisons : yields
    servers ||--o{ inventory_comparisons : "matched to"
    discovered_devices ||--o{ inventory_comparisons : "classified as"

    users { int id PK; string username UK; string password_hash; bool is_active; int failed_logins; datetime locked_until }
    roles { int id PK; string name UK }
    user_roles { int user_id PK,FK; int role_id PK,FK }
    user_sessions { int id PK; string token_hash UK; string csrf_token; int user_id FK; datetime expires_at; bool revoked }
    api_tokens { int id PK; string token_hash UK; string prefix; int user_id FK; datetime expires_at; bool revoked }
    applications { int id PK; string name UK; bool is_sample }
    servers { int id PK; string server_code UK; string name; string hostname; string ip_address; string environment; string operating_system; int application_id FK; string category; string technical_owner; string business_owner; string criticality; json approved_ports; datetime registered_at; datetime last_discovered_at; string status; bool is_sample }
    approved_networks { int id PK; string cidr UK; bool allow_diagnostics; bool allow_discovery; bool allow_os_fingerprint; bool is_sample }
    approved_domains { int id PK; string domain UK; bool is_sample }
    approved_ports { int id PK; int port UK; string service; bool enabled; bool use_in_discovery }
    diagnostic_jobs { int id PK; string reference UK; string diagnostic_type; string target; int port; json parameters; string status; string outcome; string mode; string source_interface; string source_network; int user_id FK; datetime started_at; float duration_ms }
    diagnostic_results { int id PK; int job_id FK; json summary; text raw_output; json assessment }
    discovery_scans { int id PK; string reference UK; string range_input; string normalized_range; int address_count; json methods; json ports; string status; int progress; int responding; string mode; int user_id FK }
    discovered_devices { int id PK; int scan_id FK; string ip_address; string hostname; string mac_address; string os_guess; bool responded_icmp; bool responded_tcp; bool responded_arp; json detection_methods }
    discovered_ports { int id PK; int device_id FK; int port; string state; float response_ms }
    inventory_comparisons { int id PK; int scan_id FK; int server_id FK; int device_id FK; string ip_address; string classification; text detail; bool reviewed }
    audit_logs { int id PK; datetime timestamp; string username; string source_interface; string client_ip; string action; string diagnostic_type; string destination; int port; string source_network; string status; string reference }
    application_settings { string key PK; json value }
    ai_settings { int id PK; bool enabled; string model; text api_key_encrypted; bool send_raw_output }
    report_history { int id PK; string reference UK; string report_type; string format; string file_name; int user_id FK }
    schema_migrations { int version PK; string name; datetime applied_at }
```

## Notes

* **Indexes**: references, `diagnostic_jobs(diagnostic_type, target, started_at)`, `audit_logs(timestamp, action)`, `servers(ip_address, hostname)`, `discovered_devices(scan_id, ip_address)`, and others (see `models/entities.py`).
* **Transactions**: request-scoped sessions commit on success and roll back on error (`get_db`, `session_scope`). Diagnostic jobs commit before network I/O so that no write lock is held while waiting.
* **Sample data**: every seeded row carries `is_sample = true`, with names prefixed `SAMPLE`. Sample policy rows only apply in Simulation mode.
* **Secrets**: passwords use scrypt. Session and API tokens are stored as SHA-256 hashes. The Claude API key is Fernet-encrypted with the per-installation secret in `data/secret.key`.
* **Backup / restore**: online backups use the SQLite backup API. A restore checks integrity and the schema first and takes a safety copy before replacing the file.
* **Retention**: `retention_days` (default 180) applies to diagnostics, scans and expired sessions. The audit log is kept for at least 365 days.
