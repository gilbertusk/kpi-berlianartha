# Project Progress — Sistem KPI PT Berlian Artha Label

Dokumen ini bersifat append-only. Jangan menghapus atau menulis ulang riwayat step sebelumnya.

## 2026-10-12 — Step 00 Validasi Keputusan Bisnis

**Status:** PARTIAL

### Scope selesai

- Membuat decision register dengan status, owner, dampak, target keputusan, dan gate implementasi.
- Mencatat konfirmasi full-stack Laravel dan GitHub push policy.
- Menyediakan template definisi KPI lengkap untuk workshop dan sign-off.
- Menyediakan role/action matrix dan approval matrix untuk 21 team.
- Menyediakan UAT/domain sign-off matrix dan gate defect.

### File yang dibuat/diubah

- `docs/product/DECISION_REGISTER.md`
- `docs/product/KPI_DEFINITION_TEMPLATE.md`
- `docs/product/ROLE_APPROVAL_MATRIX.md`
- `docs/product/UAT_SIGNOFF_MATRIX.md`
- `PROJECT_PROGRESS.md`
- `AGENTS.md`, `README.md`, `.gitignore`, dan prompt README sebagai baseline workflow Git.

### Validasi

- Review silang terhadap PRD bagian struktur organisasi, role, workflow, model KPI, fitur khusus, NFR, acceptance criteria, implementasi bertahap, dan keputusan terbuka: selesai secara manual.
- Struktur 3 group/department dan 21 team: tercantum pada approval matrix.
- Keputusan bisnis yang belum memiliki bukti tidak ditandai `APPROVED`.
- Git commit/push: **belum tervalidasi** karena executor otomatis mengalami `helper_unknown_error: setup refresh had errors`; autentikasi GitHub berhasil melalui terminal aplikasi.

### Keputusan dan asumsi

- TECH-001 full-stack Laravel berstatus APPROVED berdasarkan konfirmasi Project Owner.
- Seluruh bobot PRD tetap draft; total template wajib 100% sebelum publish.
- Kandidat KPI pada lampiran PRD tidak otomatis aktif.
- Payroll, bonus, atau kompensasi otomatis tetap di luar scope sampai kebijakan terpisah disetujui.

### Blocker

- DEC-001 sampai DEC-016 belum seluruhnya memperoleh sign-off.
- Nama approver dan substitute untuk 21 team belum tersedia.
- Formula/rating/golden examples belum disetujui.
- Team pilot, volume pengguna/data, provider hosting, SSO, integrasi, SLA, retention, RPO, dan RTO belum diputuskan.
- Push remote Step 00 belum terbukti.

### Gate berikutnya

- Step 01 arsitektur dapat dimulai menggunakan keputusan terbuka sebagai constraint eksplisit.
- Laravel skeleton dan infrastructure lokal boleh disiapkan setelah arsitektur ditinjau.
- Published KPI, scoring resmi, approval routing produksi, integration production, dan UAT bisnis tetap diblokir sampai keputusan terkait disetujui.

## 2026-10-12 — Step 01 Arsitektur dan Rencana Implementasi

**Status:** PARTIAL — artefak desain selesai; validasi command dan Git push belum terbukti.

### Scope selesai

- Membuat system context, container view, module map, trust boundary, deployment topology, data flow, NFR mapping, dan architecture risk register.
- Menetapkan modular monolith serta memisahkan satu deployable application dari process web, worker, dan scheduler.
- Menetapkan domain boundaries, dependency direction, transaction boundaries, domain events, query boundary, dan extension rules.
- Membuat sembilan ADR untuk architecture style, Laravel/Livewire, data platform, object storage, audit/outbox, effective dating/versioning, timezone, identifier/decimal, dan authentication/authorization.
- Membuat implementation backlog yang mengikuti tahapan PRD: governance → fondasi inti → pilot → rollout inti → fitur khusus/integrasi → optimasi.
- Membuat test strategy, release strategy, threat model, serta data classification baseline.
- Menyelaraskan prompt terhadap PRD untuk total bobot 100%, Manager bypass, Team Leader/Daily Review, AR aging bucket, PPIC status, NCR/CAPA lifecycle, target performa 3 detik, dan urutan pilot.

### File yang dibuat/diubah

- `docs/architecture/SOLUTION_ARCHITECTURE.md`
- `docs/architecture/DOMAIN_BOUNDARIES.md`
- `docs/architecture/adr/0001-modular-monolith.md`
- `docs/architecture/adr/0002-fullstack-laravel-livewire.md`
- `docs/architecture/adr/0003-data-platform.md`
- `docs/architecture/adr/0004-private-object-storage.md`
- `docs/architecture/adr/0005-audit-dan-transactional-outbox.md`
- `docs/architecture/adr/0006-effective-dating-dan-versioning.md`
- `docs/architecture/adr/0007-timezone.md`
- `docs/architecture/adr/0008-identifier-dan-numeric-precision.md`
- `docs/architecture/adr/0009-authentication-dan-authorization.md`
- `docs/implementation/BACKLOG.md`
- `docs/implementation/TEST_STRATEGY.md`
- `docs/implementation/RELEASE_STRATEGY.md`
- `docs/security/THREAT_MODEL.md`
- `docs/security/DATA_CLASSIFICATION.md`
- `prompts/ai-development/README.md`
- Prompt Step 06, 10, 11, 14, 15, 16, 19, dan 20.

### Validasi

- Mapping manual PRD section 3–19 terhadap module, backlog, security, testing, dan release gate: selesai.
- Provider, identity, capacity, retention, RPO/RTO, integration, formula, dan approver yang masih terbuka tidak dikunci sebagai fakta.
- Build/test/link check melalui command: **belum dapat dijalankan** karena executor tetap gagal dengan `helper_unknown_error: setup refresh had errors`.
- Git commit/push dan kesamaan SHA lokal/remote: **belum tervalidasi**.

### Keputusan arsitektur

- Modular monolith dipilih sebagai baseline; microservices dan Kubernetes tidak digunakan pada tahap awal.
- Full-stack Laravel/Livewire adalah application stack.
- PostgreSQL menjadi source of truth; Redis bukan satu-satunya penyimpan business state.
- Evidence/export menggunakan private object storage dan signed URL.
- Published/final data menggunakan effective dating, versioning, correction, dan audit.
- Release khusus unit/integrasi dijalankan setelah pilot core, sesuai PRD.

### Blocker

- Review/approval arsitektur oleh IT/Project Owner belum tercatat.
- TECH-003, DEC-002 sampai DEC-016 yang relevan masih terbuka/proposed.
- Executor command dan Git gate belum pulih.

### Gate berikutnya

- Sebelum Step 02, Step 00 dan Step 01 harus dibuat sebagai commit terpisah dan di-push ke `origin/main` dengan SHA terverifikasi.
- Setelah Git gate lulus, Laravel skeleton dapat dibuat tanpa mengaktifkan business rule yang masih `OPEN`.


## 2026-10-12 — Step 02 Bootstrap Aplikasi Laravel

**Status:** PASS — install dari root/checkout bersih, test, static analysis, format check, audit, dan production asset build lulus pada PHP 8.4. Runtime eksternal (PostgreSQL, Redis, S3-compatible storage, mail, Docker) **belum dibuktikan** dan dilaporkan terpisah di bawah.

### Git preflight

- Repository lokal belum memiliki commit dan remote `origin`. Remote `origin` ditambahkan ke `https://github.com/gilbertusk/kpi-berlianartha.git` (repository remote kosong).
- Step 00 dan Step 01 di-commit terpisah, di-push, dan diverifikasi SHA lokal = `origin/main`:
  - `d0c06f7` `step-00: add product governance decision artifacts` — `PROJECT_PROGRESS.md` pada commit ini hanya berisi bagian Step 00.
  - `1c2e4a1` `step-01: define architecture and implementation plan` — termasuk prompt yang direvisi pada Step 01 (README, 06, 10, 11, 14, 15, 16, 19, 20).
- `git pull --ff-only origin main`: already up to date. SHA awal Step 02: `1c2e4a11e72c6ce7e9662de6f794337c471b8e57`.

### Environment

- PHP pada PATH adalah XAMPP 8.2.12 (tidak kompatibel dengan Laravel 13). PHP 8.4.25 (ZTS x64) dipasang melalui `winget install --id PHP.PHP.8.4` dan dipakai secara eksplisit; ekstensi diaktifkan pada `php.ini` instalasi tersebut. Paket `PHP.PHP.NTS.8.4` gagal diunduh (HTTP 404 dari manifest winget).
- Composer 2.10.3, Node.js 24.15.0, npm 10.8.0, Git 2.43.0.

### Scope selesai

- Skeleton dibuat dengan Laravel Installer 5.32.0 (`laravel new --livewire --pest`) di direktori sementara, ditinjau, lalu di-merge ke root tanpa menimpa `AGENTS.md`, `PROJECT_PROGRESS.md`, `docs/`, `prompts/`, `output/`, `scripts/`, dan `.git/`. `.gitignore` di-merge manual: aturan existing dipertahankan, dan aturan starter kit yang mengabaikan `AGENTS.md`/`CLAUDE.md` tidak diambil. `.github/` starter kit tidak diambil karena CI adalah scope Step 03.
- Laravel 13.35.0, Livewire 4.4.7 (Alpine.js dibundel Livewire), Flux UI 2.20.1, Fortify 1.41.0, Tailwind CSS 4.3.3, Vite 8 melalui `vite-plus`, Pest 5.3.1, Larastan 3.13.0 (level 7), Pint 1.32.1.
- Application shell: sidebar dengan navigation placeholder Dashboard, KPI, Approval, Reports, Administration (`config/navigation.php` dengan hook `ability` untuk Step 05), skip link, landmark `<main>`, dan fokus keyboard yang terlihat.
- UI primitives di `resources/views/components/ui/`: button, label, input, field-error, alert, status-badge, page-header, table-shell, empty-state, loading-state, error-state. Design token `brand/info/success/warning/danger` ada di `resources/css/app.css`.
- Halaman: landing, login (starter kit), dashboard placeholder ("Data KPI belum dikonfigurasi"), placeholder modul tanpa data, serta error page mandiri 401/403/404/419/429/500/503 tanpa pesan exception.
- `GET /health` mengembalikan `{"status":"ok"}` dengan `Cache-Control: no-store`, didaftarkan di luar middleware group `web` (tanpa session/cookie/CSRF). Endpoint bawaan `/up` dihapus.
- `.env.example` tanpa secret: `APP_TIMEZONE=Asia/Jakarta`, PostgreSQL, Redis cache/queue, variabel S3-compatible, dan mail lokal.
- README developer dan `docs/architecture/APPLICATION_STRUCTURE.md`.

### File/package penting

- `app/Http/Controllers/HealthController.php`, `bootstrap/app.php`, `routes/web.php`, `config/navigation.php`, `config/app.php` (`business_timezone`), `config/database.php` (default `pgsql`), `config/fortify.php` (registrasi nonaktif), `database/seeders/DatabaseSeeder.php` (kosong).
- `resources/views/components/ui/*`, `resources/views/errors/*`, `resources/views/layouts/app*.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/modules/placeholder.blade.php`, `resources/views/welcome.blade.php`.
- `tests/Feature/Smoke/*` (boot, health, guest, authenticated shell, error pages), `tests/Feature/Ui/UiComponentsTest.php`, `tests/Unit/ArchTest.php`.
- Dependency di luar framework beserta alasannya (semuanya bawaan official starter kit kecuali dicatat):
  - `livewire/flux`: UI kit starter kit.
  - `laravel/fortify`: backend autentikasi starter kit.
  - `livewire/blaze`: optimasi render Blade component.
  - `laravel/chisel`: tool installer untuk menghapus fitur opsional; tidak dipakai saat runtime.
  - `laravel/pao`: output test/analysis ringkas untuk agent.
  - `laravel/sail`: helper Docker untuk development; keputusan Docker ada di Step 03.
  - `larastan/larastan`: static analysis wajib.
  - `pestphp/pest` dan `pestphp/pest-plugin-laravel`: test framework wajib.
  - `pestphp/pest-plugin-drift` dihapus karena konversi gagal di Windows dan tidak dibutuhkan.
