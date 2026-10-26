# Release Test Report — Step 19 run 1 (core readiness)

**Tanggal:** 26 Oktober 2026
**Scope:** fondasi produk inti (Step 02–12) beserta modul Step 13–18 yang sudah dibangun, sebagai *core readiness* sebelum pilot. Sesuai prompt, Step 19 diulang sebagai full regression setelah fitur khusus dan integrasi diaktifkan.
**Status:** **PARTIAL.** Gerbang teknis yang dapat dibuktikan tanpa keputusan bisnis lulus: authorization negatif, journey inti di browser, aksesibilitas otomatis, tidak ada defect Critical/High pada produk, dan tidak ada flaky test. Gerbang yang bergantung pada keputusan bisnis tetap BLOCKED: golden example bertanda tangan (DEC-004), beban performa (DEC-007), browser resmi (DEC-010), dan UAT (Step 20).

Dokumen pendamping: `TRACEABILITY_MATRIX.md` (requirement → test → status), `PERFORMANCE_REPORT.md`, `EVIDENCE_INDEX.md`.

## 1. Ringkasan hasil

| Kategori | Perintah | Environment | Hasil |
|---|---|---|---|
| Unit + Feature (suite default) | `php artisan test` | PHP 8.4.25, SQLite in-memory | 1.069 test: 1.066 lulus, 3 skip, 0 gagal (15.141 assertion, 563 detik) |
| Unit + Feature | `bash scripts/dev.sh test-pgsql` | Compose: PHP 8.5.11, PostgreSQL 18, Redis | 1.069 test: 1.067 lulus, 2 skip, 0 gagal (15.157 assertion, 922 detik) |
| E2E browser — Chromium | `php artisan test --testsuite=Browser` | Playwright 1.64.0, Windows 11 | 18 test, 18 lulus, 196 assertion; lulus 3× berturut-turut |
| E2E browser — Firefox | `… --testsuite=Browser --browser firefox` | Firefox 157.0 (Playwright) | 18/18 lulus |
| E2E browser — WebKit | `… --testsuite=Browser --browser safari` | WebKit 27.2 (Playwright) | 18/18 lulus |
| Performa | `bash scripts/dev.sh test-pgsql --testsuite=Performance` | Compose PostgreSQL 18 | 1 test lulus (16 halaman × 20 sampel); p95 tertinggi 547 ms |
| Format | `vendor/bin/pint --test` | — | lulus |
| Static analysis | `vendor/bin/phpstan analyse` (level 7) | — | 0 error |
| Dependency PHP | `composer validate --strict`, `composer audit` | — | valid; tidak ada advisory (setelah Livewire 4.4.8) |
| Dependency npm | `npm audit --audit-level=high` | — | **gagal** saat Step 19: N-01 (`vite-plus` 0.3.0, dev tooling), lihat §5. Setelah migrasi `vite-plus` 1.4.0 (26 Oktober 2026): 0 kerentanan |
| Build | `npm run build` | — | berhasil |

### Test per kategori (suite default, SQLite)

| Kategori | Test | Lulus | Skip | Gagal |
|---|---:|---:|---:|---:|
| Unit: scoring (strategi, policy, golden example sintetis, properti) | 163 | 163 | 0 | 0 |
| Unit: lain (target, KPI governance, produksi, accounting, identitas, konvensi data, arsitektur, guard database) | 140 | 140 | 0 | 0 |
| Feature: autentikasi dan pengaturan akun (Fortify) | 29 | 27 | 2 | 0 |
| Feature: identitas, akses, organisasi | 129 | 129 | 0 | 0 |
| Feature: KPI governance, periode, target | 145 | 145 | 0 | 0 |
| Feature: aktual dan bukti | 73 | 72 | 1 | 0 |
| Feature: scoring, workflow approval, koreksi | 96 | 96 | 0 | 0 |
| Feature: dashboard, notifikasi, team review, laporan dan ekspor | 88 | 88 | 0 | 0 |
| Feature: modul Accounting, Produksi/PPIC, Quality | 54 | 54 | 0 | 0 |
| Feature: integrasi dan migrasi (termasuk kontrak inbound) | 37 | 37 | 0 | 0 |
| Feature: operasional dan catatan Commercial/Support, keamanan, data foundation, smoke, UI | 112 | 112 | 0 | 0 |
| Feature: regresi rilis Step 19 | 3 | 3 | 0 | 0 |
| **Jumlah** | **1.069** | **1.066** | **3** | **0** |

