# KPI PT Berlian Artha Label

Repository untuk pengembangan Sistem Manajemen KPI PT Berlian Artha Label.

## Status saat ini

- Step 02 (bootstrap aplikasi): skeleton Laravel 13 + Livewire starter kit, application shell, UI primitives, halaman error, `/health`, dan smoke test.
- **Business module belum diimplementasikan.** Tidak ada tabel, template, bobot, formula, target, approver, atau data KPI. Menu KPI, Approval, Reports, dan Administration hanya placeholder tanpa data.
- Docker Compose, CI GitHub Actions, dan verifikasi runtime PostgreSQL/Redis/object storage adalah scope **Step 03** dan belum dibuktikan.

Artefak perencanaan:

- PRD: `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx` (sumber: `scripts/build_prd.py`)
- Decision register dan matrix: `docs/product/`
- Arsitektur dan ADR: `docs/architecture/` (struktur kode: `docs/architecture/APPLICATION_STRUCTURE.md`)
- Paket prompt implementasi: `prompts/ai-development/README.md`
- Riwayat step: `PROJECT_PROGRESS.md`

## Stack yang disepakati

- Laravel 13 dan PHP 8.4
- Blade, Livewire 4 (starter kit resmi + Flux UI), Alpine.js, Tailwind CSS 4, dan Vite
- PostgreSQL 18 (source of truth)
- Redis untuk cache/queue (Horizon menyusul pada step terkait)
- S3-compatible private object storage untuk evidence/export
- Pest (unit/feature), Playwright (disiapkan pada step testing)
- Docker, Docker Compose, Nginx, PHP-FPM, dan GitHub Actions (Step 03)

## Prasyarat development

| Tool | Versi minimum | Catatan |
|---|---|---|
| PHP | 8.4 | Ekstensi: `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `zip`, `sodium` |
| Composer | 2.x | |
| Node.js | 22 LTS atau lebih baru | npm 10+ |
| PostgreSQL | 18 | Target database |
| Redis | 7+ | Target cache/queue; butuh ekstensi `phpredis` (disediakan image Docker Step 03) |

Pastikan `php --version` menampilkan 8.4 sebelum menjalankan perintah apa pun. Di Windows, letakkan direktori PHP 8.4 di urutan pertama `PATH` bila ada instalasi PHP lain (mis. XAMPP).

## Instalasi dari checkout bersih

```bash
git clone https://github.com/gilbertusk/kpi-berlianartha.git
cd kpi-berlianartha

composer install
npm install

cp .env.example .env          # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate      # APP_KEY hanya di .env lokal; jangan di-commit
```

### Environment

`.env.example` berisi target arsitektur tanpa secret:

- `APP_TIMEZONE=Asia/Jakarta` adalah zona waktu presentasi/bisnis (`config('app.business_timezone')`). Runtime dan penyimpanan timestamp tetap UTC (ADR-0007; konvensi persistence pada Step 04).
- `DB_CONNECTION=pgsql`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, variabel `AWS_*` untuk S3-compatible storage, dan `MAIL_*` untuk mail catcher lokal.
- Isi `DB_PASSWORD` dan credential lain hanya di `.env` lokal atau secret manager.

### Database (target PostgreSQL)

```bash
# Contoh pembuatan role/database lokal (gunakan password Anda sendiri):
psql -U postgres -c "CREATE ROLE kpi_app LOGIN PASSWORD '<password-lokal>';"
psql -U postgres -c "CREATE DATABASE kpi_berlianartha OWNER kpi_app;"

php artisan migrate
```

Step 02 hanya membawa migration bawaan starter kit (users, sessions, cache, jobs, passkeys, two-factor). Tabel domain KPI belum dibuat.

**Fallback lokal tanpa Redis/PostgreSQL** (bukan keputusan project, hanya untuk mencoba UI): di `.env` set `CACHE_STORE=database` dan `QUEUE_CONNECTION=database`; bila PostgreSQL belum tersedia, set sementara `DB_CONNECTION=sqlite` dan `DB_DATABASE=<path absolut>/database/database.sqlite` (file `*.sqlite` diabaikan Git).

### Akun lokal

Registrasi publik dinonaktifkan. Seeder tidak membuat akun atau password. Buat akun development secara manual:

```bash
php artisan tinker
>>> App\Models\User::factory()->create(['name' => 'Developer', 'email' => 'dev@example.test', 'password' => '<password-lokal>']);
```

Provisioning akun, role, dan organization scope resmi adalah scope Step 05.

## Perintah harian

| Tujuan | Perintah |
|---|---|
| Development server (PHP server + queue listener + Vite) | `composer dev` |
| Hanya PHP server | `php artisan serve` |
| Vite dev server | `npm run dev` |
| Production asset build | `npm run build` |
| Test (Pest) | `php artisan test` |
| Format check / perbaikan | `composer lint:check` / `composer lint` |
| Static analysis (Larastan level 7) | `composer types:check` |
| Gate lengkap (config clear + format + static analysis + test) | `composer test` |
| Audit dependency | `composer audit` dan `npm audit` |
| Daftar route | `php artisan route:list` |

Test memakai SQLite in-memory, cache `array`, dan queue `sync` (lihat `phpunit.xml`) sehingga dapat berjalan tanpa layanan eksternal. Integrasi dengan PostgreSQL/Redis diuji pada CI Step 03.

## Endpoint health

`GET /health` mengembalikan `{"status":"ok"}` dengan `Cache-Control: no-store`, tanpa session/cookie dan tanpa informasi environment, versi, konfigurasi, atau dependency. Pemeriksaan database/Redis/storage yang mendalam adalah scope Step 03/18 dan tidak boleh dibuat publik.

## Alur implementasi

Jalankan prompt pada `prompts/ai-development/` satu per satu sesuai nomor. Step berikutnya hanya boleh dimulai setelah exit criteria step aktif diperiksa dan statusnya dicatat dalam `PROJECT_PROGRESS.md`.

Setiap step yang menghasilkan perubahan harus mempunyai commit terpisah dan didorong ke remote GitHub. Detail aturan terdapat pada `AGENTS.md`.

## Batasan penting

PRD masih memuat keputusan bisnis terbuka. Bobot, formula, target, rating, approver, SLA, integrasi, dan aturan kompensasi tidak boleh diasumsikan oleh developer atau AI.
