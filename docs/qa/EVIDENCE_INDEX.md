# Evidence Index — Step 19

Setiap bukti dapat diulang dari repository. File hasil run (JUnit, JSON performa, screenshot kegagalan) tidak di-commit. Lokasinya disebut di bawah dan diregenerasi oleh perintah yang sama. Format mengikuti `docs/implementation/TEST_STRATEGY.md` §8: perintah, versi, environment, waktu, dataset, hasil, lokasi.

| ID | Bukti | Perintah | Environment | Waktu (WIB) | Dataset | Hasil | Lokasi hasil |
|---|---|---|---|---|---|---|---|
| EV-01 | Suite default sebelum perubahan (baseline) | `php artisan test` | PHP 8.4.25, SQLite in-memory, basis `99f31dd` | 26-10-2026 | Fixture sintetis | 1.063 test: 1.060 lulus, 3 skip, 0 gagal (15.114 assertion) | Output konsol |
| EV-02 | Suite default setelah Step 19 | `php artisan test --log-junit storage/framework/testing/junit-sqlite.xml` | PHP 8.4.25, SQLite | 26-10-2026 | Fixture sintetis | Lihat `RELEASE_TEST_REPORT.md` §1 | `storage/framework/testing/junit-sqlite.xml` |
| EV-03 | Suite default di PostgreSQL | `bash scripts/dev.sh test-pgsql --log-junit storage/framework/testing/junit-pgsql.xml` | Compose: PHP 8.5.11, PostgreSQL 18, Redis | 26-10-2026 | Fixture sintetis | Lihat `RELEASE_TEST_REPORT.md` §1 | `storage/framework/testing/junit-pgsql.xml` |
| EV-04 | E2E Chromium, 3 run berturut-turut | `npm run build && php artisan test --testsuite=Browser` | Playwright 1.64.0, Chromium, Windows 11 | 26-10-2026 | `BrowserJourney`, `WorkflowScenario` | 18/18 lulus tiap run | Screenshot hanya saat gagal: `tests/Browser/Screenshots/` |
| EV-05 | E2E Firefox | `php artisan test --testsuite=Browser --browser firefox` | Firefox 157.0 (Playwright) | 26-10-2026 | Sama | 18/18 lulus | — |
| EV-06 | E2E WebKit | `php artisan test --testsuite=Browser --browser safari` | WebKit 27.2 (Playwright) | 26-10-2026 | Sama | 18/18 lulus | — |
| EV-07 | Scan aksesibilitas sebelum perbaikan | Suite Browser (Vite aktif) | Chromium | 26-10-2026 | `WorkflowScenario` | 3 temuan serious: D-01, D-02, D-03 | `RELEASE_TEST_REPORT.md` §5 |
| EV-08 | Probe performa, PostgreSQL | `bash scripts/dev.sh test-pgsql --testsuite=Performance` | Compose PostgreSQL 18 + Redis, i5-10300H | 26-10-2026 10:31 | 316 pegawai, 632 target/aktual, 316 scorecard | 16 halaman, p95 tertinggi 547 ms | `storage/framework/testing/performance.json`; `PERFORMANCE_REPORT.md` §3 |
| EV-09 | Probe performa sebelum/sesudah D-04 | `php artisan test --testsuite=Performance` | PHP 8.4.25, SQLite | 26-10-2026 10:10 dan 10:14 | Sama | Query dashboard/approval 380–396 → 22–38 | `PERFORMANCE_REPORT.md` §4 |
| EV-10 | Test regresi N+1 (gagal sebelum, lulus sesudah) | `php artisan test tests/Feature/Workflow/WorkflowQueryEfficiencyTest.php` | SQLite | 26-10-2026 | `PerformanceDataset(2)` | 24 query bypass → 1 | Output konsol |
| EV-11 | Audit dependency | `composer audit`, `npm audit` | — | 26-10-2026 | — | Composer bersih setelah Livewire 4.4.8; npm: N-01 terbuka | `docs/security/VULNERABILITY_EXCEPTIONS.md` §2a |
| EV-12 | Format dan static analysis | `vendor/bin/pint --test`; `vendor/bin/phpstan analyse --memory-limit=1G` | PHP 8.4.25 | 26-10-2026 | — | Lulus; 0 error | Output konsol |
| EV-13 | Environment Compose dari image development baru (ext-sockets) | `bash scripts/dev.sh bootstrap` | Docker 29.7.2 | 26-10-2026 | — | Health, readiness, storage privat (403), queue smoke tepat 1×, heartbeat | Output konsol |
| EV-14 | CI GitHub Actions untuk commit Step 19 | push ke `main` | GitHub Actions | Setelah push | — | Dicatat lewat addendum `PROJECT_PROGRESS.md` | Run GitHub Actions |

## Data uji

Semua data sintetis dan anonim: akun `@fixtures.example.test`, nomor pegawai `T-`, `W-`, `AR-`, `PF-`, file bukti `tests/Fixtures/browser/laporan-mutu.pdf` (PDF minimal buatan sendiri). Tidak ada data pegawai, customer, atau finansial nyata.

## Policy skor

Journey browser, test workflow, dan probe performa memakai policy skor **TEST-ONLY** (`tests/Support/ScoringFixtures.php`). Policy itu hanya ada di memori proses test dan tidak pernah masuk `config/scoring.php`. Skor resmi tetap diblokir sampai DEC-004 disetujui.