Di PostgreSQL, test constraint `CHECK` aktual ikut berjalan (khusus PostgreSQL), sehingga yang di-skip hanya 2.

## 2. Cakupan domain kritis

Coverage baris tidak diukur: PHP lokal dan image CI tidak memasang driver coverage (pcov/Xdebug), dan menambahkannya di luar scope step ini. Cakupan dinilai per perilaku melalui `TRACEABILITY_MATRIX.md`.

| Domain kritis | Bukti utama | Catatan |
|---|---|---|
| Scoring | 54 test unit (`tests/Unit/Scoring`) + `ScoringServiceTest`, `ScorecardPagesTest` | Cocok dengan golden example **sintetis**; contoh bisnis belum ditandatangani (DEC-004) |
| Authorization | `RoleCapabilityMatrixTest`, `AuthorizationScopeTest`, test akses per modul, `HardeningTest`, 6 direct URL di browser | Setiap entry point dipetakan di `docs/security/AUTHORIZATION_REVIEW.md` |
| Workflow approval, lock, koreksi | `ApprovalWorkflowTest`, `ApprovalPagesTest`, `CorrectionTest`, `CorrectionGuardTest`, `ReleaseRegressionTest`, journey browser | Dengan policy TEST-ONLY |
| Transaksi, idempotency, audit | `tests/Feature/DataFoundation/*`, import berulang, retry queue | — |
| Ekspor | `ReportExportTest`, `ReleaseRegressionTest` | Termasuk koreksi vs ekspor |

## 3. Journey utama (E2E)

`tests/Browser/CoreJourneyTest.php` menjalankan satu scorecard lewat UI di browser sungguhan, dengan akun yang memegang setiap keputusan:

1. Pegawai membuka halaman aktual (tidak melihat subjek di luar scope), mengisi nilai, menyimpan draft, melihat bukti terlampir, lalu mengajukan.
2. Team Leader memverifikasi aktual timnya.
3. HR menghitung scorecard dan memindahkan periode ke Review setelah checklist lulus.
4. Department Head menyetujui review, HR mengkalibrasi, Management memberi approval final.
5. HR memfinalisasi dan mengunci periode; input aktual hilang dari halaman pegawai.
6. HR meminta koreksi; Management menyetujui; perhitungan baru menunjuk perhitungan lama dan periode tetap Locked.
7. HR menampilkan dan mengekspor laporan scorecard ke Excel (antrean → selesai).
8. Auditor membaca audit koreksi dan riwayat approval tanpa kontrol ubah.

Test kedua: HR membuka periode lewat UI. Test aksesibilitas/otorisasi: 7 peran membuka halaman harian (26 halaman), 2 halaman di viewport tablet (iPad Mini), 6 direct URL di luar peran (403/404), login hanya dengan keyboard, dan password salah.

Yang dikerjakan lewat service, bukan klik (setup, bukan bagian journey): data scorecard lain di periode yang sama, bypass review team tanpa reviewer, dan lampiran bukti (lihat KI-04). Publish KPI/template lewat UI tidak termasuk journey browser; perilakunya diuji di `KpiDefinitionLifecycleTest` dan `KpiTemplateTest`.

## 4. Aksesibilitas