- npm: `vite-plus` dipertahankan pada 0.3.0 (versi starter kit). `overrides.tinypool = 2.1.2` menutup advisory critical GHSA-5gmw-xhrv-c9v3 dan GHSA-85c8-ppgw-ccpr pada dependency `oxfmt`. Upgrade ke `vite-plus` 0.3.3 tidak dipakai karena memerlukan `vp migrate`, yang mengubah alias Vite dan `devEngines`.

### Command validasi dan hasil aktual (PHP 8.4.25)

| # | Command | Exit | Hasil |
|---|---|---:|---|
| 1 | `composer validate --strict` | 0 | `./composer.json is valid` |
| 2 | `composer audit` | 0 | No security vulnerability advisories found |
| 3 | `npm audit` | 0 | found 0 vulnerabilities |
| 4 | `vendor/bin/pint --test` | 0 | passed |
| 5 | `vendor/bin/phpstan analyse` (Larastan level 7) | 0 | 0 errors |
| 6 | `php artisan test` | 0 | 80 test: 77 passed, 3 skipped, 231 assertions. Yang di-skip: 2 test registrasi dorman (fitur dimatikan) dan 1 arch test modul (`app/Modules` belum ada) |
| 7 | `npm run build` | 0 | built (vite-plus) |
| 8 | `php artisan route:list` | 0 | 61 route. Route aplikasi: `/`, `/dashboard`, `/kpi`, `/approvals`, `/reports`, `/administration`, `/health`, settings, dan auth Fortify |
| 9 | Pemeriksaan staged diff | — | Tidak ada `.env`, `vendor/`, `node_modules/`, `public/build/`, `*.sqlite`, log, cache/session, atau secret |

Verifikasi tambahan:

- Instalasi dari checkout bersih (`git checkout-index` ke direktori sementara, lalu `composer install`, `npm ci`, salin `.env.example`, `key:generate`, Pint, PHPStan, test, dan build): seluruhnya lulus.
- Smoke runtime `php artisan serve` dengan fallback lokal SQLite/file (bukan target):
  - `/health` 200 tanpa cookie;
  - `/` 200 dan `/login` 200;
  - `/dashboard` 302 ke `/login`;
  - `/register` 404;
  - URL tidak dikenal 404 dengan halaman custom.
- Code review (agent code-reviewer): 0 Critical/High.
  - Medium yang diperbaiki: landmark `<main>`, kontras tombol, dan kontras border input.
  - Low yang diperbaiki: label alert ganda, test escaping/variant/h1, sample Pest, dan `export-ignore` README.
  - Temuan bahasa UI dicatat sebagai debt.

### Keputusan/asumsi

- Registrasi publik dimatikan (ADR-0009; DEC-013 masih OPEN). Kode registrasi upstream dibiarkan dorman di balik feature flag Fortify. Seeder tidak membuat akun/password.
- Runtime dan penyimpanan timestamp tetap UTC. `APP_TIMEZONE` dipetakan ke `config('app.business_timezone')` untuk presentasi/bisnis (ADR-0007). Konvensi persistence ditetapkan pada Step 04.
- Test memakai SQLite in-memory agar dapat berjalan tanpa layanan eksternal. Ini bukan perubahan keputusan database; default koneksi aplikasi adalah `pgsql`.
- Folder `app/Modules/*` belum dibuat. Struktur dan arch test sudah disiapkan untuk modul pertama.
- Tidak ada migration/tabel KPI, organization, approval, score, target, atau actual. Tidak ada bobot, formula, rating, approver, SLA, atau seed bisnis.

### Blocker / belum dibuktikan

- PostgreSQL 18 terpasang sebagai service Windows lokal, tetapi koneksi aplikasi **belum diuji** karena credential tidak tersedia dan tidak diasumsikan. Redis, S3-compatible storage, mail catcher, dan Docker **belum dijalankan** (scope Step 03).
- `php` default pada PATH masih XAMPP 8.2.12. Developer harus menempatkan PHP 8.4 di urutan pertama PATH.
- Technical debt:
  - keputusan bahasa UI (`lang="en"` pada halaman berbahasa Indonesia);
  - konversi test starter kit ke sintaks Pest;
  - driver S3 dan `phpredis` belum dipasang.

### Gate berikutnya

- Step 03 (infrastruktur lokal dan CI) boleh dimulai: Docker Compose, runtime PostgreSQL/Redis/object storage/mail, dan GitHub Actions dengan gate nyata.
- Business modules tetap menunggu Step 04+ dan keputusan pada decision register.

## 2026-10-12 — Step 03 Infrastruktur Lokal dan CI

**Status:** PARTIAL — seluruh validasi lokal (Docker build, Compose dari checkout bersih, test PostgreSQL/Redis, smoke, persistensi, scan) lulus; bukti run GitHub Actions untuk commit ini baru tersedia setelah push dan dicatat pada addendum di bawah.

### Git preflight

- `git pull --ff-only origin main`: already up to date. SHA awal Step 03: `3cb179db0b753f8110e275ef10ff5f0e161f7a34` (= `origin/main`).
- Working tree bersih sebelum mulai.

### Scope selesai

- `Dockerfile` multi-stage dengan target berikut:
  - `development`: PHP-FPM + Composer, untuk Compose dengan bind mount.
  - `production`: image immutable non-root. Image yang sama menjalankan web (`php-fpm`), worker (`queue:work`), dan scheduler (`schedule:work`).
  - `http`: nginx berisi `public/` dari build yang sama.
- Base image: `php:8.4.25-fpm-trixie` dengan ekstensi bcmath, intl, pcntl, pdo_pgsql, pgsql, redis, dan zip, plus `apt-get upgrade` untuk patch keamanan OS. Production memakai Composer `--no-dev` dengan classmap authoritative, Vite build di stage Node 24.15.0, opcache tanpa revalidate, dan `php artisan optimize` saat start (`APP_OPTIMIZE`). Migration tidak berjalan otomatis.
- `compose.yaml` development dengan service:
  - `app` (php-fpm);
  - `worker` dan `scheduler`;
  - `nginx` (`127.0.0.1:8088`);
  - PostgreSQL 18.6 dan Redis 8.2.10 (keduanya tidak dipublish ke host);
  - SeaweedFS 4.48 S3-compatible (`127.0.0.1:8333`, bucket privat dibuat otomatis);
  - Mailpit v1.31.3 (`127.0.0.1:8025`);
  - `assets` (one-shot Vite build).
- Compose memakai named volume (`postgres-data`, `redis-data`, `seaweedfs-data`, `vendor`, `node-modules`, `view-cache`) dan healthcheck yang bermakna: FastCGI ping, `/health` via nginx, `pg_isready`, `redis-cli ping`, S3 `/healthz`, `mailpit readyz`, serta heartbeat worker/scheduler.
- Tooling operasional (CLI, tidak publik):
  - `ops:readiness`: database, cache, dan round-trip object storage, tanpa mencetak credential atau pesan exception;
  - `ops:queue-smoke`: job percobaan harus diproses tepat satu kali;
  - `ops:heartbeat`: heartbeat worker lewat event `Looping`, heartbeat scheduler dijadwalkan tiap menit.
- `scripts/dev.sh` dan `scripts/dev.ps1` menyediakan perintah: bootstrap, up/down/ps/logs, migrate, seed, test, test-pgsql, lint, analyse, build, queue-check, readiness, smoke, dan reset-db.
  - `bootstrap` membuat secret lokal acak hanya bila kosong.
  - `reset-db` hanya untuk `APP_ENV=local` + PostgreSQL Compose, dengan konfirmasi nama database.
- Guard test: `TestCase::createApplication()` menolak database selain SQLite `:memory:` atau `*_test`, sehingga `RefreshDatabase` tidak dapat menghapus database development.
- GitHub Actions `.github/workflows/ci.yml`. Semua action dipin ke SHA dan `permissions: contents: read`. Job:
  - `php-quality`;
  - `tests` (PostgreSQL 18 + Redis, migrate/rollback rehearsal, readiness, queue smoke);
  - `frontend`;
  - `docker-image` (hygiene, runtime smoke, Trivy fixable CRITICAL/HIGH, SBOM CycloneDX);
  - `compose-smoke` (bootstrap dari checkout bersih, test-pgsql, persistensi).
- `.github/dependabot.yml` untuk composer, npm, github-actions, docker, dan docker-compose.
- Dokumentasi:
  - `docs/operations/LOCAL_ENVIRONMENT.md` (Windows dev, Linux deployment, port, volume, troubleshooting, bukti sehat);
  - `MIGRATION_POLICY.md`;
  - `DEPENDENCY_POLICY.md`;
  - `CI_PIPELINE.md`;
  - README dan `APPLICATION_STRUCTURE.md` diperbarui.

### Dependency baru

- `league/flysystem-aws-s3-v3` ^3.0 (beserta `aws/aws-sdk-php`): driver disk S3 untuk object storage privat (ADR-0004) dan bukti readiness storage. Service AWS SDK yang tidak dipakai dibuang lewat `Aws\Script\Composer\Composer::removeUnusedServices` (`extra.aws/aws-sdk-php = ["S3"]`), sehingga vendor AWS turun dari 76 MB ke 5 MB.
- Image pihak ketiga (development/CI): `chrislusf/seaweedfs:4.48`, `axllent/mailpit:v1.31.3`, `aquasec/trivy:0.74.0`, `mlocati/php-extension-installer:2.11.34`. MinIO tidak dipakai karena distribusi image komunitasnya tidak lagi diterbitkan berkala.

### Command validasi dan hasil aktual

