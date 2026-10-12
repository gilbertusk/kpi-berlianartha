# Test Strategy

## 1. Tujuan

Membuktikan correctness, authorization, auditability, reliability, usability, dan operational readiness berdasarkan PRD. Test hijau tidak menggantikan business sign-off atau bukti runtime environment.

## 2. Test levels

| Level | Fokus | Contoh |
|---|---|---|
| Unit | Pure business rule/value object | scoring, rounding, date boundary, transition guard |
| Feature | Laravel request/Livewire/policy/database | form validation, role scope, transaction, audit |
| Integration | PostgreSQL/Redis/object storage/queue/adapter | outbox, evidence, import/export, retries |
| Contract | External payload dan version | ERP/accounting/production/SSO contract fixtures |
| E2E | Journey per role | period → target → actual → approval → lock → correction |
| Security | Negative/bypass behavior | IDOR, forged Livewire action, file/export scope |
| Performance | Target PRD pada workload agreed | standard page <3 detik, export/queue throughput |
| Accessibility | Keyboard, focus, label, contrast, error semantics | desktop/tablet journey |
| Operational | Deploy/backup/restore/alert/runbook | migration, health, queue failure, restore rehearsal |

## 3. Critical test suites

### Authorization

- setiap role: allow dan deny;
- same team versus other team/department;
- current versus expired assignment/delegation;
- direct URL, Livewire action, download, export, queued job/deep link;
- System Admin cannot modify KPI values;
- Auditor read-only;
- self-approval denied.

### Data and concurrency

- unique/source/idempotency key;
- duplicate submit/import/event/retry;
- concurrent edit/approval/lock/correction;
- transaction rollback leaves no partial state/outbox mismatch;
- effective-date overlap and period boundary;
- zero, missing, N/A, negative where applicable, decimal/rounding.

### Scoring

- table-driven boundary cases for every approved strategy;
- golden examples signed by HR/process owner;
- old formula version reproduces historical result;
- contribution totals reconcile to final score;
- calibration preserves before/after.

### File and export

- size/extension/content mismatch;
- malicious filename/path and formula injection;
- quarantine/scan state;
- unauthorized signed URL and expired URL;
- large streamed export, metadata, retry, retention.

## 4. Test data

- gunakan factories dan deterministic seeds;
- data personal/customer/financial harus sintetis atau disanitasi dengan approval;
- sediakan 3 departments, 21 teams, all roles, effective assignment changes, period history, dan edge cases;
- load dataset diturunkan dari DEC-007; jangan mengklaim capacity sebelum volume tersedia.

## 5. Environments

| Environment | Tujuan | Data |
|---|---|---|
| Local | Fast developer feedback | Synthetic |
| CI | Reproducible automated gate | Ephemeral synthetic |
| Staging | Runtime/integration/UAT rehearsal | Sanitized or controlled pilot |
| Production | Smoke/monitoring only | Real, least-privilege |

Database, bucket, Redis namespace, credentials, dan notification recipient tidak boleh dibagi antara staging dan production.

## 6. CI gates

- dependency/security audit;
- formatting dan static analysis;
- unit/feature/integration test;
- frontend production build;
- migration on empty database dan upgrade-path rehearsal ketika relevan;
- E2E smoke untuk merge/release branch;
- image scan/SBOM bila tooling tersedia.

Script aggregate harus dibuktikan menjalankan checks nyata; script no-op tidak dianggap gate.

## 7. Release gates

- tidak ada Critical/High defect terbuka;
- authorization negative tests lulus;
- scoring matches signed golden examples;
- requirement traceability lengkap untuk release scope;
- target performance tercapai atau memiliki risk acceptance formal;
- backup/restore dan rollback rehearsal tersedia;
- UAT sign-off lengkap untuk production.

## 8. Evidence

Simpan command, version/commit SHA, environment, timestamp, dataset, result, report path, dan approver. Screenshot tanpa log/result yang dapat direproduksi tidak cukup untuk technical gate.

