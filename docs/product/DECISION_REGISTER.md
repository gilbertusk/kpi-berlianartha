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
| DEC-002 | Approval chain | Siapa Team Leader, Manager/Head, approver pengganti, serta jalur ketika posisi kosong untuk setiap team? | Konfigurasi per team dan effective date; izinkan TL langsung ke HR/Management hanya dengan persetujuan | Workflow dan UAT approval terblokir | Management, HR Performance | OPEN | Sebelum Step 10/UAT | — |
| DEC-003 | Bobot scorecard | Apakah komposisi staff dan Team Leader pada PRD disetujui atau berbeda per team/jabatan? | Jadikan komposisi PRD sebagai draft; total template wajib 100% | Scorecard resmi tidak dapat dipublish | Management, HR Performance, unit owner | PROPOSED | Sebelum Step 06 publish | — |
| DEC-004 | Scoring/rating | Apa rating band, cap/floor, toleransi, rounding, zero denominator, N/A, missing data, dan late-data rule? | Gunakan strategy berversi dan golden example yang ditandatangani; jangan memberi missing sebagai nol secara otomatis | Scoring engine resmi terblokir | Management, HR Performance | OPEN | Sebelum Step 09 | — |
| DEC-005 | KPI release scope | KPI mana yang wajib untuk rilis inti, pilot, dan tahap berikutnya? | Pilih set minimum yang mewakili manual, import, higher/lower, dan evidence; semua KPI lampiran tetap kandidat sampai disetujui | Scope, estimasi, dan UAT tidak stabil | Management, HR, unit owner | OPEN | Sebelum backlog release dikunci | — |
| DEC-006 | KPI definition | Apa target, formula, unit, frequency, source, owner, evidence, approver, dan exception tiap KPI aktif? | Gunakan `KPI_DEFINITION_TEMPLATE.md` dan sign-off per owner | KPI tidak boleh dipublish | Process owner, data owner, HR | OPEN | Sebelum Step 06 publish | — |
| DEC-007 | Pilot | Team mana yang menjadi pilot serta berapa pengguna, concurrent user, dan volume data? | Minimal satu Commercial/Support, satu Accounting, satu Produksi sesuai PRD | Sizing, test data, training, dan UAT tidak dapat dikunci | Management, HR, IT | OPEN | Sebelum performance plan dan staging | — |
| DEC-008 | Integrasi | Sistem apa yang diintegrasikan, metode akses, owner, SLA, dan environment sandbox-nya? | Mulai dengan file import terkontrol; aktifkan connector setelah data contract disetujui | Integrasi runtime dan otomatisasi tetap blocked | IT, system owner, process owner | OPEN | Sebelum Step 17 | — |
| DEC-009 | SLA workflow | Berapa SLA input, verification, review, calibration, final approval, correction, notification escalation, dan corrective action? | Definisikan per jenis task dan kalender kerja; escalation harus idempotent | Reminder, overdue, dan escalation tidak dapat diuji | Management, HR, unit owner | OPEN | Sebelum Step 11 | — |
| DEC-010 | Retention dan service level | Berapa retention data/evidence/audit, RPO, RTO, availability, maintenance window, dan browser yang didukung? | Tetapkan bersama HR/Legal/IT dan lakukan restore rehearsal | Production readiness dan compliance terblokir | HR, Legal, IT | OPEN | Sebelum staging sign-off | — |
| DEC-011 | Data sensitif | Apakah coaching notes termasuk data HR terbatas dan siapa yang boleh membaca/mengubahnya? | Klasifikasikan restricted; default hanya subject, TL berwenang, dan HR sesuai purpose; auditor sesuai mandate | Risiko kebocoran data personal | HR, Legal, Management | PROPOSED | Sebelum implementasi coaching notes | — |
| DEC-012 | Kompensasi | Apakah skor KPI digunakan untuk bonus/payroll/kompensasi? | Di luar scope release sampai ada kebijakan terpisah, legal review, formula, dispute process, dan sign-off | Risiko finansial dan hubungan industrial | Management, HR, Finance/Legal | DEFERRED | Sebelum fitur kompensasi apa pun | — |
| DEC-013 | Authentication | Apakah login lokal, Microsoft Entra ID, atau identity provider lain yang dipakai? Apakah MFA wajib? | Gunakan SSO perusahaan dan MFA jika tersedia; local auth hanya untuk development/fallback terkontrol | Desain onboarding, session, dan production access belum final | IT/Security | OPEN | Sebelum production auth | — |
| DEC-014 | Hosting | Cloud/provider, region, domain, TLS, email, secret manager, serta owner operasional | Pilih berdasarkan standar IT; pisahkan dev/staging/production | Deployment architecture dan biaya belum final | IT, Management | OPEN | Sebelum Step 03 production design | — |
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