- Otomatis: axe-core (dari pest-plugin-browser), aturan WCAG dengan dampak *serious* dan *critical*, pada semua halaman di §3, di tiga mesin browser.
- Keyboard: login diisi dan dikirim hanya dengan keyboard; fokus awal di email, Tab ke password, Enter mengirim.
- Temuan dan perbaikan: D-01, D-02, D-03 (§5). Setelah perbaikan, tidak ada pelanggaran serious/critical pada halaman yang diuji.
- **Belum dilakukan:** review manual dengan screen reader (NVDA/VoiceOver) dan pemeriksaan urutan fokus di dialog/modal. Dijadwalkan bersama UAT (Step 20).

## 5. Defect

Severity mengikuti gerbang defect `docs/product/UAT_SIGNOFF_MATRIX.md`.

| ID | Temuan | Severity | Status | Perbaikan / pemilik |
|---|---|---|---|---|
| D-01 | Dashboard: `<dl>` "Menunggu tindakan Anda" berisi tautan di luar `<dt>/<dd>` (axe `definition-list`, serious) | Medium | **Ditutup** | Tautan dibungkus `<dd>` |
| D-02 | Pagination Livewire: `aria-label` pada `<span>` tanpa role (axe `aria-prohibited-attr`, serious); label "&laquo; Previous" dibacakan dengan entitas mentah | Medium | **Ditutup** | Override `resources/views/vendor/livewire/tailwind.blade.php`: teks sr-only, label "Halaman sebelumnya/berikutnya" |
| D-03 | Judul grup "Menu" di sidebar: kontras 2,41:1 (axe `color-contrast`, serious; WCAG AA butuh 4,5:1) | Low | **Ditutup** | Judul sendiri `text-zinc-600` / `dark:text-zinc-300` |
| D-04 | N+1: bypass review dicari per scorecard di dashboard, inbox, dan alur approval (396 query pada 316 scorecard) | Medium | **Ditutup** | `WorkflowService::reviewBypassesInForce()`; test `WorkflowQueryEfficiencyTest` |
| D-05 | `livewire/livewire` 4.4.7: GHSA-mhf7-xp6r-6qrc (bypass `#[Authorize]`) | Medium | **Ditutup** | Naik ke 4.4.8. Aplikasi tidak memakai atribut itu |
| D-06 | N-01: `vite-plus` 0.3.0 membawa advisory high/moderate pada dev server Vite, `launch-editor`, dan Vitest; gerbang CI `npm audit --audit-level=high` gagal | Medium (advisory: high; tidak ada eksposur produksi) | **Ditutup** | `vite-plus` 1.4.0 dengan alias `vite` → `@voidzero-dev/vite-plus-core@1.4.0` (26 Oktober 2026). `npm audit --audit-level=high` 0 kerentanan, `npm run build` berhasil, suite Browser (Chromium) 18/18 lulus, `docker build --target http` berhasil. Pemilik: Engineering Lead |

Ringkas: Critical 0, High 0, Medium 5 (semua ditutup; D-06 ditutup 26 Oktober 2026), Low 1 (ditutup).

### Known issue (bukan defect produk)

| ID | Isu | Dampak | Workaround / keputusan |
|---|---|---|---|
| KI-01 | Skor resmi BLOCKED: DEC-004 terbuka, `config/scoring.php` kosong | Tidak ada scorecard resmi; journey memakai policy TEST-ONLY | Keputusan bisnis DEC-004 |
| KI-02 | Beban performa belum disepakati (DEC-007) | NFR 002 tidak dapat dinyatakan lulus | Ukur ulang di staging setelah DEC-007 |
| KI-03 | Browser resmi belum ditetapkan (DEC-010) | NFR 010 tidak dapat dinyatakan lulus | Chromium, Firefox, WebKit lulus; tetapkan daftar resmi sebelum UAT |
| KI-04 | Server in-process pest-plugin-browser tidak me-nest field multipart `files[]`, sehingga unggahan Livewire tidak dapat diuji lewat browser | Unggah bukti lewat UI tidak tercakup E2E | Dicakup `ActualPagesTest` (Livewire, termasuk file ditolak berdasarkan isi); journey melampirkan lewat service yang sama lalu memeriksa UI. Uji manual di staging |
| KI-05 | Perhitungan ulang paralel dua proses tidak diuji | Risiko rendah: `ScoringService` memakai row lock (`lockForUpdate`) dan perhitungan idempoten | Tambah uji paralel bila pilot memakai worker ganda |