| # | Command | Exit | Hasil |
|---|---|---:|---|
| 1 | `docker build --target production` | 0 | Image ±886 MB (Docker Desktop, tidak dikompresi; base `php:8.4.25-fpm-trixie` ±710 MB), ±198 MB terkompresi (gzip) |
| 2 | `docker build --target http` | 0 | ±107 MB, ±28 MB terkompresi |
| 3 | Hygiene image production | 0 | uid 33 (www-data); tanpa `.env`, `tests`, `node_modules`, Pest/Larastan/Pint, `docs`, `prompts`, `.git`; `app/` tidak writable |
| 4 | Trivy 0.74.0 (fixable HIGH/CRITICAL) | 0 | app: 0 (sebelum `apt-get upgrade` ada 64 HIGH); http: 0 (sebelum `apk upgrade` ada 3 HIGH) |
| 5 | SBOM CycloneDX | 0 | 285 komponen (image app) |
| 6 | `bash scripts/dev.sh bootstrap` (repo kerja) | 0 | 8 service healthy, migration PostgreSQL OK, smoke OK |
| 7 | `bootstrap` dari checkout bersih (`git checkout-index`, project `kpi-clean`) | 0 | Composer install dan Vite build di container, 8 service healthy, migration dan smoke OK, `/login` 200 dan CSS 200 |
| 8 | `smoke` | 0 | `/health` → `{"status":"ok"}`; readiness database/cache/storage OK; bucket anonim HTTP 403; queue smoke "diproses tepat 1 kali"; heartbeat worker dan scheduler sehat |
| 9 | `test` (container, SQLite) | 0 | 96 passed, 3 skipped, 268 assertions |
| 10 | `test-pgsql` (container, PostgreSQL `kpi_test` + Redis) | 0 | 96 passed, 3 skipped, 268 assertions |
| 11 | `lint` / `analyse` (container) | 0 / 0 | Pint passed; Larastan 0 error |
| 12 | Persistensi | 0 | User, object S3, dan key Redis masih ada setelah `docker compose down` + `up` (tanpa `--volumes`); marker dibersihkan |
| 13 | `reset-db` | 1 / 0 | Konfirmasi salah ditolak (exit 1); `KPI_CONFIRM_RESET=kpi_berlianartha` menjalankan `migrate:fresh` |
| 14 | Runtime image production (web + http + worker di jaringan Compose) | 0 | `/health` 200, `/login` 200 (0,17 dtk), asset 200, `/.env` 403, `*.php` lain 404; readiness OK; queue smoke tepat 1 kali; config/routes/views/events CACHED; debug OFF |
| 15 | Host: `composer validate --strict`, `composer audit`, `npm audit --audit-level=high`, Pint, Larastan, `php artisan test`, `npm run build` | 0 semua | test 96 passed, 3 skipped |
| 16 | `actionlint` 1.7.7 | 0 | workflow valid (1 warning shellcheck diperbaiki) |
| 17 | Inspeksi log container | — | Tidak ada error di app/worker/scheduler/nginx/postgres. Peringatan yang bersifat informatif: Mailpit API tanpa auth (port hanya 127.0.0.1); SeaweedFS election leader saat start |
| 18 | GitHub Actions run untuk commit Step 03 | — | Belum tersedia saat commit dibuat (lihat addendum) |

Temuan yang diperbaiki selama validasi:

- **Port 8080 bentrok di mesin pengembang**, sehingga default diubah ke 8088 dan dapat diatur lewat `APP_PORT`.
- **Bind mount Windows sangat lambat:** `/login` timeout 60 detik dan `php artisan` butuh ±12 detik. `vendor/` dan compiled views dipindah ke named volume; `/login` turun ke ±0,3 detik.
- **Heartbeat tidak pernah dianggap segar di Redis**, karena Redis mengembalikan angka sebagai string. Diperbaiki, dan tertangkap oleh run `test-pgsql`.
- **Test bergantung state Redis bersama.** Test heartbeat sekarang memakai cache bersih dan token UUID acak.
- **Curl `-o /dev/null` gagal di Git Bash** bila `MSYS_NO_PATHCONV=1`. Diganti dengan cara lain.
- **`schedule:list` di CI butuh cache.** Langkah itu sekarang memakai `CACHE_STORE=array`; masalah ini ditemukan saat verifikasi lokal.

Code review (agent code-reviewer) tidak menemukan temuan Critical/High. Yang diperbaiki:

- baris `FROM` literal, agar Dependabot dapat memperbarui base image;
- listener heartbeat tidak lagi dapat menghentikan worker;
- header keamanan nginx pada `/build/`;
- service `assets` berjalan non-root, dengan mount minimal dan `node_modules` di tmpfs;
- healthcheck PostgreSQL memakai `pg_isready -h 127.0.0.1`;
- batas heartbeat worker menjadi 120 detik;
- `cancel-in-progress` tidak berlaku untuk `main`;
- nama artifact SBOM unik per attempt;
- dokumentasi: port 9000, password PostgreSQL setelah `.env` dibuat ulang, dan output readiness.

Setelah perbaikan di atas, seluruh validasi lokal yang terdampak dijalankan ulang dan lulus: build dev/production/http, assets, smoke, test SQLite dan PostgreSQL, Pint, Larastan, actionlint, Trivy, dan runtime image production.

### Keputusan/asumsi

- Worker memakai `queue:work`. Laravel Horizon ditunda karena dashboard-nya membutuhkan authorization (Step 05) dan observability (Step 18).
- SeaweedFS hanya pilihan development/CI; provider object storage production tetap DEC-014.
- Kredensial lokal dibuat acak oleh script dan disimpan hanya di `.env` yang di-ignore. CI memakai service efemeral tanpa secret repository.
- Image menerapkan `apt-get upgrade`/`apk upgrade`; artifact diidentifikasi lewat digest (`DEPENDENCY_POLICY.md`).
- Tidak ada tabel, seed, atau logic bisnis KPI yang ditambahkan.

### Blocker / belum dibuktikan

- Deployment Linux/production, TLS, secret manager, backup/restore, dan HA belum dijalankan; hanya didokumentasikan (Step 18, DEC-014).
- Image production belum dipush ke registry (provider belum diputuskan).

### Gate berikutnya

- Step 04 (fondasi data, audit, dan transaksi) dapat dimulai di atas lingkungan Compose dan CI ini.

### Addendum 2026-10-12 — bukti GitHub Actions Step 03

**Status Step 03 diperbarui:** PASS. Seluruh gate lokal dan CI untuk commit `25eb2c1dd358a67bf320032f86b08f9440aca781` lulus.

GitHub Actions run `38223018889` (`ci`, push ke `main`) berakhir dengan conclusion `success`. Kelima job lulus:

| Job | Hasil | Bukti dari log |
|---|---|---|
| PHP quality | success | `composer audit` tidak menemukan advisory; Pint PASS (62 file); Larastan `[OK] No errors` |
| Tests (PostgreSQL 18 + Redis) | success | migrate → rollback → migrate; 96 passed, 3 skipped (268 assertions); readiness database OK; "Job smoke diproses tepat 1 kali" |
| Frontend | success | `npm audit` 0 vulnerabilities; Vite build berhasil |
| Docker image | success | `kpi-app:ci` 609 MB dan `kpi-http:ci` 71,6 MB (ukuran di runner); hygiene dan runtime smoke lulus; Trivy 0 vulnerability fixable CRITICAL/HIGH (debian 13.7 dan composer-vendor); artifact SBOM `sbom-25eb2c1…-1` terunggah |
| Docker Compose | success | Bootstrap dari checkout bersih; `/health` ok; readiness OK; bucket anonim HTTP 403; queue smoke tepat 1 kali; heartbeat worker dan scheduler sehat; `test-pgsql` 96 passed; uji persistensi lulus |

Gate berikutnya tetap: Step 04 (fondasi data, audit, dan transaksi).

## 2026-10-12 — Step 04 Fondasi Data, Audit, dan Transaksi

**Status:** PASS — acceptance criteria fondasi data, transaksi, audit, idempotency, concurrency, outbox, object metadata, dan error aman lulus pada PostgreSQL/Redis serta stack Docker Compose. Sinkronisasi checkout lokal tidak dapat diverifikasi karena executor Codex tetap gagal dengan `helper_unknown_error: setup refresh had errors`; status remote dan CI diverifikasi langsung melalui GitHub.

### Git preflight

- Branch Step 04 dibuat dari `origin/main` pada commit `89865684ab6fa20cbe9bd587de4ad67c17f57fd2`; working tree remote berada pada PR #12 (`step-04-data-foundation`).
- Selama pengerjaan, `main` maju ke `547d86d0744c626132d838c3097a0e7f11f429db` karena merge Dependabot. Perubahan progress ini dipush setelah base maju; PR wajib lulus CI ulang terhadap merge ref terbaru sebelum squash merge.
- Tidak ada force-push atau penulisan ulang histori. Seluruh perubahan Step 04 tetap terisolasi di PR.
- Pemeriksaan patch PR (50 file) tidak menemukan `.env`, private key, credential, token/password literal, `vendor/`, `node_modules/`, log, atau artifact build.

### Scope selesai

- Menambahkan shared data conventions untuk internal bigint ID, public ULID, timestamp UTC, business timezone Asia/Jakarta, half-open effective period, source reference, lifecycle, numeric/rounding guardrail, dan optimistic `lock_version`.
- Menambahkan `TransactionBoundary`, `StateTransitionGuard`, `OptimisticLock`, dan exception taxonomy aman tanpa menetapkan workflow bisnis.
- Menambahkan audit bisnis yang hanya dapat ditulis di dalam transaksi, menyimpan actor/role/scope snapshot, action, entity, before/after, reason, source, request/correlation ID, dan timestamp.
- Sanitizer mengganti password/token/cookie/private key/recovery code/API key/credential dengan `[REDACTED]`, menangani nested payload, dan membatasi string panjang.
- Immutability audit ditegakkan pada dua lapis: Eloquent menolak update/delete, dan trigger PostgreSQL/SQLite menolak bulk UPDATE/DELETE.
- Menambahkan idempotency key/fingerprint SHA-256, encrypted replay response, konflik fingerprint yang aman, expiry, dan rollback atomik.
- Menambahkan transactional outbox dengan event version, aggregate reference, payload hash, deduplication hash, per-consumer delivery receipt, row lock, retry/backoff terkontrol, terminal failure setelah lima attempt, scheduler dispatcher, dan unique queue job.
- Menambahkan abstraction metadata object storage tanpa upload evidence: disk/path, file identity, checksum, classification, owner/source reference, status, metadata aman, dan concurrency token.
- Menambahkan JSON error mapping aman untuk safe domain exception; response tidak menampilkan exception message internal atau stack trace.
- Mendokumentasikan pemakaian fondasi oleh modul berikutnya pada `docs/architecture/DATA_CONVENTIONS.md`.

### Schema yang dibuat

| Tabel | Tujuan |
|---|---|
| `audit_logs` | Business audit append-only dengan actor, before/after, reason, source, dan correlation |
| `idempotency_keys` | Deduplication request dan replay response terenkripsi |
| `outbox_messages` | Event transactional, retry state, deduplication, dan delivery schedule |
| `outbox_deliveries` | Receipt unik per message/consumer |
| `stored_object_metadata` | Metadata object privat tanpa mengunggah isi file |

Migration tidak membuat tabel organization, definisi KPI, target, actual, approval, score, rating, atau compensation; keputusan bisnis Step 00 yang masih terbuka tetap tidak dikunci.

### Contoh kontrak yang tervalidasi

- Audit `object.registered` menyimpan scope actor dan status objek, tetapi `access_token` serta `password` menjadi `[REDACTED]`.
- Event outbox `TestEvent` versi 1 diproses sekali per consumer; retry menyimpan hanya kode aman `consumer_failure`, bukan pesan exception mentah.
- Retry idempotent dengan key dan fingerprint sama mengembalikan result pertama tanpa menjalankan perubahan kedua.
- Update metadata dari `lock_version=1` ke 2 berhasil; stale writer dengan expected version 1 menerima `concurrency_conflict`/HTTP 409.

### Validasi dan bukti aktual

GitHub Actions run `38230761410` untuk implementation head `92d4e6c131bab967b5df6aa5e41977347030cc73` berakhir `success`; kelima job lulus:

| Gate | Hasil |
|---|---|
| PostgreSQL migration | database kosong migrate → rollback → migrate lulus, termasuk trigger append-only |
| Test suite PostgreSQL + Redis | 115 passed, 2 skipped, 345 assertions |
| Transaction/audit | rollback tidak meninggalkan domain row, audit palsu, atau outbox yatim; Eloquent dan bulk SQL mutation audit ditolak |
| Idempotency/concurrency/outbox | replay, fingerprint conflict, failed-command rollback, stale writer, deduplication, retry, dan delivery receipt lulus |
| PHP quality | `composer validate --strict`, `composer audit`, Pint, dan Larastan level 7 lulus |
| Frontend | `npm audit` dan production asset build lulus |
| Docker image | build, hygiene, runtime smoke, fixable HIGH/CRITICAL scan, SBOM generation/upload lulus |
| Docker Compose | checkout-clean bootstrap, 8 service healthy, readiness, private bucket check, queue exactly-once smoke, PostgreSQL test suite, dan persistence recreation lulus |

