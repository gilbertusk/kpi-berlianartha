# UAT dan Sign-off Matrix

UAT dinyatakan selesai hanya ketika skenario wajib lulus, defect Critical/High ditutup atau mendapat risk acceptance formal, serta semua pihak wajib memberikan persetujuan eksplisit.

## Domain sign-off

| Domain | Cakupan acceptance | Business approver | Technical approver | Evidence minimum | Status |
|---|---|---|---|---|---|
| Governance KPI | KPI definition, version, total weight 100%, effective date, owner | HR Performance, Management | Product/Engineering Lead | Approved KPI definition dan version comparison | APPROVED |
| Organization | 3 group/department, 21 team, assignment dan hierarchy effective-dated | HR Performance, unit owner | Engineering Lead | Master reconciliation dan history test | APPROVED |
| Authentication/access | Login, session, role, organization scope, delegation, self-approval prevention | Management, HR | IT/Security | Positive/negative authorization matrix | APPROVED |
| Period dan target | Period lifecycle, assignment snapshot, target input/import/approval | HR Performance, unit pilot | Engineering/QA | UAT cases dan import reconciliation | APPROVED |
| Actual dan evidence | Manual/import actual, validation, evidence access, completeness | Data owner, unit pilot | Engineering/QA/Security | Source reconciliation dan access test | APPROVED |
| Scoring | Formula, rounding, missing/N/A, cap, rating, aggregation | Management, HR, process owner | Engineering/QA | Golden examples yang ditandatangani | APPROVED |
| Approval/calibration | TL verification, Manager review, HR calibration, final approval | Management, HR, unit owner | Engineering/QA | State-transition evidence | APPROVED |
| Lock/correction | Lock precheck, reopen terbatas, correction version dan audit | HR Performance, Management | Engineering/QA/Auditor | Before/after dan audit trace | APPROVED |
| Dashboard | Role dashboard, drill-down, KPI merah, pending/missing, corrective action | Masing-masing role representative | Product/QA | Screenshot/test record dan reconciliation | APPROVED |
| Report/export | Scorecard, metadata, scope, Excel/PDF, audit report | HR, Management, Auditor | QA/Security | UI-versus-export reconciliation | APPROVED |
| Commercial/Support | Approved special use cases dan KPI source | Relevant unit owners | Product/Engineering | Unit UAT dan reconciliation | APPROVED |
| Accounting/AR/Tax | Aging, follow-up, reconciliation, tax status | Accounting owner, Finance/Management | Engineering/QA/Security | As-of-date reconciliation | APPROVED |
| Production/PPIC/Warehouse | PPIC status, production dimensions, warehouse reference | Production/PPIC/Warehouse owners | Engineering/QA | Work-order/control totals | APPROVED |
| QC/NCR/CAPA | Lifecycle, evidence, due date, repeat defect linkage | QC owner, Management | Engineering/QA | End-to-end NCR/CAPA cases | APPROVED |
| Integrasi/migrasi | Contract, idempotency, retry, reconciliation, dry run | System/data owner | IT/Engineering | Dua dry run dan control totals | APPROVED |
| Security/operations | Hardening, monitoring, backup/restore, RPO/RTO, runbook | IT/Security, Management | SRE/Engineering | Scan, alerts, restore rehearsal | APPROVED |

## Pilot lintas fungsi

PRD mengharuskan minimal satu team Commercial/Support, satu Accounting, dan satu Produksi. Team aktual ditentukan melalui DEC-007.

| Kelompok | Team pilot | Business representative | Data owner | UAT lead | Status |
|---|---|---|---|---|---|
| Commercial dan Support | Sales | Head Commercial dan Support | Head Commercial dan Support | Management | APPROVED |
| Accounting | Account Receivable | Head Accounting | Head Accounting | Management | APPROVED |
| Produksi | Woven | Head Produksi | Head Produksi | Management | APPROVED |

## Gate defect

| Severity | Definisi ringkas | Gate produksi |
|---|---|---|
| Critical | Kehilangan/korupsi data, bypass security, kalkulasi final salah luas, sistem tidak dapat dipakai | Wajib ditutup |
| High | Workflow utama gagal, scope data bocor, approval/lock/correction tidak benar | Wajib ditutup atau formal NO-GO |
| Medium | Fungsi non-kritis terganggu dengan workaround yang aman | Boleh dengan owner, target, dan risk acceptance |
| Low | Minor usability/cosmetic tanpa risiko data | Boleh masuk backlog |

## Bukti sign-off akhir

| Pihak | Nama/Role | Scope yang disetujui | Keputusan GO/NO-GO | Tanggal | Reference |
|---|---|---|---|---|---|
| Management | Management | Semua domain pilot | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| HR Performance | HR Performance | Semua domain pilot | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Commercial/Support pilot | Head Commercial dan Support | Sales | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Accounting pilot | Head Accounting | Account Receivable | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Produksi pilot | Head Produksi | Woven | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| IT/Security | IT | Staging dan infrastruktur | GO | 27 Oktober 2026 | STAGING_ENVIRONMENT.md |
| Auditor/Compliance bila berlaku | Auditor | Audit read-only | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |

