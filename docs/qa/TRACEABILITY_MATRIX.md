# Requirement Traceability Matrix

**Status:** Step 19 run 1 (core readiness), 26 Oktober 2026, commit Step 19. Sumber requirement: PRD §14 (audit, keamanan, kepatuhan), §15 (NFR 001–010), dan §16 (kriteria penerimaan rilis inti), serta decision register. Bukti per run ada di `EVIDENCE_INDEX.md`. Ringkasan dan keputusan rilis ada di `RELEASE_TEST_REPORT.md`.

Arti status:

- **TERBUKTI (teknis)**: perilaku sistem dibuktikan test otomatis dan/atau runtime. Ini bukan penerimaan bisnis; UAT tetap wajib (Step 20).
- **BLOCKED (keputusan)**: mekanismenya ada dan teruji, tetapi requirement tidak dapat dinyatakan lulus sebelum keputusan bisnis terkait disetujui.
- **BELUM**: belum ada bukti.

Singkatan lokasi test: `F/` = `tests/Feature/`, `U/` = `tests/Unit/`, `B/` = `tests/Browser/` (Playwright), `P/` = `tests/Performance/`.

## 1. Kriteria penerimaan rilis inti (PRD §16)

| # | Kriteria | Keputusan terkait | Test otomatis | Bukti lain | Status |
|---|---|---|---|---|---|
| AC-01 | 3 group/department dan 21 team dengan hubungan atasan dan tanggal efektif | — | `F/Organization/ReferenceStructureTest`, `F/Organization/OrganizationHistoryTest` | Seeder referensi; dataset performa memakai ke-21 team | TERBUKTI (teknis). Rekonsiliasi master pegawai nyata menunggu DEC-016 |
| AC-02 | Setiap peran hanya melihat dan menjalankan aksi yang diizinkan | DEC-002 (approver) | `U/IdentityAccess/RoleCapabilityMatrixTest`, `F/IdentityAccess/AuthorizationScopeTest`, `F/IdentityAccess/AdministrationAccessTest`, `F/IdentityAccess/DelegationTest`, `F/Workflow/ApprovalPagesTest`, `F/Security/HardeningTest`, dan test akses per modul (`docs/security/AUTHORIZATION_REVIEW.md`); browser: `B/AccessAndAccessibilityTest` (direct URL 403/404 untuk 6 kombinasi peran) | Tinjauan entry point Step 18 | TERBUKTI (teknis). Rantai approver nyata BLOCKED (DEC-002) |
| AC-03 | HR membuat periode dan menerbitkan template berversi dengan total bobot 100% | DEC-003 (bobot), DEC-006 | `F/KpiGovernance/KpiTemplateTest`, `U/KpiGovernance/ScoringRulesTest`, `F/PeriodAssignment/PeriodLifecycleTest`, `F/PeriodAssignment/PeriodPagesTest`; browser: `B/CoreJourneyTest` (HR membuka periode) | — | Mekanisme TERBUKTI; template resmi BLOCKED (DEC-003, DEC-006: belum ada KPI/template yang disetujui) |
| AC-04 | Target dan realisasi diisi/diimpor, divalidasi, dilengkapi bukti, diproses sampai approval final | DEC-004, DEC-006, DEC-002 | `F/Target/*`, `F/ActualEvidence/*`, `F/Workflow/ApprovalWorkflowTest`; browser: `B/CoreJourneyTest` (pegawai mengisi dan mengajukan, bukti terlihat di UI, TL memverifikasi, Manager review, HR kalibrasi, Management approval final) | Unggah bukti lewat Livewire diuji di `F/ActualEvidence/ActualPagesTest` (lihat KI-04) | Mekanisme TERBUKTI dengan policy TEST-ONLY; alur resmi BLOCKED (DEC-004) |
| AC-05 | Scorecard dapat direkonsiliasi dari target, aktual, formula, skor KPI, bobot, kontribusi | DEC-004 | `U/Scoring/*` (termasuk `GoldenExamplesTest`), `F/Scoring/ScoringServiceTest`, `F/Scoring/ScorecardPagesTest` (ekspor rekonsiliasi) | `docs/product/SCORING_GOLDEN_EXAMPLES.md` (sintetis) | Mesin TERBUKTI terhadap contoh sintetis; **golden example bisnis belum ditandatangani** → BLOCKED (DEC-004) |
| AC-06 | Data final tidak dapat ditimpa; koreksi menghasilkan versi baru dan audit | DEC-002 (kewenangan koreksi) | `F/Workflow/CorrectionTest`, `F/Workflow/CorrectionGuardTest`, `F/Release/ReleaseRegressionTest` (lock vs edit aktual/target, koreksi vs ekspor); browser: `B/CoreJourneyTest` (input aktual hilang setelah lock, koreksi HR disetujui Management, perhitungan baru menunjuk perhitungan lama) | — | TERBUKTI (teknis). Kewenangan koreksi nyata BLOCKED (DEC-002) |
| AC-07 | Dashboard TL: anggota, pending verification, missing actual, KPI merah, masalah, corrective action | DEC-004 (ambang merah), DEC-009 | `F/Reporting/DashboardTest`, `F/TeamReview/*`; browser: halaman TL lulus scan aksesibilitas | — | TERBUKTI kecuali KPI merah: BLOCKED (ambang belum disetujui) |
| AC-08 | HR kalibrasi; periode hanya dikunci setelah kelengkapan dan approval | DEC-002, DEC-004 | `F/Workflow/ApprovalWorkflowTest`, `F/Workflow/ApprovalPagesTest` (checklist lock), `F/Workflow/CorrectionTest` (koreksi tertunda menahan lock); browser: `B/CoreJourneyTest` (kalibrasi, finalisasi, dan lock lewat UI setelah checklist lulus) | — | TERBUKTI (teknis) dengan policy TEST-ONLY |
| AC-09 | Management melihat ringkasan perusahaan dan drill-down sesuai hak | — | `F/Reporting/DashboardTest`, `F/Reporting/ReportCatalogTest`; browser: halaman Management lulus scan | — | TERBUKTI (teknis); skor resmi kosong selama DEC-004 terbuka |
| AC-10 | Auditor menelusuri definisi, sumber, bukti, approval chain, perubahan tanpa hak edit | — | `F/IdentityAccess/AuthorizationScopeTest` (Auditor read-only), `F/Reporting/ReportCatalogTest` (audit log); browser: `B/CoreJourneyTest` (Auditor melihat audit koreksi dan riwayat approval, tanpa kontrol ubah) | — | TERBUKTI (teknis) |
| AC-11 | Ekspor mengikuti filter dan hak akses, mencantumkan metadata periode dan versi | DEC-010 (retensi) | `F/Reporting/ReportExportTest`, `F/Release/ReleaseRegressionTest` (file lama tidak berubah setelah koreksi; ekspor baru memuat skor terkoreksi); browser: `B/CoreJourneyTest` (ekspor Excel dari UI) | — | TERBUKTI (teknis); retensi data BLOCKED (DEC-010) |
| AC-12 | UAT disetujui Management, HR, dan unit pilot tanpa defect kritis terbuka | DEC-007 | — | `docs/product/UAT_SIGNOFF_MATRIX.md` (semua OPEN) | BELUM (Step 20) |

