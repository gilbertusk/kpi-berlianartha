# Implementation Backlog

Backlog ini memetakan PRD dan decision register ke delivery bertahap. Detail story dapat dipecah lebih kecil ketika kapasitas tim diketahui. Urutan release mengikuti PRD, bukan sekadar nomor file prompt.

## Definition of Ready

Sebuah item siap dikerjakan jika:

- objective dan acceptance criteria jelas;
- dependency teknis tersedia;
- keputusan bisnis terkait `APPROVED` atau item hanya membuat draft/configuration boundary;
- data owner dan source diketahui untuk fitur berbasis data;
- security/data classification telah ditentukan;
- test approach dan evidence yang dibutuhkan jelas.

## Definition of Done

- implementation sesuai module boundary dan ADR;
- migration/reversal/rollback impact ditinjau;
- authorization positive dan negative test tersedia;
- unit/feature/integration/E2E sesuai risiko lulus;
- audit/observability dan error path diuji;
- documentation dan `PROJECT_PROGRESS.md` diperbarui;
- tidak ada Critical/High defect terbuka;
- commit step di-push dan SHA lokal sama dengan `origin/main`.

## Release 0 — Governance dan architecture

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| GOV-01 | Decision register, KPI template, role/approval matrix, UAT sign-off | PRD | Semua keputusan memiliki owner/status; unknown tidak diasumsikan | 00 |
| ARC-01 | Solution architecture, ADR, module boundary, threat model | GOV-01 | PRD terpetakan, risiko dan dependency eksplisit | 01 |

## Release 1 — Fondasi produk inti

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| PLT-01 | Laravel/Livewire skeleton, design primitives, smoke test | ARC-01 | Install/build/test dari checkout bersih | 02 |
| PLT-02 | Docker Compose, CI, quality/security gates | PLT-01 | Runtime lokal dan CI terbukti, bukan no-op | 03 |
| DAT-01 | Identifier, decimal, timezone, transaction, audit, outbox | PLT-02 | Idempotency/concurrency/audit tests lulus | 04 |
| IAM-01 | Authentication adapter, organization, assignment, role/scope, delegation | DAT-01, DEC-013 | 3 group, 21 team, negative authorization tests | 05 |
| KPI-01 | KPI definition/template/version/publish | IAM-01, DEC-003/005/006 | Published immutable, total bobot 100%, effective-date valid | 06 |
| KPI-02 | Period, assignment snapshot, target/import | KPI-01, DEC-002 | Histori tidak berubah; import atomik/reconciled | 07 |
| KPI-03 | Actual, evidence, staged import | KPI-02, storage boundary | Evidence private; zero/missing/N/A berbeda; retry idempotent | 08 |
| KPI-04 | Scoring strategy dan scorecard snapshot | KPI-03, DEC-004/006 | Golden examples cocok; historical reproducible | 09 |
| WFL-01 | Verify/review/calibrate/approve/lock/correction | KPI-04, DEC-002/009 | No skipped state/self-approval; correction creates version | 10 |
| UX-01 | Role dashboard, daily review, corrective action, notification | WFL-01, DEC-009/011 | Scoped dashboard; reminder idempotent; accessibility baseline | 11 |
| RPT-01 | Scorecard/report/audit export | UX-01, retention decision | UI/export reconcile; large export async/private | 12 |

## Release 1 readiness — sebelum pilot

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| OPS-CORE | Core security, health, logs/metrics, backup/restore rehearsal | Release 1, DEC-010/014 | Tidak ada high finding; restore teruji | 18 core |
| QA-CORE | Traceability, role E2E, scoring/concurrency/load/accessibility | OPS-CORE, DEC-007 | Core acceptance criteria dan 3 detik target teruji | 19 core |

## Release 2 — Pilot lintas fungsi

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| PILOT-01 | Staging dan pilot minimal tiga unit lintas fungsi | QA-CORE, DEC-007 | UAT sign-off tanpa Critical/High defect | 20 |
| PILOT-02 | Training, operational acceptance, release/rollback evidence | PILOT-01 | GO/NO-GO tertulis | 20 |

## Release 3 — Rollout fondasi inti 21 team

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| CORE-ROLL-01 | Template/target/user/source mapping per wave | PILOT-02 | Access, template, data owner, reconciliation disetujui per wave | 21 wave plan |
| CORE-ROLL-02 | Training/support/adoption per wave | CORE-ROLL-01 | Owner acknowledgement dan support SLA aktif | 21 wave plan |

## Release 4 — Fitur khusus dan integrasi

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| COM-01 | Sales, Design attribution, Costing comparison, support-unit views | Pilot core, DEC-001/006/008 | Unit UAT dan source reconciliation | 13 |
| ACC-01 | AR aging, Accounting reconciliation, Tax Invoice tracking | Pilot core, DEC-006/008/010 | As-of reconciliation dan access tests | 14 |
| PRD-01 | PPIC board, production dimensions, warehouse/logistics | Pilot core, DEC-006/008/015/016 | Work-order/control-total reconciliation | 15 |
| QMS-01 | Inspection, defect, NCR, CAPA, repeat issue | Pilot core, DEC-006/009/016 | Lifecycle/closure/linkage UAT | 16 |
| INT-01 | Source adapters, migration, dry run, reconciliation | Unit features, DEC-008/016 | Dua repeatable dry run tanpa duplicate | 17 |
| OPS-FULL | Full-scope hardening, monitoring, DR | INT-01 | Production controls and restore evidence | 18 full |
| QA-FULL | Full regression/load/security/accessibility | OPS-FULL | Release traceability complete | 19 full |
| UAT-FULL | Specialized feature UAT | QA-FULL | Approved per domain | 20 repeat |

## Release 5 — Production completion dan optimasi

| Epic | Scope | Dependency | Exit criteria | Prompt |
|---|---|---|---|---|
| PRD-GO | Production deployment/cutover dan validation | GO decision | Local/remote version, runtime, migration, reconciliation verified | 21 |
| ROLL-21 | Complete wave status untuk 21 team | PRD-GO | Semua team yang in-scope accepted | 21 |
| HYP-01 | Hypercare, incident/defect/adoption monitoring | PRD-GO | Stabilitas sesuai exit threshold | 21 |
| HND-01 | Runbook, KT, operations acceptance, post-implementation review | HYP-01 | Business dan IT handover signed | 21 |
| OPT-01 | Trend analytics, data quality, SLA tuning, KPI effectiveness review | Stable production data | Improvement disetujui dan berversi | Backlog pascaproduksi |

## Critical dependency register

| Dependency | Blocking scope | Owner/status |
|---|---|---|
| Approved KPI definitions/formula | KPI publish, scoring, UAT | DEC-003–006 OPEN/PROPOSED |
| Approver per team | Production workflow | DEC-002 OPEN |
| Pilot team/user/volume | Load model dan pilot | DEC-007 OPEN |
| Source contracts | Unit features/integration | DEC-008/016 OPEN |
| SLA | Reminder/escalation | DEC-009 OPEN |
| Retention/RPO/RTO/browser | Production readiness | DEC-010 OPEN |
| Identity provider | Production login | DEC-013 OPEN |
| Hosting/provider | Production topology | DEC-014 OPEN |

## Prioritization rule

1. Security, data integrity, audit, and correctness outrank convenience.
2. Core generic capability outranks duplicated team-specific implementation.
3. Pilot learning outranks broad rollout.
4. Integration automation follows a proven manual/staged reconciliation path.
5. Optimization follows measurement; do not preemptively add microservices, Kubernetes, or data warehouse.

