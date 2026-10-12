# Threat Model — Sistem KPI PT Berlian Artha Label

**Status:** Baseline; wajib diperbarui ketika integration, hosting, SSO, dan data classification difinalkan.

## 1. Assets

- identity, role, organization assignment, dan delegation;
- KPI definition, target, actual, score, rating, calibration, dan approval;
- evidence, coaching notes, corrective action, NCR/CAPA;
- customer, invoice/outstanding, production/work-order references;
- audit event dan export;
- secret, session, integration credential, backup, dan recovery material.

## 2. Trust boundaries

1. Browser/user ↔ edge/web application.
2. Application ↔ PostgreSQL/Redis/object storage.
3. Worker ↔ external systems/email/identity provider.
4. Developer/CI ↔ artifact registry/deployment environment.
5. Admin/operator ↔ production control plane/backup.

## 3. Threat register

| ID | Threat | Scenario | Impact | Required controls | Validation |
|---|---|---|---|---|---|
| T-001 | Broken object authorization | User mengganti ID team/employee/file/export | Data personal/KPI bocor | Server policy + organization scope + opaque download + audit | Negative tests per role/scope |
| T-002 | Privilege escalation | System Admin atau delegated user mengubah score | Integrity | Separation of duties, explicit policies, delegation scope/expiry | Forged request/Livewire tests |
| T-003 | Self-approval | Approver menyetujui record miliknya | Governance failure | Ownership check, substitute matrix, atomic state guard | Workflow negative tests |
| T-004 | Session/account takeover | Cookie/token dicuri atau session lemah | Full unauthorized access | TLS, secure cookie, MFA/SSO, session rotation/revoke, rate/risk control | Security config/session tests |
| T-005 | CSRF/XSS/injection | Form, note, import, filter, atau template berisi payload | State change/data theft | CSRF, validation, output escaping, parameterized query, CSP plan | Automated/manual security tests |
| T-006 | Mass assignment | Request mengubah field status/scope tersembunyi | Integrity/privilege | DTO/form request, allowlist, domain command | Feature tests |
| T-007 | Malicious upload | Evidence executable/polyglot/oversized | Malware/availability | Quarantine, signature/MIME/size validation, scan, private storage | Upload test suite |
| T-008 | Signed URL leakage | URL panjang umur atau tidak scoped | Evidence leak | Short expiry, authorization before issue, private bucket, audit | Expiry/unauthorized tests |
| T-009 | Import replay/duplicate | Batch yang sama diproses ulang | Double actual/score | Hash, idempotency key, source unique key, reconciliation | Replay tests |
| T-010 | Formula/config abuse | Arbitrary expression atau unreviewed formula dipublish | Wrong scoring/RCE | Whitelisted strategies, version approval, no eval | Publish and parser tests |
| T-011 | Race condition | Double approval, edit-vs-lock, correction-vs-export | Inconsistent state | Transaction, lock/token, unique constraints, idempotency | Concurrency tests |
| T-012 | Audit tampering/bypass | Bulk update tidak tercatat | Non-repudiation loss | Domain service audit in same transaction, restricted DB role | Direct-path and immutability tests |
| T-013 | Export exfiltration | Broad export or spreadsheet injection | Data leak/code execution | Scope snapshot, metadata, formula sanitization, expiry, audit | Export security tests |
| T-014 | Secret leakage | `.env`, logs, CI output, error page | System compromise | Secret manager, redaction, ignore rules, scanning | Repo/log/image scan |
| T-015 | Webhook/API spoofing | Fake/replayed source event | Wrong actual | TLS, signature/OAuth, timestamp/nonce, replay guard | Contract/security tests |
| T-016 | Queue poisoning/retry storm | Bad payload repeatedly fails | Availability/data duplicates | Schema/version, bounded retry, DLQ/manual recovery, idempotency | Failure/replay tests |
| T-017 | Cache scope leak | Cached dashboard reused across scopes | Cross-team exposure | Scope-aware keys, safe invalidation, no sensitive shared cache | Cache authorization tests |
| T-018 | Backup exposure/failure | Backup unencrypted or untested | Mass leak/irrecoverability | Encryption, access control, retention, isolated restore rehearsal | Restore and access evidence |
| T-019 | Dependency/container compromise | Vulnerable package/image | Application compromise | Lockfiles, audit/scan, minimal image, SBOM, patch policy | CI scan and exception register |
| T-020 | Availability exhaustion | Heavy report/import/dashboard | Denial of service | Pagination, async job, rate/size limit, query budget, monitoring | Load/resource tests |

## 4. Abuse cases requiring explicit tests

- Employee requests another employee's evidence by URL.
- Team Leader modifies team identifier in Livewire payload.
- System Admin calls score update route directly.
- Manager approves own score using expired delegation.
- Same import or webhook is replayed many times.
- Actual is edited while HR locks the period.
- Formula/rating changes after a historical period is locked.
- Export contains a cell beginning with spreadsheet formula markers.
- Notification deep link opens record after user's scope changed.
- Object-storage URL is reused after expiry.

## 5. Residual/open risks

- Identity provider/MFA is not selected.
- Hosting/network topology and WAF are not selected.
- Antivirus product/runtime is not selected.
- Retention, RPO/RTO, and legal-hold requirements are open.
- Integration protocols and credential lifecycle are open.

These items block production security acceptance, not architecture drafting.