## 2. Kebutuhan nonfungsional (PRD §15)

| NFR | Kebutuhan | Test / bukti | Status |
|---|---|---|---|
| 001 Keandalan transaksi | Tidak ada status parsial | `F/DataFoundation/AuditAndTransactionTest`, `F/DataFoundation/IdempotencyTest`, `F/ActualEvidence/ActualLifecycleTest` (batch all-or-nothing), `F/Workflow/ApprovalWorkflowTest` (double click satu keputusan), `F/Release/ReleaseRegressionTest` (submit ganda) | TERBUKTI (teknis) |
| 002 Kinerja | Halaman standar ≤ 3 detik pada beban yang disepakati | `P/PageResponseTest`; `PERFORMANCE_REPORT.md` | Terukur pada dataset sintetis: p95 tertinggi 0,55 detik (PostgreSQL). **Beban yang disepakati belum ada (DEC-007)**, konkurensi belum diukur → BLOCKED |
| 003 Ketersediaan | SLA dan maintenance window bersama IT | — | BLOCKED (DEC-010, DEC-014) |
| 004 Waktu | Simpan konsisten, tampil Asia/Jakarta | `F/Smoke/ApplicationBootTest`, `U/DataFoundation/ConventionsTest`, `F/Target/TargetLifecycleTest` (cut-off), `F/ActualEvidence/ActualLifecycleTest` (late entry), `U/Production/ShiftCalendarTest` (shift lewat tengah malam) | TERBUKTI (teknis); kalender shift resmi BLOCKED (DEC-015) |
| 005 Usability | Filter, urut, cari, ekspor; status dan tindakan jelas | Test halaman per modul; browser journey | TERBUKTI sebagian; penilaian pengguna di UAT |
| 006 Responsive | Desktop dan tablet | `B/AccessAndAccessibilityTest` (iPad Mini: halaman aktual TL dan review Manager, termasuk scan) | TERBUKTI untuk halaman yang diuji; mobile operator divalidasi di pilot |
| 007 Aksesibilitas | Keyboard, label, kontras, fokus, error tidak hanya warna | `B/AccessAndAccessibilityTest`: axe-core (serious + critical) pada 26 halaman harian tujuh peran, 2 halaman tablet, dan halaman login, login hanya dengan keyboard; 3 temuan diperbaiki (D-01 s.d. D-03) | TERBUKTI untuk aturan otomatis axe. **Review manual dengan screen reader belum dilakukan** |
| 008 Observability | Job, integrasi, error, aktivitas sensitif dipantau | `F/Operations/*` (Step 18) | Mekanisme TERBUKTI; alert aktif BLOCKED (DEC-014) |
| 009 Backup dan recovery | RPO, RTO, uji restore | Rehearsal Step 18 (`docs/operations/BACKUP_RESTORE.md`) | Rehearsal lokal TERBUKTI; target BLOCKED (DEC-010) |
| 010 Kompatibilitas | Browser perusahaan ditetapkan dan diuji sebelum UAT | Suite browser lulus di Chromium, Firefox, dan WebKit (lokal); CI menjalankan Chromium | Teruji di tiga mesin; **daftar browser resmi belum ditetapkan (DEC-010)** → BLOCKED |

