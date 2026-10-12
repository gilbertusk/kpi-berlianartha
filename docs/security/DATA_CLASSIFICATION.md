# Data Classification dan Handling Baseline

Klasifikasi final memerlukan persetujuan HR, Legal/Compliance, Management, dan IT. Sampai disetujui, gunakan klasifikasi yang lebih ketat ketika ragu.

## Levels

| Level | Definisi | Contoh | Default handling |
|---|---|---|---|
| PUBLIC | Disetujui untuk publik | Informasi perusahaan yang sudah dipublikasikan | Tidak mengandung credential/internal detail |
| INTERNAL | Operasional internal dengan dampak rendah | KPI dictionary draft umum, dokumentasi proses | Authenticated access, controlled sharing |
| CONFIDENTIAL | Data bisnis/pegawai/customer terbatas | Target, actual, score, invoice summary, production metric | Scope authorization, encryption, restricted export |
| RESTRICTED | Dampak tinggi, personal/sensitif/audit/security | Coaching notes, evidence sensitif, calibration reason, credential, backup | Need-to-know, strong audit, minimum retention, no casual export |

## Data inventory baseline

| Data | Classification default | Authorized roles (subject to scope) | Storage/logging notes |
|---|---|---|---|
| Organization/team structure | INTERNAL | Authenticated users sesuai kebutuhan | Effective history retained |
| Employee identifier/profile | CONFIDENTIAL | Employee self, TL/Manager, HR, authorized auditor | Minimize fields; no unnecessary logs |
| KPI definition/template | INTERNAL; CONFIDENTIAL before approval if sensitive | HR, unit owner, Management; published definition per policy | Versioned and audited |
| Target/actual/score/rating | CONFIDENTIAL | Subject, approval chain, HR, Management, auditor | Never in debug logs; scoped export |
| Calibration reason | RESTRICTED | HR, authorized Management/auditor | Preserve before/after, strong audit |
| Coaching notes | RESTRICTED (proposed) | Decision DEC-011 | Separate policy; no broad dashboard/export |
| Evidence attachment | CONFIDENTIAL or RESTRICTED | Record-authorized roles | Private object, scan, signed URL, retention |
| Corrective action | CONFIDENTIAL | Responsible chain and auditor | Evidence may be RESTRICTED |
| Customer/order/invoice/outstanding | CONFIDENTIAL | Relevant Sales/Accounting/Management/auditor | Mask/minimize; financial decimal precision |
| Production/work-order/machine data | CONFIDENTIAL | Production/PPIC/Management/auditor | Show source freshness |
| NCR/CAPA/defect evidence | CONFIDENTIAL or RESTRICTED | QC/Production/Management/auditor | No deletion after affecting score |
| Audit event | RESTRICTED | Auditor, HR/Management/IT as mandated | Append-only logical; redacted payload |
| Application log/trace | INTERNAL/CONFIDENTIAL | IT/SRE | No token, cookie, full payload, personal note |
| Password/token/private key | RESTRICTED SECRET | Runtime/authorized operator only | Never database plain text, log, chat, or Git |
| Backup | RESTRICTED | Authorized operations/security | Encrypted, isolated, retention, restore audit |

## Handling rules

### Collection

- collect only fields needed by approved use case;
- state owner, purpose, source, and retention;
- production data in non-production requires approval and sanitization.

### Access

- role + organization scope + workflow/ownership;
- least privilege and periodic recertification;
- direct file/export access rechecks policy;
- delegation has scope, reason, start, and expiry.

### Transmission/storage

- TLS in transit and encryption at rest where service supports it;
- private database/network/storage endpoints;
- secret only in approved secret store/environment injection;
- signed URLs short-lived.

### Logging/audit

- audit sensitive action, not secret value;
- redact token, cookie, password, setup/recovery code, connection string, and unnecessary personal data;
- restrict audit/log access separately.

### Export and sharing

- apply requester scope at generation time;
- include classification/metadata where appropriate;
- sanitize spreadsheet cells;
- store private with expiry and audit download for sensitive reports.

### Retention/disposal

Exact durations remain DEC-010. Purge must respect legal hold, audit requirement, locked-period reproducibility, and backup lifecycle. Deleting an object without reconciling metadata is prohibited.