Validasi lokal tidak dinyatakan lulus pada turn ini karena proses terminal tidak dapat dibuat. Bukti runtime di atas berasal dari runner GitHub Actions dan stack Compose efemeral.

### Risiko dan batas lanjutan

- External outbox consumer wajib menerapkan idempotency memakai `messageId`; transaksi database lokal tidak dapat mengembalikan side effect eksternal yang sudah terjadi.
- Pesan yang mencapai lima kegagalan ditandai terminal; dashboard/replay operasional belum dibuat dan menjadi scope observability/operations berikutnya.
- Upload evidence, authorization object, malware scan, signed URL, retention, dan object storage production belum diimplementasikan.
- Retention/purge audit, idempotency, dan outbox menunggu DEC-011. Provider object storage/hosting menunggu DEC-014.
- Fondasi ini belum mengesahkan bobot, formula, target, rating, approver, SLA, atau aturan kompensasi.

### Gate berikutnya

- Step 05 dapat dimulai untuk identity, role, permission, organization scope, policy, dan authorization entry check.
- Publish KPI, scoring resmi, approval routing produksi, dan compensation tetap diblokir sampai keputusan bisnis terkait disetujui.


## 2026-10-12 — Step 05 Autentikasi, Organisasi, dan Otorisasi

**Status:** PARTIAL. Seluruh acceptance criteria lulus secara lokal (SQLite in-memory, Pint, Larastan, build, dan smoke runtime). Dua hal belum terbukti:

- Suite PostgreSQL + Redis belum dijalankan karena Docker Engine lokal tidak dapat berjalan pada sesi ini. Bukti CI dicatat pada addendum setelah push.
- SSO belum diimplementasikan. Ini sesuai prompt karena DEC-013 masih OPEN; yang tersedia adalah login lokal di balik adapter.

### Git preflight

- `git pull --ff-only origin main`: fast-forward 26 commit (Step 04 dan merge Dependabot) ke `224a19e8eed28de138573d3a2057130b1023e8dc`. Working tree bersih sebelum mulai.
- Baseline sebelum perubahan: 117 test (115 passed, 2 skipped) pada PHP 8.4.25.

### Scope selesai

#### Authentication

- Fortify memakai adapter `CredentialAuthenticator`; implementasinya `LocalPasswordAuthenticator`.
  - Pesan gagal login bersifat generik.
  - Waktu proses untuk akun yang tidak dikenal disamakan.
  - Akun nonaktif ditolak.
  - `IDENTITY_PROVIDER` selain `local` gagal secara eksplisit.
- MFA bersifat opsional dan dikendalikan switch `IDENTITY_MFA_REQUIRED` (default `false`, DEC-013). Switch ini juga berlaku pada update Livewire.
- `EnsureAccountIsActive` berjalan pada semua request web. Session akun nonaktif diakhiri sebelum dan sesudah request, sehingga login lewat passkey/2FA juga tertutup.

#### Lifecycle akun

- Akun dibuat oleh System Admin tanpa password yang diketahui admin. Pengguna membuat password lewat tautan reset, dan reset sekaligus memverifikasi email.
- Akun dapat dinonaktifkan dan diaktifkan kembali. Saat dinonaktifkan, `remember_token` diganti dan session database dihapus. System Admin aktif terakhir tidak dapat dinonaktifkan.
- Self-service hapus akun dihapus agar nama tetap muncul pada histori.
- Audit login mencakup login berhasil, gagal, lockout, logout, reset selesai, dan session diakhiri. Email yang dicoba dan password tidak disimpan.

#### Organisasi

- Hierarki Company → Group/Department → Team bertanggal efektif. Nama dan induk unit disimpan sebagai versi.
- Data pegawai dan penugasan home/temporary bertanggal efektif.
- Perubahan atas data yang sudah ada hanya boleh berlaku mulai hari ini atau sesudahnya. Penugasan yang sudah berakhir tidak dapat diubah.
- Hanya ada satu company. Setiap perubahan memakai optimistic lock dan dicatat di audit.

#### Role dan scope

- Tujuh role minimum. Role terpisah dari scope: company, department, team, data sendiri, atau sistem.
- Periode role tidak boleh dimulai sebelum hari ini.
- Pencabutan memakai `revoked_at`, sehingga akses berhenti seketika tanpa mengubah histori.
- Plafon capability per role dikunci oleh invariant:
  - Auditor hanya read-only;
  - System Admin dan Auditor tidak pernah memegang `kpi.value.modify` atau `approval.decide`.

#### Delegasi

- Setiap delegasi mencatat pemberi, penerima, role, scope, alasan, tanggal mulai, tanggal akhir (wajib), akun yang mengizinkan, dan audit.
- Delegasi ditolak bila tanggalnya mundur, melebihi scope atau periode role sumber, atau diberikan kepada diri sendiri.
- Delegasi hanya memberi hak baca dan approval.
- Delegasi gugur bila role sumber dicabut atau akun pemberi dinonaktifkan.
- Kedaluwarsa terjadi otomatis. Perintah `identity:expire-delegations` (berjalan per jam) menulis audit satu kali per delegasi.
- Tidak ada self-approval, termasuk persetujuan oleh penerima atas record milik pemberi.

#### Segregation of duties

- Tidak ada yang dapat mengubah hak akses sendiri.
- Aturan dua orang: admin yang membuat sebuah akun tidak dapat memberi role bisnis kepada akun tersebut.
- Access review menandai tiga kondisi:
  - System Admin yang juga memegang role bisnis;
  - Auditor yang juga memegang role operasional;
  - akun nonaktif yang masih memiliki role.

#### Authorization

- Semua keputusan akses melalui satu titik, yaitu `AccessControl`.
- Policy Laravel untuk `OrganizationUnit`, `Employee`, `User` (akun), `RoleAssignment`, dan `Delegation`, ditambah gate `administration.view` dan `access-review.view`.
- Tidak ada `Gate::before` atau bypass.
- Setiap halaman memeriksa authorization saat dimuat, dan setiap aksi Livewire memeriksa ulang terhadap objek target.

#### UI `/administration`

- Halaman yang tersedia:
  - struktur organisasi (per tanggal, dengan riwayat versi);
  - pegawai dan penugasan;
  - detail pegawai;
  - akun;
  - role dan scope;
  - delegasi;
  - access review dengan audit trail.
- Semua halaman memiliki filter dan pagination, dan setiap perubahan wajib disertai alasan.
- Menu Administration hanya muncul untuk akun yang memiliki capability terkait.

#### Seeder

- `OrganizationReferenceSeeder` membuat 1 company, 3 department, dan 21 team. Seed production wajib memakai `ORGANIZATION_REFERENCE_EFFECTIVE_FROM`.
- `DevelopmentIdentitySeeder` hanya berjalan pada `APP_ENV=local` dan menolak berjalan di production.
  - Membuat 13 akun fiktif `[DEV]` dengan domain `kpi.example.test`, NIK `DEV-xxxx`, dan satu contoh delegasi.
  - Password diambil dari `DEV_SEED_PASSWORD`, atau dibuat acak dan dicetak satu kali.

### File dan migration

- Migration:
  - `2026_10_12_000200_create_organization_tables.php`
    - membuat `organization_units`, `organization_unit_versions`, `employees`, dan `employee_assignments`;
    - menambahkan CHECK periode di PostgreSQL.
  - `2026_10_12_000300_create_identity_access_tables.php`
    - menambahkan kolom `users.public_id`, `status`, `deactivated_at`, `deactivation_reason`, `last_login_at`, `lock_version`, dan `provisioned_by_user_id`;
    - membuat `role_assignments` dan `delegations`;
    - menambahkan CHECK periode dan CHECK delegasi-bukan-diri-sendiri di PostgreSQL.
- Modul baru: `app/Modules/Organization/**` dan `app/Modules/IdentityAccess/**`.
- Tambahan di Shared: `BusinessDate`, `BusinessRuleViolation`, `Actor`, `GuardsInput`.
- Provider dan config: `OrganizationServiceProvider`, `IdentityAccessServiceProvider`, `config/identity.php`, `config/organization.php`, `bootstrap/app.php`, `routes/web.php`, `routes/console.php`, `config/navigation.php`.
- UI: `resources/views/pages/administration/*` (8 halaman Livewire dan partial), serta primitives `x-ui.select`, `x-ui.textarea`, dan `x-ui.panel`.
- Dihapus: `pages/settings/⚡delete-user-*.blade.php` dan `app/Livewire/Actions/Logout.php` (dead code).
- Dokumentasi:
  - `docs/architecture/IDENTITY_ACCESS.md` baru;
  - README, `APPLICATION_STRUCTURE.md`, `LOCAL_ENVIRONMENT.md`, dan `.env.example` diperbarui.

### Role–scope matrix aktual

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| organization.view / manage | A / — | A / — | A / — | — / — | A / A | A / — | A / — |
| employee.view / manage | A / — | A / — | A / — | diri sendiri / — | A / A | A / — | — / — |
| account.view / manage | — | — | — | — | — | A / — | A / A |
| role.view / manage | A / — | — | — | — | A / — | A / — | A / A |
| delegation.view / manage | A / — | — | — | — | A / A | A / — | A / — |
| access_review.view | A | — | — | — | A | A | A |
| kpi.value.modify (plafon Step 06+) | — | plafon | plafon | plafon | plafon | — | — |
| approval.decide (plafon Step 06+) | plafon | plafon | plafon | — | plafon | — | — |

Scope tiap role:

| Role | Scope |
|---|---|
| Management | company |
| Head | department atau satu team |
| TL | team |
| Employee | data sendiri |
| HR | company atau department |
| Auditor | company, department, atau team |
| SysAdmin | sistem |

Kondisi "C" pada ROLE_APPROVAL_MATRIX ditegakkan oleh modul Step 06–10.

### Validasi (PHP 8.4.25, lokal)

| # | Command | Exit | Hasil |
|---|---|---:|---|
| 1 | `composer validate --strict` | 0 | valid |
| 2 | `composer audit` / `npm audit --audit-level=high` | 0 / 0 | 0 advisory / 0 vulnerabilities |
| 3 | `vendor/bin/pint --test` | 0 | passed |
| 4 | `vendor/bin/phpstan analyse` (level 7) | 0 | 0 error |
| 5 | `php artisan test` | 0 | 256 test: 254 passed, 2 skipped (registrasi dorman), 889 assertions |
| 6 | Subset Step 05 (`tests/Feature/IdentityAccess`, `tests/Feature/Organization`, `tests/Unit/IdentityAccess`) | 0 | 141 passed, 553 assertions |
| 7 | `npm run build` | 0 | built |
| 8 | `php artisan route:list` | 0 | 68 route (8 route `/administration`) |
| 9 | Migrate → rollback → migrate (SQLite file berisi data seed) | 0 | lulus; `users.public_id` di-backfill untuk baris lama |
| 10 | `migrate:fresh --seed` (`APP_ENV=local`) + `identity:expire-delegations` | 0 | 25 unit, 13 akun `[DEV]`, 1 delegasi; 0 delegasi kedaluwarsa |
| 11 | Smoke runtime `php artisan serve` (SQLite, session database), login nyata per role | — | status halaman sesuai policy (tabel di bawah); log server tanpa exception |
| 12 | Suite PostgreSQL + Redis (`scripts/dev.sh test-pgsql`) | — | **Belum dijalankan**: Docker Engine lokal tidak tersedia; menunggu CI |