## 3. Audit, keamanan, dan kepatuhan (PRD §14)

| Area | Test / bukti | Status |
|---|---|---|
| Access control | AC-02 di atas | TERBUKTI (teknis) |
| Authentication | `F/Auth/*`, `F/IdentityAccess/AccountLifecycleTest`, `F/Security/HardeningTest`; browser: login keyboard dan password salah | Login lokal TERBUKTI; SSO/MFA BLOCKED (DEC-013) |
| Audit trail | `F/DataFoundation/AuditAndTransactionTest`, audit per modul, `B/CoreJourneyTest` (audit koreksi terbaca Auditor) | TERBUKTI (teknis) |
| Data protection | `F/ActualEvidence/EvidenceTest`, `F/TeamReview/TeamReviewPagesTest` (coaching notes), `F/Security/HardeningTest` | TERBUKTI (teknis); klasifikasi coaching notes BLOCKED (DEC-011); TLS produksi BLOCKED (DEC-014) |
| Period lock | AC-06 dan AC-08 | TERBUKTI (teknis) |
| Segregation of duties | `F/IdentityAccess/AuthorizationScopeTest` (System Admin tidak mengubah nilai), `F/Workflow/CorrectionGuardTest` (pemohon tidak menyetujui koreksinya), `F/Workflow/ApprovalWorkflowTest` (tidak memutus scorecard sendiri) | TERBUKTI (teknis) |
| Export control | AC-11; `F/Reporting/ReportExportTest` (unduhan dicek ulang dan diaudit) | TERBUKTI (teknis) |
| Retention | — | BLOCKED (DEC-010) |

