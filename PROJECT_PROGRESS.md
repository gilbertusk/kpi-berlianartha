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

