# Decision Register — Sistem KPI PT Berlian Artha Label

Dokumen ini adalah daftar keputusan yang harus dikunci sebelum aturan KPI digunakan secara resmi. Sumber utama adalah `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx` versi 0.1 tanggal 12 Oktober 2026.

## Status keputusan

- `APPROVED`: terdapat persetujuan eksplisit dan bukti yang dapat ditelusuri.
- `PROPOSED`: rekomendasi sudah tersedia tetapi belum disetujui pihak berwenang.
- `OPEN`: jawaban atau pemilik keputusan belum lengkap.
- `DEFERRED`: sengaja tidak masuk release aktif dan mempunyai kondisi untuk dibuka kembali.

Keputusan hanya boleh diubah menjadi `APPROVED` setelah kolom bukti persetujuan diisi. Percakapan informal yang tidak menyebut keputusan secara jelas tidak dianggap sign-off bisnis.

## Keputusan teknis yang sudah dikonfirmasi

| ID | Area | Keputusan | Status | Pemilik | Bukti |
|---|---|---|---|---|---|
| TECH-001 | Application stack | Full-stack Laravel: Laravel 13, PHP 8.4, Blade, Livewire, Alpine.js, dan Tailwind CSS | APPROVED | Project Owner | Konfirmasi pengguna 12 Oktober 2026: menggunakan full-stack Laravel |
| TECH-002 | Application shape | Satu modular monolith; web, worker, dan scheduler memakai source/image yang sama tetapi berjalan sebagai process terpisah | PROPOSED | IT/Project Owner | Menunggu review arsitektur Step 01 |
| TECH-003 | Data platform | PostgreSQL sebagai source of truth, Redis untuk cache/queue, object storage private untuk evidence | PROPOSED | IT | Menunggu konfirmasi environment dan provider |
| TECH-004 | Version control | Repository resmi `https://github.com/gilbertusk/kpi-berlianartha.git`; satu commit dan push per step | APPROVED | Project Owner | Instruksi pengguna 12 Oktober 2026 |

## Keputusan bisnis dan operasional

