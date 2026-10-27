# TDD Evidence — Development Demo Seeder

## User journey

Sebagai pemilik yang meninjau aplikasi lokal, saya ingin data sintetis end-to-end tersedia setelah seed agar halaman KPI, template, periode, target, dan aktual tidak kosong tanpa menganggap data tersebut sebagai keputusan bisnis resmi.

## Evidence

| Guarantee | Test / command | Result |
|---|---|---|
| Seeder membuat 6 KPI published, 3 template, 1 periode, 12 target, dan 12 aktual sintetis | `tests/Feature/Database/DevelopmentDemoSeederTest.php` | PASS |
| Menjalankan seeder dua kali tidak menambah duplikat | `tests/Feature/Database/DevelopmentDemoSeederTest.php` | PASS |
| Seeder menolak environment testing, staging, dan production | `tests/Feature/Database/DevelopmentDemoSeederTest.php` | PASS |
| Seluruh regression suite tetap lulus | `php artisan test` | 1.077 PASS, 3 SKIP, 0 FAIL; 15.173 assertions |
| Format seluruh source tetap sesuai | `vendor/bin/pint --test` | 591 files PASS |
| Seeder baru lolos analisis statis | `vendor/bin/phpstan analyse database/seeders/DevelopmentDemoSeeder.php --no-progress` | PASS |

## RED / GREEN

- **RED:** test baru gagal karena `Database\\Seeders\\DevelopmentDemoSeeder` belum tersedia.
- **GREEN:** implementasi seeder lokal ditambahkan, didaftarkan pada `DatabaseSeeder`, lalu test target dan regression suite lulus.

## Batasan

- Semua nilai memakai prefix/label `DEMO` dan hanya untuk `APP_ENV=local`.
- Dataset tidak membuktikan staging, UAT, rekonsiliasi bisnis, sign-off, atau production readiness.
- Scoring resmi tetap tidak tersedia karena belum ada policy DEC-004 yang disetujui.
