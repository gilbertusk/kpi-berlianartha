# Laporan Performa — Step 19 run 1

**Status:** terukur pada dataset sintetis; **target belum dapat dinyatakan lulus.** PRD NFR 002 meminta halaman standar tampil ≤ 3 detik "pada beban yang disepakati". Jumlah pengguna, pengguna bersamaan, dan volume data belum diputuskan (DEC-007), jadi yang diukur di sini adalah batas bawah: satu pengguna, data sintetis, mesin pengembang.

## 1. Cara mengukur

- Test: `tests/Performance/PageResponseTest.php` (suite `Performance`, dijalankan eksplisit).
- Dataset: `tests/Support/PerformanceDataset.php`, dibangun lewat service aplikasi: 21 team PRD × 15 pegawai + 1 akun pegawai, satu template pegawai per team (dua KPI, 60/40), seluruh target disetujui, seluruh aktual diisi, diajukan, dan diverifikasi, scorecard dihitung dengan policy TEST-ONLY, periode di tahap Review. Semua nama dan nilai sintetis.
- Ukuran: **316 pegawai, 632 target, 632 aktual, 316 scorecard**, 21 team.
- Per halaman: 1 request pemanasan, lalu 20 sampel berurutan. Diukur request penuh di dalam proses (routing, middleware, otorisasi, query, render Blade/Livewire) dan jumlah query maksimum per request. Tidak termasuk jaringan, render browser, dan konkurensi.
- Gerbang test: gagal bila p95 halaman mana pun > 3.000 ms.
- Ulangi: `php artisan test --testsuite=Performance` (lokal) atau `bash scripts/dev.sh test-pgsql --testsuite=Performance` (PostgreSQL + Redis Compose). Ukuran dapat diubah dengan `PERF_EMPLOYEES_PER_TEAM` dan `PERF_SAMPLES`. Hasil mentah: `storage/framework/testing/performance.json` (tidak di-commit).

## 2. Environment

| Item | Nilai |
|---|---|
| Mesin | Laptop pengembang, Intel Core i5-10300H (4 core / 8 thread), RAM 23,8 GB, Windows 11 |
| Run utama | Docker Compose lokal: PHP 8.5.11 (image development, OPcache development), PostgreSQL 18, Redis (cache) |
| Run pembanding | PHP 8.4.25 lokal, SQLite in-memory |
| Commit | Kode Step 19 sebelum commit (basis `99f31dd`) |
| Waktu | 26 Oktober 2026 |

## 3. Hasil — PostgreSQL 18 + Redis (run utama)

Seed dataset 36,8 detik; memori puncak proses test 88,5 MB.

| Halaman (peran) | Query | p50 (ms) | p95 (ms) | Maks (ms) |
|---|---:|---:|---:|---:|
| HR · dashboard | 38 | 315,9 | 399,9 | 476,3 |
| HR · detail periode | 17 | 114,9 | 143,9 | 181,0 |
| HR · target periode | 14 | 96,7 | 143,3 | 148,0 |
| HR · aktual (semua unit) | 18 | 68,5 | 71,2 | 90,2 |
| HR · scorecard periode | 15 | 61,3 | 80,8 | 85,8 |
| HR · alur approval periode | 27 | 429,3 | 529,9 | 554,3 |
| HR · laporan | 4 | 35,3 | 39,0 | 40,7 |
| Management · dashboard | 34 | 272,6 | 333,6 | 379,3 |
| Management · inbox approval | 23 | 259,3 | 293,5 | 380,5 |
| Department Head · inbox approval | 22 | 331,1 | 417,6 | 423,6 |
| Team Leader · dashboard | 35 | 316,6 | 547,3 | 558,6 |
| Team Leader · aktual tim | 20 | 69,8 | 87,7 | 89,5 |
| Team Leader · halaman tim | 13 | 61,0 | 78,7 | 88,3 |
| Employee · dashboard | 17 | 60,0 | 91,5 | 104,4 |
| Employee · aktual sendiri | 22 | 55,5 | 105,7 | 123,6 |
| Auditor · kamus KPI | 6 | 50,8 | 61,5 | 71,6 |

p95 tertinggi 547 ms (dashboard Team Leader), di bawah 3.000 ms pada dataset dan environment ini.

## 4. Temuan dan perbaikan (D-04)

Run pertama (SQLite) memperlihatkan jumlah query dashboard dan halaman approval naik linear dengan jumlah scorecard: 84 query pada 43 scorecard, 396 query pada 316 scorecard. Penyebabnya, bypass tahap review dicari satu query per scorecard tanpa reviewer (`WorkflowService::bypassFor`). Sekarang daftar membaca bypass yang berlaku sekali (`reviewBypassesInForce`), dan pemeriksaan per scorecard saat kalibrasi tetap memakai query langsung. Test regresi: `tests/Feature/Workflow/WorkflowQueryEfficiencyTest.php` (24 query bypass sebelum perbaikan, 1 sesudahnya).

SQLite, dataset sama, sebelum → sesudah:

| Halaman | Query | p95 (ms) |
|---|---|---|
| HR · dashboard | 396 → 38 | 579 → 339 |
| HR · alur approval periode | 385 → 27 | 743 → 569 |
| Management · dashboard | 392 → 34 | 467 → 362 |
| Management · inbox approval | 381 → 23 | 444 → 301 |
| Department Head · inbox approval | 380 → 22 | 522 → 393 |
| Team Leader · dashboard | 393 → 35 | 574 → 366 |

Halaman lain tidak berubah. Query bypass sekarang satu per daftar, berapa pun jumlah scorecardnya (dibuktikan test regresi pada 43 scorecard dan probe pada 316). Jumlah query total halaman lain baru diukur pada dua ukuran (43 dan 316 scorecard). Hanya dashboard dan halaman approval yang naik antara kedua ukuran itu, dan penyebabnya sudah diperbaiki. Waktu render dashboard dan approval tetap tumbuh linear karena seluruh scorecard dalam scope diproses di PHP. Ini wajar pada 316 scorecard, tetapi perlu diukur ulang pada volume DEC-007.

## 5. Ekspor dan queue

Ukuran dari suite standar (SQLite, mesin yang sama, tanpa konkurensi):

| Ukuran | Hasil |
|---|---|
| Ekspor Excel 50.000 baris (`ReportExportTest`) | 11,1 detik, file 2,9 MB, ditulis streaming (memori tidak menyimpan baris) |
| Import produksi 20.000 baris (`ProductionImportTest`) | 8,4 detik, memori puncak proses test 440 MB |
| Queue smoke (`ops:queue-smoke`, Compose) | Job diproses tepat 1 kali (bootstrap Step 19) |

Umur antrean di bawah beban dan throughput worker belum diukur karena belum ada volume yang disepakati.

## 6. Yang belum diukur

- Pengguna bersamaan, think time, dan campuran transaksi (DEC-007).
- Jaringan dan render browser end-to-end di staging (Step 20), termasuk image produksi dengan OPcache produksi.
- Resource usage (CPU/memori container) di bawah beban.
- Uji ulang setelah volume pilot tersedia, sesuai PRD NFR 002.

## 7. Keputusan

Performa **tidak memblokir** core readiness pada dataset sintetis. NFR 002 tetap **BLOCKED** sampai DEC-007 menetapkan beban dan pengukuran diulang di staging dengan beban itu, atau sampai ada exception yang disetujui pemilik keputusan.
