# KPI PT Berlian Artha Label

Repository untuk pengembangan Sistem Manajemen KPI PT Berlian Artha Label.

## Status saat ini

- Step 02 (bootstrap aplikasi): skeleton Laravel 13 + Livewire starter kit, application shell, UI primitives, halaman error, `/health`, dan smoke test.
- Step 06 (kamus KPI dan template berversi): lifecycle DRAFT → REVIEW → PUBLISHED → RETIRED, versi published immutable, strategi skor allowlist, bobot template tepat 100%, dan kandidat PRD sebagai draft. Detail: `docs/architecture/KPI_GOVERNANCE.md`.
- **Belum ada KPI resmi.** Bobot, formula, target, approver, dan rating menunggu keputusan bisnis (DEC-003 s.d. DEC-006). Menu Approval dan Reports masih placeholder.
- Step 03 (infrastruktur lokal dan CI): Dockerfile multi-stage, Docker Compose (nginx, php-fpm, worker, scheduler, PostgreSQL 18, Redis, S3-compatible SeaweedFS, Mailpit), script developer, dan GitHub Actions. Detail: `docs/operations/`.
- Step 04 (fondasi data): audit append-only, transactional outbox, idempotency, optimistic locking, metadata object storage.
- Step 05 (identity, organisasi, otorisasi): login lokal di balik adapter, struktur 3 group/department dan 21 team bertanggal efektif, penugasan pegawai, role terpisah dari scope, delegasi bertanggal, policy server-side, dan halaman Administration. Detail: `docs/architecture/IDENTITY_ACCESS.md`. SSO belum ada (DEC-013).

Artefak perencanaan:

- PRD: `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx` (sumber: `scripts/build_prd.py`)
- Decision register dan matrix: `docs/product/`
- Arsitektur dan ADR: `docs/architecture/` (struktur kode: `docs/architecture/APPLICATION_STRUCTURE.md`)
- Operasional: `docs/operations/` (lingkungan lokal, CI, migration policy, dependency policy)
- Paket prompt implementasi: `prompts/ai-development/README.md`
- Riwayat step: `PROJECT_PROGRESS.md`

## Stack yang disepakati

- Laravel 13 dan PHP 8.4
- Blade, Livewire 4 (starter kit resmi + Flux UI), Alpine.js, Tailwind CSS 4, dan Vite
- PostgreSQL 18 (source of truth)
- Redis untuk cache/queue (worker `queue:work`; Horizon dievaluasi setelah authorization tersedia)
- S3-compatible private object storage untuk evidence/export
- Pest (unit/feature), Playwright (disiapkan pada step testing)
- Docker, Docker Compose, Nginx, PHP-FPM, dan GitHub Actions

## Cara cepat: Docker Compose (direkomendasikan)

Prasyarat: Docker (Docker Desktop di Windows) dan Git (Git Bash di Windows).

```bash
git clone https://github.com/gilbertusk/kpi-berlianartha.git
cd kpi-berlianartha
bash scripts/dev.sh bootstrap     # Windows PowerShell: .\scripts\dev.ps1 bootstrap
```

`bootstrap` membuat `.env` dengan secret lokal acak (hanya bila kosong), membangun image, menjalankan seluruh service sampai healthy, menjalankan migration, lalu smoke test. Aplikasi tersedia di <http://127.0.0.1:8088>, Mailpit di <http://127.0.0.1:8025>. Setelah itu cukup `docker compose up -d --wait`.

Perintah lain: `bash scripts/dev.sh help`. Port, volume, deployment Linux, dan troubleshooting: `docs/operations/LOCAL_ENVIRONMENT.md`.

## Prasyarat development tanpa Docker

| Tool | Versi minimum | Catatan |
|---|---|---|
| PHP | 8.4 | Ekstensi: `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `zip`, `sodium` |
| Composer | 2.x | |
| Node.js | 22 LTS atau lebih baru | npm 10+ |
| PostgreSQL | 18 | Target database |
| Redis | 7+ | Target cache/queue; butuh ekstensi `phpredis` (sudah tersedia di image Docker) |

Pastikan `php --version` menampilkan 8.4 sebelum menjalankan perintah apa pun. Di Windows, letakkan direktori PHP 8.4 di urutan pertama `PATH` bila ada instalasi PHP lain (mis. XAMPP).

## Instalasi tanpa Docker dari checkout bersih

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

Migration saat ini mencakup tabel starter kit, fondasi data Step 04, serta organisasi dan identity/access Step 05. Tabel domain KPI belum dibuat.

**Fallback lokal tanpa Redis/PostgreSQL** (bukan keputusan project, hanya untuk mencoba UI): di `.env` set `CACHE_STORE=database` dan `QUEUE_CONNECTION=database`; bila PostgreSQL belum tersedia, set sementara `DB_CONNECTION=sqlite` dan `DB_DATABASE=<path absolut>/database/database.sqlite` (file `*.sqlite` diabaikan Git).

### Akun lokal

Registrasi publik dinonaktifkan. `php artisan db:seed` (atau `bash scripts/dev.sh seed`) membuat struktur referensi 21 team. Hanya pada `APP_ENV=local`, perintah ini juga membuat 13 akun fiktif `[DEV]` (`dev.*@kpi.example.test`) untuk setiap role, misalnya:

- `dev.sysadmin`
- `dev.hr`
- `dev.management`
- `dev.auditor`
- `dev.head.produksi`
- `dev.tl.woven`
- `dev.staff.woven`

Password diambil dari `DEV_SEED_PASSWORD` di `.env`. Bila kosong, password acak dicetak sekali di output seeder. Akun nyata dibuat oleh System Admin melalui **Administration → Akun**, dan pengguna membuat password sendiri lewat tautan email. Detail: `docs/architecture/IDENTITY_ACCESS.md`.

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

Test memakai SQLite in-memory, cache `array`, dan queue `sync` (lihat `phpunit.xml`) sehingga dapat berjalan tanpa layanan eksternal. Suite yang sama dijalankan terhadap PostgreSQL + Redis dengan `bash scripts/dev.sh test-pgsql` dan di CI. Test menolak database selain SQLite `:memory:` atau `*_test`.

## Endpoint health

`GET /health` mengembalikan `{"status":"ok"}` dengan `Cache-Control: no-store`, tanpa session/cookie dan tanpa informasi environment, versi, konfigurasi, atau dependency.

Pemeriksaan dependency hanya tersedia lewat CLI (tidak publik):

- `php artisan ops:readiness --disk=s3` — database, cache, dan round-trip object storage;
- `php artisan ops:queue-smoke` — job percobaan diproses tepat satu kali oleh worker;
- `php artisan ops:heartbeat worker|scheduler --check` — heartbeat process (dipakai healthcheck container).

## Alur implementasi

Jalankan prompt pada `prompts/ai-development/` satu per satu sesuai nomor. Step berikutnya hanya boleh dimulai setelah exit criteria step aktif diperiksa dan statusnya dicatat dalam `PROJECT_PROGRESS.md`.

Setiap step yang menghasilkan perubahan harus mempunyai commit terpisah dan didorong ke remote GitHub. Detail aturan terdapat pada `AGENTS.md`.

## Batasan penting

PRD masih memuat keputusan bisnis terbuka. Bobot, formula, target, rating, approver, SLA, integrasi, dan aturan kompensasi tidak boleh diasumsikan oleh developer atau AI.