| ID | Area | Pertanyaan/keputusan yang dibutuhkan | Rekomendasi awal | Dampak bila terbuka | Pemilik keputusan | Status | Target keputusan | Bukti persetujuan |
|---|---|---|---|---|---|---|---|---|
| DEC-001 | Design A/B | Apa perbedaan tanggung jawab, proses, produk, atau customer Design A dan Design B? | Pertahankan sebagai dua team terpisah; template boleh sama tetapi target, actual, dan scorecard tidak digabung | Mapping KPI, source data, dan ownership tidak dapat difinalkan | Head terkait, HR Performance | OPEN | Sebelum publish template pilot | — |
| DEC-002 | Approval chain | Siapa Team Leader, Manager/Head, approver pengganti, serta jalur ketika posisi kosong untuk setiap team? | Rantai approval ditetapkan per team pilot; pengganti hanya dengan persetujuan Management tertulis | Workflow dan UAT approval terblokir | Management, HR Performance | APPROVED | Sebelum Step 10/UAT | MANAGEMENT_HR_DECISIONS_STEP20.md §2 |
| DEC-003 | Bobot scorecard | Apakah komposisi staff dan Team Leader pada PRD disetujui atau berbeda per team/jabatan? | Komposisi PRD disetujui sebagai bobot awal pilot, total 100% | Scorecard resmi tidak dapat dipublish | Management, HR Performance, unit owner | APPROVED | Sebelum Step 06 publish | MANAGEMENT_HR_DECISIONS_STEP20.md §3 |
| DEC-004 | Scoring/rating | Apa rating band, cap/floor, toleransi, rounding, zero denominator, N/A, missing data, dan late-data rule? | Aturan scoring pilot ditetapkan; strategi berversi, N/A diabaikan dari bobot, missing tidak diperlakukan nol | Scoring engine resmi terblokir | Management, HR Performance | APPROVED | Sebelum Step 09 | MANAGEMENT_HR_DECISIONS_STEP20.md §4 |
| DEC-005 | KPI release scope | KPI mana yang wajib untuk rilis inti, pilot, dan tahap berikutnya? | Tiga team pilot dengan KPI minimum per team; sisa kandidat tetap DRAFT | Scope, estimasi, dan UAT tidak stabil | Management, HR, unit owner | APPROVED | Sebelum backlog release dikunci | MANAGEMENT_HR_DECISIONS_STEP20.md §5 |
| DEC-006 | KPI definition | Apa target, formula, unit, frequency, source, owner, evidence, approver, dan exception tiap KPI aktif? | Definisi lengkap wajib untuk tiap KPI pilot, disetujui HR dan unit owner | KPI tidak boleh dipublish | Process owner, data owner, HR | APPROVED | Sebelum Step 06 publish | MANAGEMENT_HR_DECISIONS_STEP20.md §6 |
| DEC-007 | Pilot | Team mana yang menjadi pilot serta berapa pengguna, concurrent user, dan volume data? | Sales, Account Receivable, Woven; 22 pengguna, 10 concurrent, 200 entri/hari | Sizing, test data, training, dan UAT tidak dapat dikunci | Management, HR, IT | APPROVED | Sebelum performance plan dan staging | MANAGEMENT_HR_DECISIONS_STEP20.md §1 |
| DEC-008 | Integrasi | Sistem apa yang diintegrasikan, metode akses, owner, SLA, dan environment sandbox-nya? | Mulai dengan file import terkontrol; aktifkan connector setelah data contract disetujui | Integrasi runtime dan otomatisasi tetap blocked | IT, system owner, process owner | OPEN | Sebelum Step 17 | — |
| DEC-009 | SLA workflow | Berapa SLA input, verification, review, calibration, final approval, correction, notification escalation, dan corrective action? | Definisikan per jenis task dan kalender kerja; escalation harus idempotent | Reminder, overdue, dan escalation tidak dapat diuji | Management, HR, unit owner | OPEN | Sebelum Step 11 | — |
| DEC-010 | Retention dan service level | Berapa retention data/evidence/audit, RPO, RTO, availability, maintenance window, dan browser yang didukung? | Data 2 tahun, bukti 1 tahun, audit 5 tahun; RPO ≤ 24 jam, RTO ≤ 4 jam; browser Chrome/Firefox/Safari | Production readiness dan compliance terblokir | HR, Legal, IT | APPROVED | Sebelum staging sign-off | MANAGEMENT_HR_DECISIONS_STEP20.md §7 |
| DEC-011 | Data sensitif | Apakah coaching notes termasuk data HR terbatas dan siapa yang boleh membaca/mengubahnya? | Klasifikasikan restricted; default hanya subject, TL berwenang, dan HR sesuai purpose; auditor sesuai mandate | Risiko kebocoran data personal | HR, Legal, Management | PROPOSED | Sebelum implementasi coaching notes | — |
| DEC-012 | Kompensasi | Apakah skor KPI digunakan untuk bonus/payroll/kompensasi? | Di luar scope release sampai ada kebijakan terpisah, legal review, formula, dispute process, dan sign-off | Risiko finansial dan hubungan industrial | Management, HR, Finance/Legal | DEFERRED | Sebelum fitur kompensasi apa pun | — |
| DEC-013 | Authentication | Apakah login lokal, Microsoft Entra ID, atau identity provider lain yang dipakai? Apakah MFA wajib? | Login lokal untuk pilot, SSO ditunda ke production, MFA opsional untuk pilot | Desain onboarding, session, dan production access belum final | IT/Security | APPROVED | Sebelum production auth | MANAGEMENT_HR_DECISIONS_STEP20.md §8 |
| DEC-014 | Hosting | Cloud/provider, region, domain, TLS, email, secret manager, serta owner operasional | Staging terpisah dengan PostgreSQL, Redis, private S3, domain TLS, email test, secret manager di luar repo | Deployment architecture dan biaya belum final | IT, Management | APPROVED | Sebelum Step 03 production design | MANAGEMENT_HR_DECISIONS_STEP20.md §9 |
| DEC-015 | Production calendar | Definisi shift, overnight shift, hari kerja, cut-off, dan tanggal operasional | Simpan kalender/shift bertanggal efektif; uji boundary Asia/Jakarta | Agregasi produksi dan SLA dapat salah | Production, HR, PPIC | OPEN | Sebelum modul produksi | — |
| DEC-016 | Master/source ownership | Siapa system of record dan owner untuk employee, customer, product, material, work order, machine, defect, downtime, reason code, dan warehouse location? | Tetapkan satu owner per master dan larang duplicate uncontrolled master | Validasi referensi dan rekonsiliasi tidak dapat difinalkan | IT dan unit owner | OPEN | Sebelum integrasi/modul khusus | — |

## Draft bobot dari PRD — belum disetujui

### Staff operasional

| Komponen | Bobot draft |
|---|---:|
| KPI individu | 50% |
| KPI team | 30% |
| Disiplin dan keselamatan | 10% |
| Kompetensi dan perilaku | 10% |
| **Total** | **100%** |

### Team Leader

| Komponen | Bobot draft |
|---|---:|
| Pencapaian KPI team | 55% |
| Kualitas dan produktivitas | 15% |
| Leadership dan pengelolaan anggota | 15% |
| Improvement | 10% |
| Keselamatan dan kedisiplinan | 5% |
| **Total** | **100%** |

Angka tersebut tidak boleh digunakan sebagai kebijakan resmi sebelum DEC-003 berstatus `APPROVED`.

## Urutan workshop yang direkomendasikan

1. Management dan HR: DEC-003, DEC-004, DEC-005, DEC-007, DEC-009, DEC-012.
2. HR dan seluruh unit owner: DEC-001, DEC-002, DEC-006, DEC-011.
3. IT dan data owner: DEC-008, DEC-010, DEC-013, DEC-014, DEC-016.
4. Production, PPIC, dan HR: DEC-015.

## Gate implementasi

- Aman dimulai saat keputusan masih terbuka: arsitektur, Laravel skeleton, local infrastructure, audit foundation, organization model draft, dan generic authorization framework.
- Tidak boleh difinalkan: published KPI template, production scoring, approval routing resmi, SLA escalation, SSO production, integration production, retention purge, dan compensation logic.