## 6. Skipped, deferred, dan flaky

| Test | Alasan | Pemilik |
|---|---|---|
| `Feature/Auth/RegistrationTest` (2 test) | Fitur registrasi mandiri Fortify sengaja nonaktif: akun dibuat lewat administrasi (Step 05). Test dilewati otomatis selama fitur itu mati | Engineering Lead (tidak perlu tindakan kecuali registrasi mandiri diaktifkan) |
| `Feature/ActualEvidence/ActualLifecycleTest` › zero, N/A, dan missing berbeda (constraint) | Constraint `CHECK` nilai/jenis hanya ada di PostgreSQL; di SQLite di-skip, di PostgreSQL dan CI dijalankan dan lulus | Engineering Lead |

Flaky yang ditemukan dan diperbaiki saat menulis E2E (bukan dengan menaikkan timeout):

- Penantian setelah aksi memakai teks yang sudah ada di halaman (opsi filter "Terverifikasi", menu "Periode & Target"), sehingga test berjalan mendahului request Livewire. Sekarang setiap penantian memakai pesan yang hanya muncul setelah aksi berhasil.
- `press('Hitung scorecard')` mengenai judul panel dengan teks yang sama. Sekarang memakai tombol di form `wire:submit="calculate"`.
- Firefox dapat mengembalikan halaman sebelum navigasi selesai (scan axe pada `about:blank`, "execution context destroyed"). Sekarang `BrowserJourney::open()` menunggu konten halaman dengan locator yang di-retry.

Setelah perbaikan: Chromium 3/3 run lulus, Firefox dan WebKit lulus.

## 7. Exit gate

| Gate | Status | Bukti |
|---|---|---|
| Tidak ada defect Critical/High terbuka untuk release scope | **Lulus** | §5. D-06 (Medium, dev tooling) ditutup 26 Oktober 2026 |
| Seluruh acceptance criteria rilis inti memiliki evidence | **Sebagian** | AC-01 s.d. AC-11 punya bukti teknis; AC-12 (UAT) belum; penerimaan bisnis AC-03/04/05 BLOCKED |
| Authorization negative tests lulus | **Lulus** | Suite default + 6 direct URL di browser |
| Scoring cocok dengan golden example bisnis | **BLOCKED** | Cocok dengan contoh sintetis; contoh bisnis belum ditandatangani (DEC-004) |
| Performa memenuhi target yang disepakati atau exception disetujui | **BLOCKED** | Terukur (p95 ≤ 547 ms) tanpa beban yang disepakati (DEC-007) |
| Tidak ada flaky test yang disembunyikan/di-skip tanpa issue dan pemilik | **Lulus** | §6 |

## 8. Keputusan dan langkah berikutnya

- Core readiness **teknis**: tidak ada penghalang dari sisi kode untuk menyiapkan staging. D-06 (job CI `frontend` merah karena `vite-plus` 0.3.0) ditutup dengan migrasi `vite-plus` 1.4.0.
- Pilot (Step 20) tetap memerlukan: DEC-002, DEC-004, DEC-006, DEC-007, DEC-013, DEC-014, daftar browser (DEC-010), golden example bertanda tangan, dan review aksesibilitas manual.
- Step 19 diulang sebagai full regression setelah fitur khusus/integrasi diaktifkan, dengan beban DEC-007 di staging.