Smoke runtime (status HTTP setelah login):

| Akun | dashboard | administration | organization | employees | accounts | roles | delegations | access-review |
|---|---|---|---|---|---|---|---|---|
| dev.sysadmin | 200 | 200 | 200 | 403 | 200 | 200 | 200 | 200 |
| dev.hr | 200 | 200 | 200 | 200 | 403 | 200 | 200 | 200 |
| dev.auditor | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 |
| dev.head.produksi | 200 | 200 | 200 | 200 | 403 | 403 | 200 | 403 |
| dev.tl.woven | 200 | 200 | 200 | 200 | 403 | 403 | 200 | 403 |
| dev.staff.woven | 200 | 403 | 403 | 403 | 403 | 403 | 403 | 403 |

Direktori pegawai untuk TL Woven hanya memuat NIK `DEV-0301` dan `DEV-0302`. NIK dari Sales, AR, dan HR tidak muncul.

### Bukti negative authorization test

**Role dan capability (`AuthorizationScopeTest`)**

- Setiap role diuji terhadap 13 capability, baik allow maupun deny.
- Scope diuji untuk tujuh role pada lima posisi target: diri sendiri, team yang sama, team lain dalam department yang sama, department lain, dan produksi.
- HR dan Auditor ber-scope department diuji tetap di dalam department-nya.
- Manager untuk satu team tidak dapat melihat team saudara.

**Status assignment dan akun**

- Assignment yang kedaluwarsa atau masih terjadwal ditolak, tetapi histori tetap dapat direkonstruksi.
- Pencabutan berlaku seketika.
- Akun nonaktif ditolak untuk semua capability.
- Grant pada unit yang sudah ditutup tidak mencakup apa pun.
- TL kehilangan akses tepat sejak tanggal pegawai pindah team.

**Self-approval**

- Ditolak secara langsung.
- Ditolak lewat delegasi, baik oleh penerima atas recordnya sendiri maupun oleh penerima atas record milik pemberi.

**Plafon KPI**

- SysAdmin dan Auditor ditolak untuk `kpi.value.modify` dan `approval.decide` di semua unit.
- Auditor ditolak untuk semua capability non-view.

**Direct URL**

- Matriks 7 halaman × 8 akun.
- Record pegawai diuji untuk diri sendiri, rekan satu team, dan department lain.

**Forged Livewire action (semua menghasilkan 403)**

- Auditor dan Management mencoba memberi role.
- SysAdmin dan TL mencoba membuat unit.
- Auditor mencoba mencabut role, mengirim reset, dan menonaktifkan akun.
- SysAdmin mencoba menonaktifkan akun sendiri dan memberi role ke diri sendiri.
- TL mencoba membuka form pindah team dan mendelegasikan role milik orang lain.
- HR ber-scope department mencoba memindahkan pegawai ke department lain.

**Delegasi ditolak dengan rule key stabil bila:**

- diberikan kepada pemilik role atau kepada pemberi izin;
- berada di luar scope;
- tanggalnya mundur;
- tanggal akhirnya tidak valid;
- melebihi periode role sumber;
- role-nya tidak dapat didelegasikan;
- akun penerima nonaktif;
- duplikat.

**Role assignment ditolak bila:**

- diberikan kepada diri sendiri;
- tipe unit scope tidak sesuai;
- role Employee diberikan tanpa data pegawai;
- tanggalnya mundur;
- tumpang tindih dengan assignment lain;
- akun penerima nonaktif;
- mencabut role sendiri;
- role bisnis diberikan oleh admin yang membuat akun tersebut.

**Mutation check manual**

- Bila pemeriksaan scope di `Grant` dilonggarkan, 6 test authorization gagal.
- Bila `authorize()` pada aksi grant di halaman role dihapus, 2 test forged action gagal.
- Kedua mutasi sudah dikembalikan.

### Code review

Agent code-reviewer tidak menemukan temuan Critical. Semua temuan berikut sudah diperbaiki dan diberi test.

**HIGH**

- Pindah team dan pengakhiran penugasan dengan tanggal mundur dapat menulis ulang histori. Sekarang ditolak dengan `retroactive_change`.

**MEDIUM**

- Rename, re-parent, dan penutupan unit dengan tanggal mundur.
- Penerima delegasi dapat menyetujui record milik pemberi.
- "Akun boneka": admin membuat akun lalu memberinya role bisnis sendiri. Kini dicegah dengan aturan dua orang.
- Audit tautan reset tetap tercatat walaupun pengiriman di-throttle atau gagal.

**LOW**

- Role grant dengan tanggal mundur.
- Race pada unique constraint menghasilkan error 500; kini menjadi pesan domain yang aman. Unit induk juga dikunci saat membuat unit baru.
- System Admin terakhir dapat dinonaktifkan.
- Company kedua dapat dibuat.
- MFA tidak berlaku pada update Livewire.
- Access review untuk reviewer ber-scope department menampilkan grant di luar scope-nya.

### Keputusan dan asumsi

- Plafon capability diturunkan dari ROLE_APPROVAL_MATRIX (status PROPOSED). Kondisi "C" belum diimplementasikan karena menjadi tanggung jawab Step 06–10.
- Pembagian tugas: System Admin mengelola role assignment; HR Performance mengelola struktur, pegawai, dan delegasi. Approver aktual per team tidak di-seed (DEC-002 OPEN).
- Perubahan atas data yang sudah ada tidak boleh dimundurkan, untuk melindungi histori. Koreksi histori menunggu proses koreksi resmi. Pengecualian: pegawai baru dan unit baru boleh memakai tanggal mulai lampau.
- Aturan dua orang membuat pemberian role bisnis ke akun baru membutuhkan minimal dua System Admin. Konsekuensi operasional ini perlu dikonfirmasi IT/Security.
- Kombinasi role yang melanggar SoD tidak diblokir karena belum ada keputusan bisnis; kombinasi tersebut hanya ditandai di access review.
- Tanggal berlaku struktur referensi di non-production memakai placeholder tanggal PRD v0.1 (2026-10-12). HR wajib mengonfirmasi tanggal untuk production.
- Belum dimodelkan:
  - shift (DEC-015);
  - coaching notes (DEC-011);
  - retention audit dan session (DEC-010).
- Docker Desktop lokal sempat terjalankan tanpa sengaja saat pengecekan versi. Engine tidak siap dalam 5 menit, sehingga validasi PostgreSQL diserahkan ke CI.

### Blocker bisnis

- DEC-013: SSO dan kebijakan MFA.
- DEC-002: Team Leader, Manager/Head, dan approver pengganti untuk 21 team.
- DEC-010: retention data.
- DEC-011: akses coaching notes.
- DEC-015: shift.
- DEC-016: system of record pegawai.
- Sign-off matriks role/scope dan delegasi oleh HR Performance, Management, IT/Security, dan Internal Audit.

### Gate berikutnya

- Setelah CI (PostgreSQL + Redis) lulus untuk commit Step 05, status dapat dinaikkan menjadi PASS melalui addendum.
- Step 06 (kamus KPI dan template berversi) boleh dimulai setelah itu. Publish KPI tetap diblokir oleh DEC-003, DEC-005, dan DEC-006.

### Addendum 2026-10-12 — bukti GitHub Actions Step 05

**Status Step 05 diperbarui:** PASS untuk scope implementasi.

- **Bukti:** seluruh gate lokal dan CI untuk commit `850834ed54c6189fb2c5c362820886bb1b18626a` lulus.
- **SSO:** tetap belum tersedia (DEC-013).
- **Blocker bisnis:** daftar di atas masih berlaku.

GitHub Actions run `38255706324` (`ci`, push ke `main`) berakhir dengan conclusion `success`. Kelima job lulus:

| Job | Hasil | Bukti dari log |
|---|---|---|
| PHP quality | success | `composer audit` tanpa advisory; Pint PASS (171 file); Larastan `[OK] No errors` |
| Tests (PostgreSQL 18 + Redis) | success | Migration `000200`/`000300` lulus migrate → rollback → migrate, termasuk CHECK constraint PostgreSQL dan backfill `users.public_id`. Hasil test: 254 passed, 2 skipped (889 assertions). Readiness OK. Queue smoke: "Job smoke diproses tepat 1 kali" |
| Frontend | success | `npm audit` 0 vulnerabilities; build Vite berhasil |
| Docker image | success | Build, hygiene, runtime smoke, dan scan Trivy untuk fixable CRITICAL/HIGH lulus; SBOM terunggah |
| Docker Compose | success | Bootstrap dari checkout bersih lulus. `test-pgsql` di dalam stack (PostgreSQL `kpi_test` + Redis): 254 passed, 2 skipped (889 assertions) |

Gate berikutnya: Step 06 (kamus KPI dan template berversi). Publish KPI tetap diblokir oleh DEC-003, DEC-005, dan DEC-006.

## 2026-10-12 — Step 06 Kamus KPI dan Template Berversi

**Status:** PARTIAL — scope implementasi dan seluruh gate lokal lulus; validasi PostgreSQL/Redis, image, dan Compose menunggu GitHub Actions pada commit Step 06. Belum ada KPI atau template resmi yang dipublish.

### Scope selesai

- Menambahkan modul `KpiGovernance` untuk kamus KPI, versi definisi, template scorecard, versi template, dan item template yang menunjuk versi definisi secara eksplisit.
- Mengimplementasikan lifecycle `DRAFT → REVIEW → PUBLISHED → RETIRED`, pengembalian `REVIEW → DRAFT`, pembatalan versi terjadwal, optimistic locking, larangan self-review, audit trail, alasan perubahan, dan effective dating tanpa overlap.
- Published/review content serta item template non-draft dilindungi trigger database pada PostgreSQL dan SQLite. Perubahan isi published harus melalui versi baru.
- Mengimplementasikan bobot presisi empat desimal tanpa float dan validasi total tepat 100% sebelum review/publish.
- Mengimplementasikan formula sebagai uraian dan scoring rule sebagai strategy allowlist berversi; tidak ada `eval`, expression engine, atau executable input dari pengguna.
- Menambahkan template per unit dan subject (`employee`/`team`), jabatan opsional, komponen, urutan, mandatory flag, serta applicability berupa daftar jabatan terstruktur.
- Menambahkan UI Livewire untuk dashboard governance, dictionary/filter, detail/editor, perbandingan versi dan alasan, builder/preview template, review/publish/retire/withdraw, referensi penggunaan versi KPI, serta audit history.
- Menambahkan capability dan policy ter-scope. Hanya HR Performance dapat mengelola/publish; Management, Head, Team Leader, dan Auditor read-only sesuai scope; Employee dan System Admin ditolak.
- Menambahkan importer/seeder idempotent untuk **252** kandidat Lampiran A PRD sebagai `DRAFT` dengan `origin = prd_candidate`. Import pertama membuat 252; import kedua membuat 0 dan mengenali 252 existing.
- Menambahkan `TemplateResolver` sebagai kontrak Step 07 agar periode berbeda dapat memilih versi template sesuai business date tanpa mengubah histori.
- Mendokumentasikan schema, lifecycle, akses, precision, candidate boundary, serta aturan append-only untuk vocabulary dan scoring strategy.

### Schema dan strategi

Tabel baru:

