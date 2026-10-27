# UAT dan Sign-off Matrix

UAT dinyatakan selesai hanya ketika skenario wajib lulus, defect Critical/High ditutup atau mendapat risk acceptance formal, serta semua pihak wajib memberikan persetujuan eksplisit.

## Domain sign-off

| Domain | Cakupan acceptance | Business approver | Technical approver | Evidence minimum | Status |
|---|---|---|---|---|---|
| Governance KPI | KPI definition, version, total weight 100%, effective date, owner | HR Performance, Management | Product/Engineering Lead | Approved KPI definition dan version comparison | OPEN |
| Organization | 3 group/department, 21 team, assignment dan hierarchy effective-dated | HR Performance, unit owner | Engineering Lead | Master reconciliation dan history test | OPEN |
| Authentication/access | Login, session, role, organization scope, delegation, self-approval prevention | Management, HR | IT/Security | Positive/negative authorization matrix | OPEN |
| Period dan target | Period lifecycle, assignment snapshot, target input/import/approval | HR Performance, unit pilot | Engineering/QA | UAT cases dan import reconciliation | OPEN |
| Actual dan evidence | Manual/import actual, validation, evidence access, completeness | Data owner, unit pilot | Engineering/QA/Security | Source reconciliation dan access test | OPEN |
| Scoring | Formula, rounding, missing/N/A, cap, rating, aggregation | Management, HR, process owner | Engineering/QA | Golden examples yang ditandatangani | OPEN |
| Approval/calibration | TL verification, Manager review, HR calibration, final approval | Management, HR, unit owner | Engineering/QA | State-transition evidence | OPEN |
| Lock/correction | Lock precheck, reopen terbatas, correction version dan audit | HR Performance, Management | Engineering/QA/Auditor | Before/after dan audit trace | OPEN |
| Dashboard | Role dashboard, drill-down, KPI merah, pending/missing, corrective action | Masing-masing role representative | Product/QA | Screenshot/test record dan reconciliation | OPEN |
| Report/export | Scorecard, metadata, scope, Excel/PDF, audit report | HR, Management, Auditor | QA/Security | UI-versus-export reconciliation | OPEN |
| Commercial/Support | Approved special use cases dan KPI source | Relevant unit owners | Product/Engineering | Unit UAT dan reconciliation | OPEN |
| Accounting/AR/Tax | Aging, follow-up, reconciliation, tax status | Accounting owner, Finance/Management | Engineering/QA/Security | As-of-date reconciliation | OPEN |
| Production/PPIC/Warehouse | PPIC status, production dimensions, warehouse reference | Production/PPIC/Warehouse owners | Engineering/QA | Work-order/control totals | OPEN |
| QC/NCR/CAPA | Lifecycle, evidence, due date, repeat defect linkage | QC owner, Management | Engineering/QA | End-to-end NCR/CAPA cases | OPEN |
| Integrasi/migrasi | Contract, idempotency, retry, reconciliation, dry run | System/data owner | IT/Engineering | Dua dry run dan control totals | OPEN |
| Security/operations | Hardening, monitoring, backup/restore, RPO/RTO, runbook | IT/Security, Management | SRE/Engineering | Scan, alerts, restore rehearsal | OPEN |

## Pilot lintas fungsi

PRD mengharuskan minimal satu team Commercial/Support, satu Accounting, dan satu Produksi. Team aktual ditentukan melalui DEC-007.

| Kelompok | Team pilot | Business representative | Data owner | UAT lead | Status |
|---|---|---|---|---|---|
| Commercial dan Support | OPEN | OPEN | OPEN | OPEN | OPEN |
| Accounting | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | OPEN | OPEN | OPEN | OPEN | OPEN |

## Gate defect

| Severity | Definisi ringkas | Gate produksi |
|---|---|---|
| Critical | Kehilangan/korupsi data, bypass security, kalkulasi final salah luas, sistem tidak dapat dipakai | Wajib ditutup |
| High | Workflow utama gagal, scope data bocor, approval/lock/correction tidak benar | Wajib ditutup atau formal NO-GO |
| Medium | Fungsi non-kritis terganggu dengan workaround yang aman | Boleh dengan owner, target, dan risk acceptance |
| Low | Minor usability/cosmetic tanpa risiko data | Boleh masuk backlog |

## Bukti sign-off akhir

| Pihak | Nama | Scope yang disetujui | Keputusan GO/NO-GO | Tanggal | Reference |
|---|---|---|---|---|---|
| Management |  |  |  |  |  |
| HR Performance |  |  |  |  |  |
| Commercial/Support pilot |  |  |  |  |  |
| Accounting pilot |  |  |  |  |  |
| Produksi pilot |  |  |  |  |  |
| IT/Security |  |  |  |  |  |
| Auditor/Compliance bila berlaku |  |  |  |  |  |