## 4. Pekerjaan Step 19 per butir prompt

| Butir prompt | Bukti | Status |
|---|---|---|
| 1 Traceability matrix | Dokumen ini | Selesai untuk core readiness |
| 2 Test pyramid | Unit/feature/integration (`php artisan test`), kontrak integrasi (`F/Integration/InboundPipelineTest`, fixture `tests/Fixtures/integration`), E2E Playwright (`tests/Browser`), security regression (`F/Security/HardeningTest` + test akses), aksesibilitas dan responsive (`B/AccessAndAccessibilityTest`), performa (`P/PageResponseTest`) | Lengkap; beban performa yang disepakati BLOCKED (DEC-007) |
| 3 Journey utama | `B/CoreJourneyTest`: periode dibuka → aktual + bukti → verifikasi TL → hitung → review Manager → kalibrasi HR → approval final Management → finalisasi → lock → koreksi → ekspor → audit | Lulus dengan policy TEST-ONLY. Publish template lewat UI tidak termasuk; publish template diuji di `F/KpiGovernance/KpiTemplateTest` |
| 4 Role/scope positif dan negatif | Direct URL, forged Livewire action, unduhan, ekspor, delegasi kedaluwarsa: lihat `docs/security/AUTHORIZATION_REVIEW.md`; browser: 6 direct URL | Lulus |
| 5 Concurrency | Submit ganda (`F/Release`), double approval (`F/Workflow/ApprovalWorkflowTest`), hitung ulang idempoten (`F/Scoring/ScoringServiceTest`), import berulang (`F/ActualEvidence/ActualImportTest`, `F/Target/TargetImportTest`), retry queue (`F/Notification/NotificationTest`, `F/Reporting/ReportExportTest`), lock vs edit dan koreksi vs ekspor (`F/Release`), optimistic lock (`F/DataFoundation/ObjectMetadataAndConcurrencyTest`) | Lulus. Perhitungan ulang yang benar-benar paralel (dua proses) tidak diuji; dijaga row lock `lockForUpdate` di `ScoringService` |
| 6 Data boundary | Nol, negatif, missing, N/A, presisi desimal, nilai besar, batas tanggal Asia/Jakarta, shift lewat tengah malam, tanggal efektif: `U/Scoring/*`, `U/Target/TargetValueRulesTest`, `F/ActualEvidence/ActualLifecycleTest`, `F/ActualEvidence/ActualImportTest`, `F/Production/ProductionImportTest`, `U/Production/ShiftCalendarTest`, `F/KpiGovernance/VersionTimelineTest`, `F/IdentityAccess/AuthorizationScopeTest` | Lulus. Pergantian periode (rollover) dicakup snapshot penugasan (`F/PeriodAssignment/AssignmentSnapshotTest`) |
| 7 Performa | `PERFORMANCE_REPORT.md` | Terukur; target beban BLOCKED (DEC-007) |
| 8 Browser matrix dan aksesibilitas | Chromium, Firefox, WebKit; axe-core; login keyboard | Lulus; review manual screen reader BELUM |
| 9 Flaky | Penantian berbasis pesan aksi, penantian konten setelah navigasi; suite browser lulus 3× berturut-turut di Chromium dan sekali di Firefox/WebKit | Tidak ada test yang di-skip tanpa alasan (`RELEASE_TEST_REPORT.md` §6) |
| 10 Laporan | `RELEASE_TEST_REPORT.md`, `EVIDENCE_INDEX.md`, `PERFORMANCE_REPORT.md` | Selesai |