- `kpi_definitions` dan `kpi_definition_versions`;
- `kpi_templates` dan `kpi_template_versions`;
- `kpi_template_items`.

Strategy allowlist tersedia:

- `ratio_to_target@1`;
- `inverse_ratio_to_target@1`;
- `range_band@1`;
- `binary@1`;
- `milestone_stage@1`.

Strategy tersebut adalah mekanisme teknis tervalidasi, bukan formula/rating bisnis yang sudah disetujui. DEC-004 tetap OPEN.

### Published-versus-draft

- Import kandidat pada database uji kosong: `DRAFT prd_candidate = 252`.
- KPI kandidat yang dipublish otomatis: **0**.
- Template resmi yang di-seed atau dipublish otomatis: **0**.
- Publish tetap membutuhkan definisi lengkap, strategy dan parameter valid, effective date, approval chain, sign-off reference, reviewer berbeda, serta—untuk template—KPI published yang berlaku dan bobot tepat 100%.

### Validasi lokal (PHP 8.4)

| # | Command/gate | Exit | Hasil |
|---|---|---:|---|
| 1 | Targeted Step 06 (`tests/Unit/KpiGovernance` + `tests/Feature/KpiGovernance`) | 0 | 72 passed, 217 assertions |
| 2 | Seluruh `php artisan test` | 0 | 328 test: 326 passed, 2 skipped (registrasi dorman), 1.187 assertions |
| 3 | `vendor/bin/pint --test` | 0 | passed |
| 4 | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | 0 / 0 | valid / tidak ada security advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 / 0 | 0 vulnerability / production assets built |
| 7 | Empty SQLite migrate → rollback → migrate | 0 | seluruh migration, termasuk `000400`, lulus dan dapat di-rollback |
| 8 | `OrganizationReferenceSeeder` + `kpi:import-candidates` dua kali | 0 | 252 created lalu 0 created + 252 existing |
| 9 | `php artisan route:list --path=kpi` | 0 | 5 route Livewire KPI terdaftar |
| 10 | `git diff --check` | 0 | bersih |

Perintah agregat `composer test` tidak dipakai sebagai bukti tunggal di Windows karena executable child `pint` memilih PHP XAMPP 8.2.12. Semua gate penyusunnya dijalankan langsung dan lulus memakai PHP 8.4; CI memakai PHP 8.4 secara eksplisit.

### Code review

Temuan sebelum commit sudah diperbaiki dan diuji:

- Tiga temuan tipe Larastan pada query row-lock dan union collection diperbaiki di sumber tanpa suppression.
- Referensi "versi KPI dipakai template" semula dapat menampilkan kode template dari unit di luar scope pembaca. Query sekarang dibatasi oleh `kpi_template.view` dan unit scope; regression test lintas unit ditambahkan.

### Keputusan dan blocker bisnis

- DEC-003 (bobot scorecard) masih PROPOSED; tidak ada komposisi bobot PRD yang dijadikan template resmi.
- DEC-004 (rating/scoring rules), DEC-005 (release scope KPI), dan DEC-006 (definisi KPI resmi) masih OPEN.
- Kandidat Lampiran A hanya nama dan unit pemilik; target, formula, source, owner, evidence, approver, dan sign-off tidak dikarang.
- Hak bersyarat Manager/Team Leader pada `ROLE_APPROVAL_MATRIX` belum diaktifkan karena kondisi bisnisnya belum disetujui.
- Validasi PostgreSQL 18 + Redis, Docker image, Compose, scan vulnerability, dan SBOM menunggu CI commit Step 06.

### Gate berikutnya

- Commit dan push Step 06 ke `origin/main`, lalu tunggu seluruh GitHub Actions lulus dan catat addendum PASS.
- Setelah CI Step 06 lulus, Step 07 dapat dimulai. Penggunaan KPI/template sebagai konfigurasi resmi tetap diblokir sampai DEC-003/004/005/006 memiliki sign-off yang diperlukan.

### Addendum 2026-10-12 — bukti GitHub Actions Step 06

**Status Step 06 diperbarui:** PASS untuk scope implementasi.

- **Commit tervalidasi:** `e053044aac5bca1ce8f42a2511444b546b40096d` pada `origin/main`.
- **CI:** GitHub Actions run [`38275002475`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38275002475) berakhir dengan conclusion `success`.
- **Kandidat PRD:** 252 tetap `DRAFT`; KPI kandidat dan template resmi yang dipublish otomatis tetap **0**.
- **Batas runtime:** headroom test 512 MB dikonfigurasi khusus PHPUnit; batas PHP production tetap 256 MB.

Kelima job CI lulus:

| Job | Hasil | Bukti |
|---|---|---|
| PHP quality | success | Composer validate/audit, Pint, dan Larastan lulus |
| Tests (PostgreSQL 18 + Redis) | success | Empty-database migrate → rollback → migrate, suite PostgreSQL/Redis, readiness, dan queue smoke lulus |
| Frontend | success | `npm audit` dan production asset build lulus |
| Docker image | success | Build, image hygiene, runtime smoke, scan fixable CRITICAL/HIGH, dan SBOM lulus |
| Docker Compose | success | Bootstrap checkout bersih, suite dalam stack, persistensi setelah container recreation, serta teardown lulus |

CI juga menemukan dua masalah portabilitas/infrastruktur yang telah diperbaiki sebelum status PASS:

- assertion trigger PostgreSQL sekarang diisolasi dalam nested transaction/savepoint agar expected exception tidak mengaborsi transaksi test induk (`596c0c1`);
- batas memori PHPUnit diterapkan pada proses Pest/PHPUnit yang sebenarnya, bukan hanya proses induk Artisan (`646dfba` dan `e053044`).

DEC-003, DEC-004, DEC-005, dan DEC-006 tetap terbuka. Karena itu Step 06 secara teknis selesai, tetapi penggunaan KPI/template sebagai konfigurasi bisnis resmi tetap menunggu keputusan dan sign-off. Gate berikutnya adalah Step 07.

### Addendum 2026-10-13 — regression test temuan code review Step 06

Code review Step 06 tidak menemukan temuan Critical atau High. Perbaikan temuan Medium dan Low sudah masuk commit `7427674`, tetapi belum memiliki test khusus. Addendum ini menambahkan `tests/Feature/KpiGovernance/VersionTimelineTest.php` yang berisi 6 test (31 assertions).

| Temuan review | Perbaikan (sudah ada di `7427674`) | Bukti test baru |
|---|---|---|
| **MEDIUM: supersede vs retire race** — versi lama yang ditutup tidak menaikkan `lock_version`, dan retire tidak mengunci row | `VersionTimeline` menutup versi lama lewat optimistic lock. Retire mengunci induk lalu row versi | Retire dengan lock yang sudah basi ditolak (`ConcurrencyConflict`). Retire yang memperpanjang melewati versi baru ditolak (`invalid_period`). Tanggal akhir v1 tetap 2026-11-01 |
| **MEDIUM: versi terjadwal tidak dapat dibatalkan** tanpa menciptakan celah periode | `withdrawScheduled`: versi berubah menjadi retired dengan interval kosong, dan versi sebelumnya dibuka kembali | Setelah pembatalan, 2026-11-15 kembali memakai v1. Audit `kpi_definition.withdrawn` tercatat. Versi yang sudah berlaku ditolak (`not_scheduled`). Versi koreksi v3 tetap dapat dipublish |
| **LOW: insert langsung berstatus non-draft** | Trigger `*_insert_guard` di PostgreSQL dan SQLite | `INSERT` berstatus `published` lewat SQL langsung ditolak. Assertion dibungkus savepoint agar aman di PostgreSQL |
| **Test gap: aturan timeline di level template** | Logika timeline dipakai bersama definisi dan template | Mencakup: `retroactive_change`, `returnToDraft` beserta alasannya, `effective_overlap`, publish v2, withdraw v2 (v1 dibuka kembali), dan retire v1. Resolver memberi hasil tepat untuk 2026-11-15, 2026-11-30, dan 2026-12-01 |
| **LOW: versi KPI yang dipin berakhir di tengah masa template** | `pinnedKpiWarnings` tampil di preview template | Peringatan muncul setelah KPI di-retire pada 2026-12-01 |
| **Test gap: `addItem` dengan KPI di luar scope** | `authorize('view', definition)` saat menambah item | HR ber-scope ACC menambah KPI Woven ditolak (403). Menambah KPI AR berhasil |

Validasi lokal (PHP 8.4.25):

- `vendor/bin/pint --test`: passed.
- `phpstan`: 0 error.
- `php artisan test`: 334 test (332 passed, 2 skipped), 1.218 assertions.

Status Step 06 tetap **PASS** untuk scope implementasi. DEC-003/004/005/006 tetap terbuka. Gate berikutnya tetap Step 07.

## 2026-10-13 — Step 07 Periode, Penugasan, dan Target

**Status:** PARTIAL — scope implementasi dan seluruh gate lokal lulus, termasuk suite penuh pada PostgreSQL 18 + Redis di stack Docker Compose lokal. Bukti GitHub Actions untuk commit Step 07 baru tersedia setelah push dan dicatat lewat addendum. Belum ada periode resmi, dan jalur approval target resmi masih menunggu DEC-002.

### Git preflight

- `git fetch origin` lalu `git merge --ff-only origin/main`: `Already up to date`.
- HEAD awal `cb465a68c38103ef6ac8ff46ef0e1c03a16e4bdc`, sama dengan `origin/main`; working tree bersih.

### Scope selesai

- **Modul `PeriodAssignment`.** Periode dengan tipe, rentang tanggal inklusif, zona waktu sendiri, scope unit organisasi, tanggal acuan penugasan, dan deadline per tahap. Lifecycle `DRAFT → OPEN → IN_PROGRESS → REVIEW → FINALIZED → LOCKED`.
- **Snapshot penugasan.** Saat periode dibuka, sistem menyimpan pegawai (home team dan jabatan pada tanggal acuan), unit, jalur hierarki, versi template yang berlaku, dan approver per tahap, dalam satu transaksi dengan perubahan status.
- **Modul `Target`.** Slot target dibuat dari snapshot pada level penilaian KPI. Versi target mengikuti `DRAFT → SUBMITTED → APPROVED`, dengan pengembalian ke draft dan revisi beralasan untuk target final.
- **Staged import CSV.** Validasi per baris, ringkasan sebelum commit, deteksi duplikat baris dan file (SHA-256), commit atomik dan idempotent, serta unduhan file isian.
- **Aturan input KPI.** Definisi KPI mendapat field opsional `decimal_places`, `min_valid_value`, dan `max_valid_value` (KPI_DEFINITION_TEMPLATE §D). Prompt meminta precision dan min/max diterapkan dari definisi, sedangkan schema Step 06 belum memilikinya. Ketiganya masuk content guard sehingga immutable setelah versi keluar dari draft.
- **UI Livewire.** Daftar dan pembuatan periode; detail periode dengan pratinjau atau snapshot penugasan, tindakan lifecycle, penjadwalan ulang deadline, kelengkapan target, dan audit; matriks target; halaman import.
- **Akses.** Capability baru `period.view`, `period.manage`, `target.view`, `target.manage`, `target.approve`, policy, dan menu "Periode & Target".
- **Dokumentasi.** `docs/architecture/PERIOD_TARGET.md` baru; `IDENTITY_ACCESS.md`, `KPI_GOVERNANCE.md`, `APPLICATION_STRUCTURE.md`, dan `DATA_CONVENTIONS.md` diperbarui.

### Lifecycle

| Transisi | Status di Step 07 | Syarat |
|---|---|---|
| DRAFT → OPEN | Tersedia | Scope aktif pada tanggal acuan, tidak tumpang tindih dengan periode yang sudah dibuka, minimal satu subjek tercakup template |
| OPEN → IN_PROGRESS | Tersedia | Semua target wajib sudah final |
| IN_PROGRESS → REVIEW → FINALIZED → LOCKED | Hanya di enum dan trigger | Belum ada use case; menunggu precheck kelengkapan, kalibrasi, dan approval Step 08–10 |

- Tidak ada transisi mundur. Trigger database menolak lompatan status, insert berstatus non-draft, perubahan identitas periode setelah dibuka, dan penghapusan periode non-draft.
- Duplikat (`period_duplicate`) dan tumpang tindih (`period_overlap`) ditolak untuk tipe yang sama pada scope yang berhubungan. Pemeriksaan diulang di bawah row lock saat membuka.
- Deadline bersifat opsional dan tidak memiliki nilai bawaan (DEC-009). Tidak ada grace period otomatis; deadline periode yang sudah dibuka hanya berubah lewat penjadwalan ulang beralasan dan teraudit.

### Aturan snapshot

- Subjek: semua pegawai dengan home team dalam scope pada tanggal acuan, dan semua unit dalam scope. Penugasan sementara tidak membuat scorecard kedua.
- Template: versi yang berlaku pada tanggal acuan; template khusus jabatan didahulukan, lalu template unit. Tidak ada pewarisan dari unit induk.
- Cakupan: `covered`, `no_template`, atau `kpi_not_in_force`. Subjek tidak tercakup tetap disimpan agar terlihat.
- Approver: pemegang role tiap tahap (TL, Head, HR, Management) yang scope-nya mencakup unit subjek. Pegawai tidak pernah menjadi approver dirinya sendiri. Tahap tanpa approver dicatat sebagai gap, tidak diisi asumsi.
- Baris snapshot dan slot target menolak `UPDATE` dan `DELETE` di level database.

### Schema

Migration baru:

- `2026_10_13_000450_add_input_rules_to_kpi_definition_versions`;
- `2026_10_13_000500_create_period_and_target_tables`: `assessment_periods`, `assessment_period_deadlines`, `period_assignments`, `period_assignment_approvers`, `period_targets`, `period_target_versions`, `target_import_batches`, `target_import_rows`.

Outbox event: `PeriodOpened` v1, `TargetApproved` v1, `ImportCommitted` v1.

### Bukti import

| Skenario | Hasil test |
|---|---|
| File valid | Staging menghasilkan ringkasan (3 baris: 2 valid, 1 dilewati); target tidak berubah sampai commit; commit membuat 2 draft bersumber `import` |
| Commit ulang | Hasil sama, tidak ada versi atau event kedua |
| File yang sudah di-commit diunggah lagi | Ditolak (`import_already_committed`) |
| File dengan baris salah | 7 baris error dengan pesan per baris (presisi, satuan, KPI tidak dikenal, subject_type, duplikat, kolom kosong); commit ditolak; 0 target tersimpan |
| Satu baris salah di antara baris valid | Commit ditolak seluruhnya; 0 target tersimpan |
| Target berubah setelah staging (baris valid maupun unchanged) | Commit ditolak (`import_stale`); 0 baris diterapkan; batch tetap staged |
| File bukan CSV yang diharapkan | Kolom wajib hilang, bukan UTF-8, kosong, lebih dari 1 MB, atau terlalu banyak baris: ditolak sebelum staging |
| Titik koma + BOM + kolom tambahan | Diterima |

### Test boundary dan concurrency

- **Asia/Jakarta.** Dengan deadline target 5 November: simpan pada `2026-11-05 16:59:59 UTC` (23:59:59 WIB) diterima tanpa alasan; pada `17:00:00 UTC` (00:00 WIB 6 November) ditolak `cutoff_reason_required` walaupun tanggal UTC masih 5 November.
- **Perpindahan pegawai.** Pegawai dipindah setelah snapshot November: baris November identik sebelum dan sesudah; periode Desember mengikuti team, jabatan, nama, dan template baru.
- **Pemilihan versi template.** v1 berlaku 12 Oktober dan v2 berlaku 1 Desember: periode November memakai v1, Desember memakai v2.
- **Concurrency.** `lock_version` basi pada periode, draft target, dan batch import ditolak; periode tidak dapat dibuka dua kali dan snapshot tidak tergandakan; dua draft bertumpang tindih tidak dapat sama-sama dibuka; halaman matriks basi tidak dapat menimpa draft yang diubah atau dibuat orang lain.
- **Authorization.** Halaman dan aksi diuji per role lewat URL langsung dan aksi Livewire palsu; HR department tidak dapat membuat periode company, menjalankan lifecycle periode company, atau mengubah target unit lain.

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4.25) | 463 test: 461 passed, 2 skipped (registrasi dorman), 1.874 assertions |
| 2 | `bash scripts/dev.sh test-pgsql` (PostgreSQL 18.6 + Redis, stack Compose lokal) | 463 test: 461 passed, 2 skipped, 1.874 assertions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback 2 migration → migrate | lulus |
| 8 | `php artisan route:list --path=periods` | 5 route terdaftar |
| 9 | `git diff --check` dan pemeriksaan CRLF | bersih |

Test baru Step 07: 129 (dari 334 menjadi 463).

### Code review

Dua review independen (PHP/Laravel dan security) dijalankan sebelum commit. Tidak ada temuan Critical. Temuan yang diperbaiki, masing-masing dengan test regresi:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Batch import terlihat dan dapat dibatalkan oleh HR ber-scope lebih sempit; staging file yang sama membatalkan batch akun lain | Batch hanya untuk pengunggah atau HR pengelola seluruh periode; staging ulang hanya mengganti batch milik akun yang sama |
| High | Optimistic lock pada matriks target tidak efektif karena versi dibaca ulang saat simpan | Halaman menyimpan versi draft yang pertama kali ditampilkan (`#[Locked]`, 0 bila belum ada); pengembalian target dan commit/batal import juga memakai versi yang dilihat |
| Medium | Aturan dua orang hanya memeriksa pengaju | Kolom `updated_by_user_id`; penyetuju tidak boleh pembuat, pengubah terakhir, atau pengaju |
| Medium | Baris `unchanged` tidak ikut pemeriksaan basi saat commit | Ikut diperiksa |
| Medium | Batas baris CSV baru diperiksa setelah seluruh file diurai | Dihitung di dalam loop pembacaan |
| Medium | Approver dibaca pada tanggal acuan walaupun role-nya sudah berakhir saat periode dibuka | Role dibaca pada tanggal acuan atau hari pembukaan, mana yang lebih akhir |
| Medium | `open` mengunci semua periode bertipe sama dan membaca tipe sebelum lock | Hanya draft yang dikunci; periode dibaca setelah lock |
| Medium | `whereIn` dengan satu bind per target | Subquery dan chunk |
| Medium | Kolom pengaju/penyetuju versi approved dapat diubah lewat SQL langsung | Trigger menolak semua `UPDATE` pada versi approved |
| Low | Staging pada periode tertutup; flag cut-off hilang saat deadline dimundurkan; template tanpa item dianggap tercakup; `decimal_places` negatif; formula dengan spasi di depan | Diperbaiki |

Temuan yang dicatat tetapi tidak dikerjakan di step ini:

- persetujuan massal tidak membawa token versi yang dilihat penyetuju;
- submit dan approve tidak memeriksa cut-off;
- draft periode yang keliru belum dapat dihapus;
- pratinjau penugasan dihitung ulang pada setiap interaksi halaman;
- escape `LIKE` tanpa klausa `ESCAPE` (pola yang sama sejak Step 05).

### Keputusan dan asumsi

- Rentang periode disimpan inklusif, berbeda dari tanggal berlaku yang setengah terbuka. Alasannya tercatat di `PERIOD_TARGET.md` §1.
- Tipe periode hanya klasifikasi. Tanggal selalu diisi eksplisit karena kalender bisnis belum diputuskan.
- Satu periode memiliki satu scope unit, sehingga pilot beberapa team dijalankan sebagai beberapa periode.
- Nilai target divalidasi hanya dengan aturan yang dinyatakan definisi KPI. Tidak ada batas bawaan.

### Blocker keputusan

- **DEC-002 (approval chain) OPEN.** PRD mewajibkan target disetujui, tetapi penyetuju per team belum diputuskan dan seluruh hak "Menetapkan target" pada ROLE_APPROVAL_MATRIX masih bersyarat. `target.manage` dan `target.approve` karena itu hanya dipegang HR Performance dengan aturan dua orang. Ini mekanisme teknis, bukan jalur approval resmi. Hak bersyarat Manager, Team Leader, dan Employee belum diaktifkan. Jalur pengganti saat approver kosong atau diri sendiri juga belum ada; gap hanya ditampilkan.
- **DEC-009 (SLA) OPEN.** Deadline tidak memiliki nilai bawaan dan tidak ada eskalasi.
- **DEC-015 (kalender produksi) OPEN.** Kalender kerja dan shift belum dimodelkan.
- **DEC-003/004/005/006 OPEN/PROPOSED.** Belum ada KPI atau template resmi, sehingga belum ada periode resmi yang dapat dibuka dengan data bisnis nyata.
- Kebijakan pegawai yang pindah di tengah periode, pegawai baru setelah snapshot, dan grace period belum diputuskan dan tidak diimplementasikan.

### Batasan

- Transisi REVIEW, FINALIZED, dan LOCKED belum dapat dijalankan.
- Snapshot tidak dapat ditambah atau dikoreksi setelah periode dibuka; itu bagian proses koreksi Step 10.
- Employee belum dapat melihat target pribadinya; tampilan itu bagian dashboard Step 11.
- Hanya deadline penetapan target yang ditegakkan. Deadline tahap lain disimpan untuk step berikutnya.

### Gate berikutnya

- Commit dan push Step 07 ke `origin/main`, tunggu seluruh GitHub Actions lulus, lalu catat addendum.
- Setelah CI lulus, Step 08 (aktual, evidence, dan staged import) dapat dimulai.

### Addendum 2026-10-13 — bukti GitHub Actions Step 07

**Status Step 07 diperbarui:** PASS untuk scope implementasi.

- **Commit tervalidasi:** `5734032e65448709316081bb0574fc7c9123e3da` pada `origin/main`.
- **CI:** GitHub Actions run [`38342034353`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38342034353) berakhir dengan conclusion `success`; kelima job lulus (PHP quality, Tests PostgreSQL 18 + Redis, Frontend, Docker image, Docker Compose).
- **Run pertama gagal.** Run [`38341224670`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38341224670) untuk commit `c8fd2bf` gagal pada job Docker Compose: 1 dari 463 test gagal, empat job lain lulus. Test yang gagal adalah test Step 06 (`KpiDefinitionLifecycleTest`) yang membaca audit log tanpa `orderBy`, sehingga urutan baris di PostgreSQL tidak dijamin. Test yang sama lulus pada job PostgreSQL lainnya dan pada run lokal. Perbaikannya menambahkan `orderBy('id')` (`5734032`); kode aplikasi tidak berubah.

DEC-002, DEC-009, dan DEC-015 tetap terbuka. Jalur approval target resmi, transisi REVIEW/FINALIZED/LOCKED, dan koreksi snapshot tetap menunggu keputusan dan step berikutnya. Gate berikutnya adalah Step 08.

## 2026-10-13 — Step 08 Aktual, Evidence, dan Import

**Status:** PARTIAL — scope implementasi dan seluruh gate lokal lulus, termasuk suite penuh pada PostgreSQL 18 + Redis di stack Compose lokal. Dua hal menahan PASS: bukti GitHub Actions baru tersedia setelah push, dan **antivirus belum terintegrasi** sehingga evidence belum boleh dipakai di staging atau production.

### Git preflight

- HEAD awal `74fea213807ba14ba1594125ea10c9c8682c144e`, sama dengan `origin/main`; working tree bersih sebelum mulai.

### Scope selesai

- **Modul `ActualEvidence`.** Aktual per rentang observasi dengan nilai, satuan, sumber, record sumber, pelaku, status, catatan, dan versi.
- **Lifecycle** `DRAFT → SUBMITTED → VERIFIED`, dengan `REJECTED → DRAFT`. Missing bukan status: itu ketiadaan entri.
- **Evidence.** Upload ke disk private S3-compatible, validasi isi file, karantina sampai lolos pemindaian, tautan bertanda tangan berumur pendek, dan audit unduhan.
- **Staged import CSV/XLSX.** Upload → parse → validasi → pratinjau → commit atomik → rekonsiliasi, dengan unduhan baris error.
- **UI.** Daftar periode aktual, halaman entri (personal atau massal sesuai scope) dengan bukti dan riwayat, serta halaman import.
- **Akses.** Capability `actual.view`, `actual.enter`, `actual.verify`, dan menu "Aktual".
- **Dokumentasi.** `docs/architecture/ACTUAL_EVIDENCE.md` baru; `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, dan `DEPENDENCY_POLICY.md` diperbarui.

### Lifecycle aktual

| Aturan | Implementasi |
|---|---|
| Kapan boleh diisi | Hanya saat periode IN_PROGRESS |
| Nol, N/A, missing | Nol adalah entri bernilai `0`; N/A adalah entri tersendiri yang wajib beralasan; missing berarti tidak ada entri. Constraint PostgreSQL menjaga konsistensi jenis entri dan nilai |
| Rentang observasi | Inklusif, di dalam periode, KPI harian per tanggal, tidak tumpang tindih per target |
| Simpan | Satu kali simpan berlaku semua atau tidak sama sekali, dengan pesan per baris |
| Terlambat | Setelah deadline pengisian aktual (zona waktu periode), simpan dan ajukan wajib beralasan; tanda terlambat menetap |
| Bukti wajib | Tidak dapat diajukan tanpa bukti; tidak dapat diverifikasi bila bukti tidak dapat dibuka |
| Verifikasi | Team Leader dalam scope; bukan pengisi, pengubah terakhir, pengaju, atau pemilik aktual |
| Final | Versi verified menolak semua `UPDATE` dan `DELETE`. Identitas aktual (target, rentang, sumber) juga immutable |

### Kontrol evidence

- Jenis file ditentukan dari isi: ekstensi, MIME hasil `finfo`, dan signature byte awal harus cocok. MIME dari browser tidak dipakai. Allowlist: `pdf`, `png`, `jpg`, `jpeg`, `xlsx` (harus workbook asli tanpa macro).
- Key objek dibuat sistem; nama file klien hanya teks tampilan yang sudah dibersihkan.
- Batas teknis: 5 MB per file, 5 file per aktual, checksum duplikat ditolak.
- PostgreSQL hanya menyimpan metadata dan SHA-256; binary tidak pernah masuk database.
- Unduhan lewat route bertanda tangan 5 menit yang terikat ke akun peminta, dengan pemeriksaan ulang hak akses, `attachment`, `nosniff`, CSP sandbox, dan `no-store`.

### Integrasi yang belum tersedia

- **Antivirus.** Tidak ada produk yang dipilih atau diintegrasikan. Port `MalwareScanner` tersedia. Dengan `EVIDENCE_SCANNER=none` (default) semua file tetap karantina, tidak dapat dibuka, dan tidak meloloskan verifikasi KPI berbukti wajib. `local-unscanned` hanya berlaku pada environment local/testing dan melabeli file "belum dipindai", bukan "bersih". Di environment lain setting itu jatuh ke karantina. Tidak ada klaim bahwa antivirus aktif.
- Upload langsung dari browser ke object storage belum ada; file melewati unggahan sementara Livewire lalu dipindahkan server.
- Job pembersihan binary yatim dan kebijakan retention menunggu DEC-010.

### Hasil import

| Skenario | Hasil test |
|---|---|
| File valid (nilai, nol, N/A, baris kosong) | 5 baris: 4 valid, 1 dilewati. Commit membuat 4 draft; rekonsiliasi `1296.5000` = `1296.5000` |
| Retry commit | Hasil sama; tidak ada versi atau event kedua |
| File sama diunggah lagi setelah commit | Ditolak (`import_already_committed`) |
| File dengan baris salah | 10 dari 11 baris error dengan pesan per baris (presisi, satuan, rentang, periode, record sumber ganda, N/A tanpa alasan, sumber setengah isi, KPI tidak dikenal, formula). Commit ditolak; 0 aktual tersimpan; baris error dapat diunduh dengan formula dinetralkan |
| Aktual berubah setelah staging | Commit ditolak (`import_stale`); 0 baris diterapkan |
| Record sumber sudah pernah diimpor | Baris ditolak sebagai duplikat |
| XLSX dengan sel angka dan tanggal bertipe | Dibaca sama seperti CSV |
| File bukan sheet yang diharapkan, terlalu besar, terlalu banyak baris, atau arsip yang mengembang | Ditolak sebelum staging |

### Test keamanan

- File berbahaya: executable berekstensi `.pdf`, skrip berekstensi `.png`, HTML berkedok PDF, ekstensi ganda, tanpa ekstensi, file kosong, ZIP non-workbook dan workbook ber-macro berekstensi `.xlsx` — semuanya ditolak tanpa menyisakan objek.
- Nama file berbahaya (path traversal, path Windows, null byte, markup) tidak pernah masuk key objek.
- Akses tidak sah: tautan milik akun lain, tanpa tanda tangan, dimodifikasi, kedaluwarsa, akun di luar scope, System Admin, dan tamu — semuanya ditolak tanpa tercatat sebagai unduhan.
- Aksi Livewire palsu oleh role baca-saja, pegawai atas aktual rekan, dan Team Leader di luar team ditolak.
- Concurrent edit: versi basi dan "tidak melihat draft" ditolak.

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4.25) | 537 test: 534 passed, 3 skipped, 2.315 assertions |
| 2 | `bash scripts/dev.sh test-pgsql` (PostgreSQL 18.6 + Redis, stack Compose lokal) | 537 test: 535 passed, 2 skipped, 2.331 assertions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Skip ketiga adalah test constraint nilai/jenis entri yang hanya ada di PostgreSQL; test itu berjalan pada gate 2. Test baru Step 08: 74 (dari 463 menjadi 537).

### Dependency baru

`openspout/openspout` untuk membaca XLSX secara streaming. Alasannya tercatat di `DEPENDENCY_POLICY.md`.

### Code review

Dua review independen (PHP/Laravel dan security). Tidak ada temuan Critical. Diperbaiki dengan test regresi:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Tanggal observasi dari URL atau input kosong sampai ke SQL; error di PostgreSQL | Hanya tanggal valid yang dipakai; selain itu kembali ke rentang periode |
| Medium | Race antara ajukan dan lepas bukti | Ajukan dan verifikasi mengunci baris aktual sebelum membaca bukti; perubahan bukti membaca versi di bawah lock |
| Medium | Arsip XLSX kecil dapat mengembang sangat besar; baris kosong tidak dihitung | Batas ukuran terekstrak dan jumlah entri; batas baris fisik |
| Medium | Sel angka XLSX menampilkan noise biner dan ditolak | Dibatasi 15 digit signifikan |
| Medium | Nilai yang diketik untuk entri yang sudah diajukan dilewati diam-diam | Dilaporkan sebagai error per baris |
| Medium | Filter atau pencarian menghapus nilai yang belum disimpan | Hanya pergantian rentang observasi yang mereset |
| Medium | Referensi sumber diabaikan untuk aktual yang sudah ada | Ketidakcocokan ditolak eksplisit |
| Medium | Penolakan tanpa pemeriksaan scope di service; ajukan massal mengunci draft orang lain | Service memeriksa scope dan independensi; ajukan hanya draft milik sendiri |
| Medium | Setting scanner tidak dikenal menyebabkan error | Fail closed ke karantina dengan peringatan log |
| Medium | Total rekonsiliasi dapat overflow; pembersihan objek dapat menutupi error asli | Penjumlahan desimal aman; pembersihan dijaga |
| Low | `.xlsx` menerima ZIP apa pun; identitas `kpi_actuals` dapat diubah lewat SQL; index batch; halaman audit basi | Diperbaiki |

Dicatat tetapi tidak dikerjakan: tidak ada exclusion constraint PostgreSQL untuk rentang tumpang tindih (dijaga lock aplikasi), agregasi kelengkapan masih di PHP, pemeriksaan independensi hanya melihat pengubah terakhir, dan pengajuan belum dapat dilakukan per baris.

### Keputusan dan asumsi

- Hak akses mengikuti entri `A` pada ROLE_APPROVAL_MATRIX: Employee mengisi aktualnya sendiri, Team Leader memverifikasi selain miliknya.
- Aktual untuk target unit dan import massal dilakukan HR Performance, karena hak "bila data owner" untuk Manager dan Team Leader masih bersyarat.
- Batas ukuran, jumlah, dan jenis file adalah batas teknis, bukan kebijakan evidence bisnis.

### Blocker keputusan

- **Antivirus** belum dipilih (open item THREAT_MODEL). Blocker untuk staging dan production.
- **DEC-016 (source ownership) OPEN.** Kombinasi kunci duplikasi resmi belum disetujui; yang dipakai adalah sistem sumber + referensi per target.
- **DEC-015 (kalender) OPEN.** Jumlah rentang observasi yang seharusnya ada tidak dihitung; kelengkapan dihitung per target.
- **DEC-004 (aturan N/A dan missing) OPEN.** Sistem membedakan ketiganya; cara menilainya dikerjakan Step 09 setelah keputusan.
- **DEC-006 dan DEC-010 OPEN.** Kebijakan evidence per KPI dan retention belum ada.
- **DEC-002 OPEN.** Data owner untuk target unit dan jalur verifikasi resmi per team belum diputuskan.

### Gate berikutnya

- Commit dan push Step 08, tunggu GitHub Actions, lalu catat addendum.
- Step 09 (scoring engine) dapat dimulai secara teknis, tetapi skor resmi tetap menunggu DEC-004 dan DEC-006.

### Addendum 2026-10-13 — bukti GitHub Actions Step 08

**Status Step 08 diperbarui:** PASS untuk scope implementasi. Pemakaian evidence di staging dan production tetap **BLOCKED** sampai antivirus terintegrasi.

- **Commit tervalidasi:** `b4a697fed02179801882d50930c691d0695fad3a` pada `origin/main`.
- **CI:** GitHub Actions run [`38346104488`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38346104488) berakhir dengan conclusion `success`; kelima job lulus (PHP quality, Tests PostgreSQL 18 + Redis, Frontend, Docker image, Docker Compose).
- Image Docker berhasil dibangun dengan dependency baru `openspout/openspout` dan lolos scan vulnerability.

Antivirus, DEC-002, DEC-004, DEC-006, DEC-010, DEC-015, dan DEC-016 tetap terbuka. Gate berikutnya adalah Step 09; skor resmi tetap menunggu DEC-004 dan DEC-006.
