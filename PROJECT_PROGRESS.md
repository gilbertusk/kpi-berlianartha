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

## 2026-10-13 — Step 09 Scoring Engine dan Scorecard

**Status:** PARTIAL — mesin skor, snapshot, halaman, dan test selesai dan lulus secara lokal. **Engine bisnis BLOCKED:** DEC-004 masih OPEN, sehingga tidak ada policy scorecard yang disetujui dan setiap perhitungan skor resmi ditolak. Bukti PostgreSQL dan GitHub Actions menyusul lewat addendum.

### Git preflight

- HEAD awal `010ac93ffe9f83fa260d1d9ea84a88fa77174519`, sama dengan `origin/main`.
- Sebagian besar kode step ini sudah ada di working tree dari sesi kerja sebelumnya dan belum di-commit. Sesi ini memeriksanya, menjalankan review, memperbaiki temuan, melengkapi dokumentasi, dan menjalankan gate.

### Scope selesai

- **Domain murni** `app/Modules/Scoring/Domain`: kalkulator per KPI dan per scorecard, satu kalkulator untuk tiap strategi allowlist Step 06, agregasi aktual, aturan pembulatan, rating band, dan policy scorecard. Tanpa query, waktu sistem, atau angka bawaan.
- **Aritmetika eksak** dengan `brick/math`; float tidak dipakai. Urutan pembulatan: skor KPI → kontribusi → total → skor akhir → rating.
- **Policy scorecard berversi dan bertanda tangan** di `config/scoring.php` (data terlambat, bobot KPI N/A, pembulatan kontribusi dan akhir, rating band). Daftar policy saat ini kosong.
- **Snapshot immutable** `scorecard_calculations` dan `scorecard_calculation_lines`: input lengkap, versi definisi dan strategi, versi mesin, policy dan hash-nya, nilai antara, hasil, waktu, dan alasan.
- **Recalculation idempotent** lewat fingerprint input; perubahan menghasilkan snapshot baru bernomor urut, tidak pernah menimpa.
- **UI:** daftar periode, daftar rekonsiliasi, detail scorecard per KPI dengan entri sumber dan bukti, serta ekspor CSV.
- **Akses:** `scorecard.view`, `individual_scorecard.view`, `scorecard.calculate`.
- **Dokumentasi:** `docs/architecture/SCORING.md` dan `docs/product/SCORING_GOLDEN_EXAMPLES.md` baru; `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `KPI_GOVERNANCE.md`, dan `DEPENDENCY_POLICY.md` diperbarui.

### Golden example (sintetis, belum ditandatangani)

Aturan contoh fiktif: pembulatan KPI half up 2 desimal; kontribusi half up 4 desimal; skor akhir half up 2 desimal; band `r1` < 60, `r2` [60, 80), `r3` [80, 100), `r4` ≥ 100.

| Contoh | Hitungan manual | Hasil mesin |
|---|---|---|
| GE-01 tiga KPI | Quality: rata-rata 98.2666… ÷ 98 × 100 → 100.27 × 50% = 50.1350. Reject: 12 ÷ 11 × 100 → 109.09 × 30% = 32.7270. Discipline: Ya → 100 × 20% = 20.0000. Total 102.8620 | Skor akhir 102.86, rating `r4` |
| GE-02 satu KPI N/A | Bobot dibagi proporsional: 90.00 × 60 ÷ 60 = 90.00. Pembagi tetap: 90.00 × 60 ÷ 100 = 54.00 | 90.00 `r3` atau 54.00 `r1`, sesuai aturan |
| GE-03 missing ditahan | KPI tanpa entri dengan aturan "tahan" | Scorecard belum lengkap, tanpa skor dan rating |
| GE-04 batas rating | 79.995 → KPI 79.9950 → skor akhir 80.00 | Rating `r3`, bukan `r2` |

Perbedaan dari hitungan manual: tidak ada.

### Test matrix

| Kelompok | Cakupan |
|---|---|
| Strategi | Rasio, rasio terbalik, rentang, binary, milestone; target nol dan negatif, aktual nol, parameter tidak dapat dipakai |
| Per KPI | Missing mengikuti aturan KPI, N/A bukan nol, nol adalah nilai, entri belum terverifikasi menahan, data terlambat sesuai policy, agregasi, rentang valid, target belum final |
| Scorecard | Rekonsiliasi, aturan bobot N/A, bobot bukan 100, urutan pembulatan, batas rating, fingerprint, determinisme |
| Properti (acak berbenih) | Skor dalam batas, monotonik, rekonsiliasi, tidak bergantung urutan entri, missing tidak pernah nol tanpa aturan |
| Snapshot dan service | Immutable di database, idempotent, alasan wajib, periode final ditolak, policy yang sudah dipakai tidak boleh berubah, periode lama direproduksi setelah versi baru |
| Halaman | Akses per role, aksi palsu, blokir tanpa policy, drill-down bukti, ekspor sesuai scope |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4.25) | 743 test: 740 passed, 3 skipped, 12.693 assertions |
| 2 | Test terhadap PostgreSQL lokal | **Tidak dijalankan**: Docker Desktop tidak aktif saat validasi. Bukti PostgreSQL menunggu GitHub Actions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Test baru Step 09: 206 (dari 537 menjadi 743).

### Dependency

`brick/math` dicantumkan eksplisit di `composer.json`. Paket ini sudah terpasang sebagai dependency Laravel; alasannya tercatat di `DEPENDENCY_POLICY.md`.

### Code review

Satu review independen. Tidak ada temuan Critical. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | KPI tanpa target final dan tanpa entri mengikuti aturan missing, sehingga bisa menjadi nol atau N/A padahal penyebabnya target belum ada | Berstatus `target_missing` dan menahan scorecard |
| Medium | Entri yang semuanya dibuang sebagai terlambat tampak seperti "tidak ada data" | Catatan baris menyebut `…_late_excluded` |
| Medium | Aksi halaman detail scorecard tidak memeriksa ulang akses | Setiap aksi memeriksa ulang |
| Medium | Dua perhitungan bersamaan dapat berakhir dengan error mentah | Dilaporkan sebagai `scorecard_concurrent_run` |

Dicatat tetapi tidak dikerjakan: input seluruh periode dimuat ulang untuk satu subjek, ekspor belum di-batch, constraint status hanya ada di PostgreSQL, nilai sangat besar dapat melebihi `numeric(18,4)`, dan escape `LIKE` tanpa klausa `ESCAPE`.

### Keputusan formula yang belum disetujui

- **DEC-004 OPEN:** rating band, aturan data terlambat, aturan bobot KPI N/A, pembulatan kontribusi dan skor akhir, toleransi, dan penanganan outlier. Tanpa ini tidak ada skor resmi.
- **DEC-003 PROPOSED:** bobot scorecard. Bobot yang tidak berjumlah 100 untuk sebuah subjek menahan scorecard; tidak ada normalisasi.
- **DEC-006 OPEN:** definisi KPI resmi dan golden example bertanda tangan.
- Apakah KPI wajib boleh menjadi N/A belum diputuskan; flag wajib dicatat tetapi belum memengaruhi hasil.
- Aturan roll-up pegawai → team → department → company belum ada, sehingga tidak diimplementasikan. Scorecard unit dihitung dari template unit itu sendiri.
- `ratio_of_sums` belum didukung karena aktual belum mencatat pembilang dan penyebut.

### Gate berikutnya

- Commit dan push Step 09, tunggu GitHub Actions (termasuk PostgreSQL), lalu catat addendum.
- Step 10 (approval, kalibrasi, lock, koreksi) membutuhkan DEC-002 dan DEC-009; tanpa keduanya hanya mekanismenya yang dapat dibangun.

### Addendum 2026-10-13 — bukti GitHub Actions Step 09

**Status Step 09 diperbarui:** PASS untuk scope implementasi (mesin, snapshot, halaman, test). **Engine bisnis tetap BLOCKED** sampai DEC-004 disetujui dan sebuah policy bertanda tangan ditambahkan.

- **Commit tervalidasi:** `5c5bb48aa03779a40a4da42f5d1f0f7e7006f3aa` pada `origin/main`.
- **CI:** GitHub Actions run [`38364086796`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38364086796) berakhir dengan conclusion `success`; kelima job lulus, termasuk dua job yang menjalankan suite pada PostgreSQL 18 + Redis. Ini menutup gate PostgreSQL yang tidak dapat dijalankan secara lokal.

DEC-003, DEC-004, dan DEC-006 tetap terbuka. Belum ada skor resmi, rating resmi, atau golden example bertanda tangan. Gate berikutnya adalah Step 10.

## 2026-10-13 — Step 10 Approval, Kalibrasi, Period Lock, dan Koreksi

**Status:** PARTIAL — mekanisme workflow, halaman, dan test selesai dan lulus secara lokal (SQLite). **Pemakaian bisnis BLOCKED:** DEC-004 (policy scorecard) masih OPEN sehingga tidak ada scorecard resmi yang dapat masuk review, dan DEC-002 (rantai approver, approver pengganti, kewenangan koreksi) serta DEC-009 (SLA) masih OPEN. Bukti PostgreSQL dan GitHub Actions menyusul lewat addendum.

### Git preflight

- HEAD awal `7436248` sama dengan `origin/main`; working tree bersih; fast-forward tidak diperlukan.

### Scope selesai

- **Siklus periode.** `PeriodService::advance()` untuk IN_PROGRESS → REVIEW → FINALIZED → LOCKED, hanya maju, dengan precheck yang dijalankan ulang di dalam transaksi. Precheck baru: aktual terverifikasi semua (`ActualReadiness`), perhitungan lengkap semua (`ScoringReadiness`), approval final semua, tidak ada koreksi menunggu, tidak ada event workflow gagal di outbox (`WorkflowLifecycle`). Event `PeriodLocked`.
- **Modul `Workflow`.** State review scorecard (in_review, reviewed, calibrated, final_approved, returned) dengan putaran; riwayat keputusan dan kalibrasi append-only; trigger PostgreSQL dan SQLite untuk transisi, identitas, dan immutability.
- **Kewenangan per tahap** (`ApprovalAuthority`): role tahap + scope unit + approver snapshot atau delegasi aktif dari approver snapshot; bukan pegawai yang dinilai; satu orang satu tahap per putaran, termasuk lewat delegasi.
- **Inbox dan persetujuan massal** (review dan approval final, maksimal 200, hasil per scorecard), pengembalian beralasan, pengajuan ulang oleh HR sebagai putaran baru (juga untuk scorecard yang dihitung ulang setelah masuk review).
- **Kalibrasi** yang menyimpan skor hitung dan skor kalibrasi berdampingan; rating dari policy yang tersimpan pada perhitungan; snapshot perhitungan tidak disentuh.
- **Bypass tahap review** per team: hanya bila snapshot menunjukkan gap dan ada catatan bypass bertanda sign-off yang berlaku; dicatat sebagai keputusan `bypassed`.
- **Koreksi aktual terverifikasi** pada periode REVIEW/FINALIZED/LOCKED: permintaan dan persetujuan oleh dua orang berbeda; versi aktual baru, hitung ulang, dan pengalihan review dalam satu transaksi; versi dan perhitungan lama tetap ada.
- **Halaman:** inbox approval, board periode dengan checklist lock, halaman scorecard (rantai approver, perbandingan kalibrasi, aksi, riwayat), antrean koreksi, pencatatan bypass. Menu Approval kini hanya tampil untuk akun yang berperan.
- **Outbox:** `CalibrationCompleted`, `FinalApprovalCompleted`, `PeriodLocked`, `CorrectionRequested`, `CorrectionApproved`, `FinalScoreCorrected`.
- **Capability baru:** `approval_route.manage` (HR), `correction.request` (HR), `correction.approve` (Management).
- **Dokumentasi:** `docs/architecture/WORKFLOW.md`; pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `SCORING.md`, `ACTUAL_EVIDENCE.md`, `PERIOD_TARGET.md`.

### Yang sengaja tidak dibangun

- **Reopen periode.** Tidak ada aturan yang disetujui tentang siapa dan dalam kondisi apa. Periode tidak pernah mundur; perubahan setelah REVIEW hanya lewat koreksi aktual.
- **SLA, reminder, eskalasi** (DEC-009; Step 11).
- **Koreksi target, snapshot assignment, dan unggahan bukti koreksi.** Referensi bukti koreksi berupa teks.
- **Koreksi atas scorecard final yang skornya disesuaikan pada kalibrasi** ditolak seluruhnya sampai ada aturan tentang nasib skor kalibrasi.

### Test matrix

| Area | Cakupan |
|---|---|
| Happy path | Review → kalibrasi → approval final → Finalized → Locked, dengan role, grant, audit, dan outbox |
| Reviewer kosong | Tahap menunggu; bypass disetujui; bypass kedaluwarsa; bypass pada team yang punya reviewer; team only; tidak surut; tidak tumpang tindih |
| Reject/resubmit | Alasan wajib, putaran baru, riwayat utuh, hanya HR; pengajuan ulang setelah hitung ulang |
| Delegasi | Aktif, kedaluwarsa, "atas nama", dan satu orang satu tahap lewat delegate |
| Self-approval | Pegawai yang dinilai dengan role reviewer dan Management |
| Klik ganda / bersamaan | Versi basi ditolak; tepat satu keputusan dan satu kalibrasi |
| Kalibrasi | Skor hitung tetap, alasan wajib, skala desimal, rating dari policy tersimpan |
| Koreksi | Review dan Locked, aturan dua orang, role, validasi nilai, tolak/batal, basi, membuat scorecard tidak lengkap, scorecard terkalibrasi, antrean tanpa hasil sendiri |
| Lock gagal | Scorecard belum final, koreksi menunggu, event outbox gagal, perhitungan lebih baru |
| Transisi tidak sah | Role salah, di luar periode Review, periode mundur, SQL langsung pada review/riwayat/koreksi |
| Halaman | Akses per role, aksi palsu, halaman basi, persetujuan massal, board sesuai scope, bypass |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 799 test: 796 passed, 3 skipped, 13.114 assertions |
| 2 | Test terhadap PostgreSQL lokal | **Tidak dijalankan**: Docker Desktop tidak aktif. Trigger dan CHECK constraint PostgreSQL pada migrasi baru belum pernah dieksekusi; buktinya menunggu GitHub Actions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus, tidak ada migrasi pending |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Test baru Step 10: 56 (dari 743 menjadi 799). Tidak ada dependency baru.

### Code review

Dua review independen (PHP dan keamanan). Tidak ada temuan Critical. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Koreksi pada periode final membuang skor kalibrasi secara diam-diam | Koreksi ditolak bila scorecard final itu dikalibrasi dengan penyesuaian; event `FinalScoreCorrected` untuk perubahan skor final lainnya |
| High | Antrean koreksi dibatasi 200 baris sebelum filter kewenangan | Scope unit diterapkan di query; permintaan menunggu diurutkan lebih dulu |
| Medium | Satu orang dapat memutuskan dua tahap lewat delegate | Approver yang diwakili ikut dihitung sudah memutuskan |
| Medium | Aksi koreksi tidak mencatat role dan grant; riwayat menyimpan `unknown` | Service mengambil grant dari `AccessControl` dan mencatatnya di audit dan riwayat |
| Medium | Antrean koreksi dapat menampilkan permintaan tentang hasil akun itu sendiri | Dikecualikan di query dan saat membuka |
| Medium | Bypass dapat dicatat untuk unit bukan team lewat service | Ditegakkan di service |
| Low | Versi basi baru ditolak setelah seluruh pekerjaan koreksi; perbandingan desimal tidak konsisten; kode mati | Diperbaiki |

Ditemukan sendiri saat validasi: scorecard yang dihitung ulang setelah final disetujui tidak dapat dikembalikan, sehingga periode tertahan. HR kini dapat mengajukannya ulang dari state mana pun.

Dicatat tetapi tidak dikerjakan: inbox dan board memfilter di aplikasi tanpa pagination dan memanggil pencarian bypass per baris; koreksi menghitung ulang seluruh periode; pemeriksaan state berjalan sebelum otorisasi di service (tidak terjangkau dari halaman); checklist periode menampilkan jumlah penahan seluruh periode; trigger tidak mewajibkan putaran naik saat kembali ke in_review lewat SQL langsung; CHECK constraint hanya di PostgreSQL dan belum punya test khusus; aturan klik ganda diuji berurutan, belum dengan dua koneksi PostgreSQL; beberapa method menerima lebih dari lima parameter.

### Keputusan yang belum disetujui (tidak diasumsikan)

- **DEC-004 OPEN.** Tanpa policy scorecard resmi tidak ada perhitungan lengkap, sehingga tidak ada periode yang dapat masuk REVIEW di konfigurasi resmi. Seluruh test memakai policy TEST-ONLY.
- **DEC-002 OPEN.** Siapa approver tiap team, approver pengganti, dan jalur saat posisi kosong. Mapping tahap → role mengikuti "Approval flow acuan" ROLE_APPROVAL_MATRIX; orangnya adalah konfigurasi role.
- **Default sementara yang perlu dikonfirmasi pada DEC-002:**
  - koreksi diminta HR Performance dan disetujui Management (matrix menandainya "C" untuk Management, Manager, dan HR tanpa kondisi yang diputuskan);
  - approver tahap dapat membaca skor scorecard individu yang ia putuskan, walaupun melihat scorecard individu orang lain belum diaktifkan untuk manager;
  - bypass dicatat oleh satu akun HR dengan referensi sign-off berupa teks, tanpa orang kedua;
  - approver baru yang tidak ada di snapshot periode hanya dapat bertindak lewat delegasi approver snapshot; bila approver snapshot tidak aktif lagi, tahap tertahan.
- **DEC-009 OPEN.** SLA review, kalibrasi, approval final, dan koreksi.
- Batas penyesuaian kalibrasi, kalibrasi massal, aturan reopen periode, dan nasib skor kalibrasi setelah koreksi final belum ada.

### Gate berikutnya

- Commit dan push Step 10, tunggu GitHub Actions (termasuk PostgreSQL), lalu catat addendum.
- Step 11 (notifikasi, reminder, eskalasi) membutuhkan DEC-009.

### Addendum 2026-10-13 — bukti GitHub Actions Step 10

**Status Step 10 diperbarui:** PASS untuk scope implementasi (mekanisme workflow, halaman, test). **Pemakaian bisnis tetap BLOCKED** sampai DEC-004 disetujui (policy scorecard resmi) dan DEC-002 serta DEC-009 diputuskan.

- **Commit tervalidasi:** `4a439d69f3f83eaf3a60a6ba8912d41b88982755` pada `origin/main`.
- **CI:** GitHub Actions run [`38370368848`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38370368848) berakhir dengan conclusion `success`; kelima job lulus, termasuk dua job yang menjalankan suite pada PostgreSQL 18 + Redis. Ini menutup gate PostgreSQL yang tidak dapat dijalankan secara lokal: migrasi workflow beserta trigger dan CHECK constraint PostgreSQL-nya berhasil dibuat, dan seluruh test workflow lulus di atasnya.

Yang tetap belum terbukti: CHECK constraint khusus PostgreSQL belum memiliki test yang sengaja melanggarnya, dan aturan klik ganda belum diuji dengan dua koneksi bersamaan. Default sementara pada entri Step 10 masih menunggu konfirmasi DEC-002. Gate berikutnya adalah Step 11.

## 2026-10-13 — Step 11 Dashboard, Notifikasi, dan Corrective Action

**Status:** PARTIAL — dashboard per peran, notifikasi, pengingat/eskalasi, daily team review, corrective action, dan coaching notes selesai dan lulus secara lokal (SQLite). **Yang BLOCKED oleh keputusan bisnis:** tidak ada pengingat atau eskalasi yang dikirim (DEC-009 OPEN, daftar aturan kosong), channel email nonaktif dan hanya diuji dengan mailer palsu (DEC-014 OPEN), KPI merah tidak ditampilkan (ambang belum disetujui, DEC-004), dan dashboard skor resmi tetap kosong tanpa policy scorecard (DEC-004). Target performa belum pernah disetujui, sehingga kriteria "memenuhi target performa pada data representatif" **belum dapat dinyatakan lulus**. Bukti PostgreSQL dan GitHub Actions menyusul lewat addendum.

### Git preflight

- HEAD awal `c55c2a6` sama dengan `origin/main`; working tree bersih.

### Scope selesai

- **Dashboard per peran** (`dashboard`): Management, HR Performance, Department Head/Team Leader, Karyawan, Auditor (baca saja), System Admin (kesehatan teknis tanpa nilai KPI). Angka adalah hitungan record tersimpan dalam scope akun dan masing-masing menautkan ke halaman sumbernya. Tidak ada angka contoh dan tidak ada cache.
- **Halaman team** (`team.index`): anggota, status aktual per anggota, target versus aktual team dari perhitungan terakhir, coaching notes, histori penempatan, tautan delegasi, dan tautan HRIS untuk cuti bila dikonfigurasi.
- **Daily team review dan corrective action** (`team-review.*`): masalah, penyebab, konteks, KPI terdampak; tindakan dengan PIC, tenggat, prioritas, bukti, verifikator; status OPEN, IN_PROGRESS, WAITING, VERIFIED, CLOSED; overdue; riwayat append-only; audit; trigger PostgreSQL dan SQLite.
- **Coaching notes** dengan akses paling sempit (penulis dan Team Leader team itu), append-only, tidak dapat didelegasikan.
- **Notifikasi**: di aplikasi selalu; email lewat job antrean bila diaktifkan dan tidak dimatikan pengguna; preferensi per kategori; catatan percobaan pengiriman tanpa isi pesan; deep link yang hanya milik penerima dan menyerahkan otorisasi ke halaman tujuan.
- **Consumer outbox** `workflow-notifications` dan event baru `ApprovalStageReady`, `ScorecardReturned`, `CorrectionRejected`, `ActualRejected`, `CorrectiveActionAssigned`, `CorrectiveActionCompleted`.
- **Pengingat dan eskalasi** idempotent (`notifications:send-reminders`, tiap jam) dari aturan bertanda tangan pada konfigurasi; daftar aturan kosong.
- **Capability baru:** `team_review.view`, `team_review.manage`, `coaching_note.manage`.
- **Perbaikan komponen bersama:** empty state `ui.table-shell` tidak pernah muncul di halaman Livewire; kini memakai `hasActualContent()`. Halaman approval dan corrective action membaca `lock_version` dari database saat dibuka.
- **Dokumentasi:** `docs/architecture/DASHBOARD_NOTIFICATION_TEAM_REVIEW.md`; pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `WORKFLOW.md`, `.env.example`.

### Yang sengaja tidak dibangun

- Modul cuti (prompt: hanya tautan/integrasi HRIS bila HRIS menjadi sumber data; belum ada keputusan).
- Ringkasan periodik, perbandingan antarshift (DEC-015), master reason code dan referensi operasional (DEC-016), roll-up atau rata-rata skor lintas unit.
- Unggahan file bukti corrective action (bukti berupa referensi teks).
- "Permintaan revisi data" sebagai entitas baru: memakai penolakan aktual beralasan (Step 08) dan koreksi (Step 10).

### Test matrix

| Area | Cakupan |
|---|---|
| Dashboard per role | Bagian yang tampil dan yang tidak untuk 7 role; scope lintas departemen; Management tanpa baris pegawai; pemilihan periode di luar scope; ambang KPI merah (belum ada, ada, tidak valid) |
| Query regression | Anggaran query dashboard per role; tidak bertambah dengan 30 notifikasi |
| Coaching notes | Penulis, Team Leader kedua, subjek, Department Head, HR, Management, Auditor, team lain; tidak tentang diri sendiri; audit tanpa isi; append-only |
| Revisi/delegasi | Tautan halaman team; delegate tidak membuka celah (Step 10) |
| Cross-scope | Team lain tidak dapat melihat, mencatat, menugaskan, atau memverifikasi; PIC dari luar team ditolak; aksi palsu pada halaman |
| Notifikasi | Deduplikasi, akun nonaktif, preferensi email, retry dengan penghitung, tidak terkirim dua kali, gagal setelah retry habis, antre ulang, event workflow dan corrective action, tanpa skor di isi pesan, deep link milik penerima |
| Scheduler | Tanpa aturan tidak mengirim; aturan tidak valid menggagalkan perintah; pengingat tenggat periode; menjalankan ulang tidak menggandakan |
| SLA corrective action | PIC dan tenggat wajib, overdue, pengingat sebelum tenggat, eskalasi setelah tenggat, ubah tenggat beralasan dan bukan oleh PIC, bukti wajib bila dikonfigurasi, siklus status lengkap, trigger database |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 851 test: 848 passed, 3 skipped, 13.528 assertions |
| 2 | Test terhadap PostgreSQL lokal | **Tidak dijalankan**: Docker Desktop tidak aktif. Trigger, CHECK constraint, dan agregat SQL baru belum pernah dieksekusi di PostgreSQL; buktinya menunggu GitHub Actions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus, tidak ada migrasi pending |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Test baru Step 11: 52 (dari 799 menjadi 851). Tidak ada dependency baru.

### Bukti query dan performa

Jumlah query dashboard pada data skenario dibatasi test: Management, HR, Department Head ≤ 110; Team Leader, Auditor ≤ 90; Karyawan ≤ 60; System Admin ≤ 40. Ini batas regresi, bukan hasil uji beban: tidak ada pengukuran waktu respons, tidak ada data representatif, dan tidak ada target yang disetujui (DEC-007, DEC-010). Pengukuran sebenarnya bagian Step 19.

### Channel notifikasi yang benar-benar diuji

- **Di aplikasi:** diuji end to end melalui outbox, consumer, penyimpanan, halaman, dan deep link.
- **Email:** diuji hanya dengan mailer palsu dan antrean palsu/sync (antre, kirim, gagal, retry, tidak ganda, antre ulang). **Belum pernah dikirim lewat SMTP atau layanan email nyata**; channel nonaktif secara default.

### Aksesibilitas

Halaman baru memakai komponen UI yang sama dengan step sebelumnya (label, caption tabel, header baris, status dengan teks dan simbol, empty state, teks tautan pembeda, layout satu kolom pada layar sempit). **Tidak ada audit dengan alat bantu** (axe, pembaca layar, uji keyboard manual) yang dijalankan pada step ini.

### Code review

Dua review independen (PHP dan keamanan). Tidak ada temuan Critical atau High. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| Medium | Halaman team menampilkan alasan penempatan yang dicatat HR | Kolom alasan dihapus |
| Medium | PIC yang juga pengelola dapat memundurkan tenggatnya sendiri | Ditolak; harus pengelola lain |
| Medium | Job email yang diantar ulang bersamaan dapat mengirim dua kali | Job tidak berjalan tumpang tindih per pengiriman |
| Medium | Pengiriman email tertinggal bila dispatch hilang | Diantrekan ulang oleh perintah terjadwal |
| Medium | Notifikasi tugas approval tertahan untuk scorecard kedua pada hari yang sama | Kunci per scorecard, tahap, dan putaran |
| Medium | Penyelesaian dapat dilaporkan dua kali | Ditolak sampai diverifikasi atau dikembalikan |
| Low | Akun nonaktif menerima notifikasi; kode KPI tanpa batas; tautan pengingat karyawan ke halaman yang tidak dapat dibuka; event penolakan koreksi tanpa periode; query hitung ganda di dashboard | Diperbaiki |

Dicatat tetapi tidak dikerjakan: pengingat hanya cocok pada tanggal persis (tanpa kirim susulan bila scheduler mati sehari); perintah pengingat memuat data tanpa batching dan mencari penerima per tindakan; CHECK constraint hanya di PostgreSQL dan belum punya test khusus; belum ada uji balapan unik dengan dua koneksi; isi notifikasi memuat kode KPI dan kode subjek, yang ikut terkirim lewat email bila channel diaktifkan; pesan exception mentah dapat masuk `failed_jobs` atau log; `latestScores` memuat seluruh riwayat perhitungan; delegate belum menerima notifikasi tugas; PIC tidak diberi tahu saat penyelesaian diverifikasi atau dikembalikan.

### Keputusan yang belum disetujui (tidak diasumsikan)

- **DEC-009 OPEN.** Aturan pengingat, eskalasi, dan SLA corrective action. Mekanisme diuji dengan aturan TEST-ONLY.
- **DEC-014 OPEN.** Layanan dan pengirim email.
- **DEC-004 OPEN.** Ambang KPI merah dan policy scorecard resmi.
- **DEC-011 PROPOSED.** Akses coaching notes. Dibangun lebih sempit dari usulan: subjek dan HR tidak dapat membaca. Perlu keputusan sebelum dipakai.
- **DEC-015, DEC-016 OPEN.** Shift dan master data operasional.
- **Default sementara yang perlu dikonfirmasi:** Department Head dan Team Leader mengelola review team; Team Leader saja yang menulis coaching notes; prioritas tiga tingkat tanpa konsekuensi; penerima notifikasi tiap kejadian (lihat dokumen arsitektur); karyawan melihat skor hasil perhitungan sebelum kalibrasi segera setelah scorecard lengkap (konsisten dengan halaman scorecard Step 09).

### Gate berikutnya

- Commit dan push Step 11, tunggu GitHub Actions (termasuk PostgreSQL), lalu catat addendum.
- Step 12 (laporan dan ekspor).

### Addendum 2026-10-13 — bukti GitHub Actions Step 11

**Status Step 11 diperbarui:** PASS untuk scope implementasi (dashboard, notifikasi, pengingat, team review, test). **Tetap BLOCKED oleh keputusan bisnis:** pengingat dan eskalasi (DEC-009), channel email (DEC-014), KPI merah dan skor resmi (DEC-004), akses coaching notes (DEC-011). Kriteria performa pada data representatif dan audit aksesibilitas dengan alat bantu **belum dibuktikan**.

- **Commit tervalidasi:** `fa71c132ddf5e50ae54d0a3178b001aae024e491` pada `origin/main`.
- **CI:** GitHub Actions run [`38382781036`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38382781036) berakhir dengan conclusion `success`; kelima job lulus, termasuk dua job yang menjalankan suite pada PostgreSQL 18 + Redis. Ini menutup gate PostgreSQL yang tidak dapat dijalankan secara lokal: migrasi Step 11 beserta trigger dan CHECK constraint-nya berhasil dibuat dan seluruh test lulus di atasnya.

Gate berikutnya adalah Step 12.

## 2026-10-14 — Step 12 Laporan dan Ekspor

**Status:** PARTIAL — katalog 11 laporan, pratinjau, ekspor Excel/PDF asynchronous, unduhan bertanda tangan, retensi file, dan test selesai serta lulus secara lokal (SQLite). Belum dapat dinyatakan lulus: **performa pada dataset representatif** (ukuran pilot DEC-007 dan target performa belum ada; yang diukur hanya penulis ekspor dengan baris sintetis), **object storage nyata** (hanya disk palsu pada test), dan bukti PostgreSQL/GitHub Actions yang menyusul lewat addendum. Laporan skor resmi tetap kosong selama DEC-004 terbuka.

### Git preflight

- HEAD awal `77d586d` sama dengan `origin/main`; working tree bersih.

### Katalog laporan

| Kunci | Laporan | Format |
|---|---|---|
| `scorecards` | Scorecard karyawan, team, department, perusahaan (skor hitung, skor berlaku setelah kalibrasi, status approval) | Excel, PDF |
| `kpi_lines` | Target versus aktual per KPI, skor periode sebelumnya, KPI merah (bila ambang disetujui) | Excel, PDF |
| `rating_distribution` | Distribusi rating per unit | Excel, PDF |
| `completeness` | Kelengkapan dan data yang belum masuk per target wajib | Excel, PDF |
| `approvals` | Status approval, tahap yang menunggu, approver, gap, umur tunggu | Excel, PDF |
| `approval_history` | Riwayat keputusan approval | Excel, PDF |
| `corrections` | Koreksi aktual dan keputusannya | Excel, PDF |
| `corrective_actions` | Corrective action dan yang melewati tenggat | Excel, PDF |
| `kpi_definitions` | Definisi dan versi KPI yang dikunci periode | Excel, PDF |
| `calculations` | Rekonsiliasi seluruh perhitungan tersimpan | Excel, PDF |
| `audit_log` | Laporan audit pada rentang tanggal (maks. 92 hari) | Excel, PDF |

PDF dibatasi 500 baris; laporan lebih besar diekspor sebagai Excel.

### Scope selesai

- Satu definisi laporan dipakai untuk pratinjau dan ekspor; setiap laporan membatasi query dengan scope capability datanya sendiri. Filter divalidasi bentuknya dan filter/status yang tidak ditawarkan laporan ditolak.
- Ekspor lewat job antrean: status dan progres baris, alasan gagal tetap yang aman, retry idempotent, permintaan identik yang masih berjalan dipakai ulang (indeks unik parsial), akses dievaluasi ulang saat job berjalan dan saat unduhan.
- Metadata tiap file: nama dan kode laporan, periode, filter, scope organisasi, waktu dan timezone, pembuat, template/versi per baris, klasifikasi, ID ekspor.
- File di disk private dengan nama dari server; unduhan lewat route bertanda tangan 5 menit yang terikat pemilik; audit untuk permintaan, penyelesaian, kegagalan, unduhan (dengan checksum), dan penghapusan.
- Housekeeping tiap jam: ekspor yang ditinggal worker mati ditandai gagal, file sementara dibersihkan, file melewati retensi dihapus.
- Pencegahan formula injection, path traversal, ekspor tanpa hak, akses objek langsung, dan kebocoran lintas scope.
- Capability baru `report.view` dan `audit_report.view` (tidak dapat didelegasikan). Menu Reports kini halaman nyata.
- Dependency baru `dompdf/dompdf` 3.1.6 (alasan di `DEPENDENCY_POLICY.md`).
- Dokumentasi: `docs/architecture/REPORTING.md`; pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `DEPENDENCY_POLICY.md`, `.env.example`.

### Test matrix

| Area | Cakupan |
|---|---|
| Rekonsiliasi | Scorecard report = perhitungan tersimpan = halaman scorecard; skor berlaku setelah kalibrasi; baris KPI = baris snapshot; distribusi rating = jumlah lengkap; perhitungan = seluruh snapshot; kelengkapan = ringkasan halaman aktual |
| Otorisasi | Katalog per role; karyawan dan System Admin tanpa laporan; manager tanpa baris pegawai; filter unit di luar scope dan unit tak dikenal ditolak sama; audit hanya HR/Auditor dan tidak dapat didelegasikan |
| Filter | Path traversal, SQL, kunci tak dikenal, tingkat, ULID, tanggal tidak valid/terbalik, filter dan status yang tidak ditawarkan, array |
| Ekspor | Isi file = baris laporan; metadata lengkap; scope manager; PDF; PDF terlalu besar; format tak dikenal |
| Injection | Unit test sanitizer (=, +, -, @, tab, CR, spasi awal, karakter kontrol, desimal negatif) dan end to end lewat alasan koreksi |
| Akses file | Bukan pemilik, tanpa tanda tangan, viewer dipalsukan, tamu, akses dicabut sebelum unduhan dan sebelum worker berjalan |
| Kedaluwarsa | Tautan kedaluwarsa (403), ekspor kedaluwarsa (410), purge menghapus file |
| Retry/idempotensi | Permintaan ganda, job ganda, retry yang tidak perlu, batas ekspor berjalan, storage gagal lalu pulih, worker mati (running basi), retry saat permintaan identik sedang berjalan, disk publik ditolak |
| Performa | Anggaran query lima laporan utama; uji volume penulis Excel |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 886 test: 883 passed, 3 skipped, 13.850 assertions |
| 2 | Test terhadap PostgreSQL lokal | **Tidak dijalankan**: Docker Desktop tidak aktif. SQL agregat laporan, indeks unik parsial, dan CHECK constraint baru belum dieksekusi di PostgreSQL; buktinya menunggu GitHub Actions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus, tidak ada migrasi pending |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Test baru Step 12: 35 (dari 851 menjadi 886).

### Ukuran dataset, waktu, dan memori

- **Uji volume penulis Excel (sintetis):** 50.000 baris × 18 kolom, sekitar 9 detik pada mesin pengembangan, tambahan memori puncak di bawah 1 MB, file 2,9 MB. Ini membuktikan streaming, bukan performa laporan pada data nyata.
- **Query laporan:** lima laporan utama ≤ 25 query pada data skenario (4 scorecard). Data yang melampaui satu chunk belum diuji pada laporan.
- **PDF:** pemakaian memori belum diukur; dibatasi 500 baris.
- **Dataset representatif:** belum ada (DEC-007), sehingga kriteria performa belum dapat dinilai.

### Retensi yang berlaku

File hasil ekspor disimpan 72 jam lalu dihapus otomatis; tautan unduhan berlaku 5 menit. Keduanya batas teknis yang dapat diatur lewat env, **bukan** kebijakan retensi data (DEC-010 OPEN). Record sumber tidak terpengaruh.

### Code review

Dua review independen (PHP dan keamanan). Tidak ada temuan Critical. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Ekspor dapat tertahan `running` selamanya bila worker mati atau error terjadi sebelum blok penanganan | Seluruh proses setelah klaim berada dalam penanganan error; housekeeping menggagalkan ekspor `running` yang basi dan membersihkan file sementara |
| High | Timeout job 900 detik melebihi `retry_after` antrean 90 detik, sehingga job yang masih berjalan dapat diberikan ke worker lain dan ditandai gagal | Timeout job 75 detik; penyelesaian hanya oleh run yang masih memegang ekspor; batas baris diturunkan ke 100.000 |
| Medium | `audit_report.view` ikut dapat didelegasikan | Dikecualikan dari delegasi |
| Medium | Retry saat permintaan identik berjalan berakhir dengan error 500; retry melewati batas ekspor berjalan | Mengembalikan ekspor yang berjalan; batas diperiksa |
| Medium | File yang terunggah tetapi gagal dicatat tertinggal; purge mengabaikan kegagalan hapus | File dihapus bila penyelesaian gagal; purge per ekspor dan hanya menandai bila file benar terhapus |
| Medium | Status di luar daftar laporan menghasilkan ekspor kosong tanpa error | Ditolak sebagai filter tidak valid |
| Medium | PDF disusun di memori | Batas PDF diturunkan ke 500 baris |
| Low | Disk publik dapat dipakai lewat env; UTF-8 tidak valid dan sel melebihi batas Excel; tipe nilai pada PDF; rentang audit lebih satu hari; stream tidak ditutup saat gagal | Diperbaiki |

Dicatat tetapi tidak dikerjakan: `corrections` (untuk Management) dan `completeness` (untuk pemegang `actual.view`) menampilkan baris per pegawai sesuai scope halaman yang sudah ada, bukan aturan scorecard individu — perlu dikonfirmasi; pratinjau tidak diaudit dan tidak di-throttle; job antrean yang gagal dapat menyimpan pesan exception di `failed_jobs`; "menunggu sejak" pada laporan approval memakai waktu perubahan terakhir review; batas ekspor berjalan dihitung tanpa lock.

### Keputusan yang belum disetujui (tidak diasumsikan)

- **DEC-004** skor resmi dan ambang KPI merah. **DEC-009** SLA approval (laporan memberi umur tunggu, bukan "overdue"). **DEC-010** retensi, klasifikasi data, dan kontrol ekspor (klasifikasi Internal/Terbatas adalah pembeda kerja). **DEC-007** ukuran pilot dan target performa. **DEC-015/016** shift dan mesin (tidak ada filter shift/mesin).

### Gate berikutnya

- Commit dan push Step 12, tunggu GitHub Actions (termasuk PostgreSQL), lalu catat addendum.
- Step 13 (modul Commercial dan Support).

### Addendum 2026-10-14 — bukti GitHub Actions Step 12

**Status Step 12 diperbarui:** PASS untuk scope implementasi (katalog laporan, pratinjau, ekspor asynchronous, unduhan, retensi teknis, test). **Belum dibuktikan:** performa pada dataset representatif (DEC-007), object storage nyata, dan kebijakan retensi data (DEC-010). Laporan skor resmi tetap kosong selama DEC-004 terbuka.

- **Commit tervalidasi:** `534330a0dba2e3b2d2a3a8b38a4c10ec8f84e7de` pada `origin/main`.
- **CI:** GitHub Actions run [`38502547296`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38502547296) berakhir dengan conclusion `success`; kelima job lulus, termasuk dua job yang menjalankan suite pada PostgreSQL 18 + Redis dan job image Docker dengan dependency baru `dompdf/dompdf`. Ini menutup gate PostgreSQL yang tidak dapat dijalankan secara lokal: SQL agregat laporan, indeks unik parsial, dan CHECK constraint ekspor berjalan di PostgreSQL.

Gate berikutnya adalah Step 13.

## 2026-10-15 — Step 13 Modul Commercial dan Support

**Status:** BLOCKED untuk sebagian besar kebutuhan per team, PARTIAL untuk yang dibangun. Prompt meminta fitur khusus hanya untuk use case yang **disetujui**, dan seluruh keputusan terkait masih OPEN: DEC-001 (Design A/B), DEC-005 (KPI rilis), DEC-006 (definisi KPI), DEC-008 (integrasi), DEC-010 (klasifikasi data), DEC-016 (system of record). Yang dibangun adalah satu register operasional generik untuk dua kebutuhan yang aturannya ditetapkan PRD sendiri. Tidak ada KPI Commercial/Support yang diaktifkan.

### Git preflight

- HEAD awal `411d43c` sama dengan `origin/main`; working tree bersih.

### Fitur per team

| Team | Hasil |
|---|---|
| Design A, Design B | **Register Revisi Design** dengan kategori penyebab PRD (kesalahan internal, perubahan customer, perubahan Sales, masalah produksi). Hanya kesalahan internal dibebankan ke team; laporan detail dan atribusi per team. A dan B dipisah per team tanpa workflow berbeda |
| R&D Costing | **Register Costing vs aktual produksi** per komponen (material, waktu mesin, tenaga kerja) dan versi asumsi, dengan referensi BOM/routing/work order; selisih dan selisih relatif eksak; versi tidak bercampur; estimasi tidak pernah menjadi aktual. Angka biaya terbatas (HR tidak membaca) |
| Sales | BLOCKED: dashboard Sales butuh CRM/ERP, klasifikasi margin/customer, dan KPI disetujui. Memakai KPI generik |
| Customer Service | BLOCKED: tracking request/handover/complaint hanya bila menjadi sumber KPI resmi (belum diputuskan). Memakai KPI generik |
| Admin PPIC | Workflow handover administratif BLOCKED (sumber dan aturan belum ada); board PPIC adalah Step 15. Memakai KPI generik |
| Purchasing, HR, GA | KPI generik (tidak ada register operasional tambahan yang disetujui). Data personal HR dan coaching notes tetap dilindungi aturan Step 05/11 |

### Scope selesai

- Modul `Operations`: satu tabel `operational_records`, jenis catatan bertipe (`RecordType`) dengan validasi isian, referensi sumber, tautan opsional ke definisi KPI, pembatalan beralasan (tanpa edit/hapus, dijaga trigger), audit tanpa isi angka.
- Akses: `operational_record.manage` (Team Leader, Department Head) atas team; `operational_record.view` lewat role yang diterima jenis catatan.
- Batas adapter sumber (`OperationalRecordSource`): impor idempotent lewat aturan yang sama; baris untuk team di luar scope ditolak. Belum ada konektor nyata.
- Halaman `operations.index` (layar per jenis, isian dinamis, pembatalan) dan tiga laporan baru di katalog Step 12 (`design_revisions`, `design_revision_attribution`, `costing_comparisons`) yang ikut ekspor Excel/PDF.
- Dokumentasi: `docs/product/COMMERCIAL_SUPPORT_SOURCES.md` (fitur, sumber, pemilik data usulan, yang ditunda); pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `REPORTING.md`.

### Test matrix

| Area | Cakupan |
|---|---|
| Atribusi revisi | Hanya kesalahan internal dibebankan; jumlah atribusi = baris detail; Design A/B terpisah; rentang tanggal wajib |
| Auditabilitas | Kategori diperbaiki lewat batal + catat ulang; audit urut; edit/hapus/ubah setelah batal ditolak database |
| Validasi | Kategori/komponen tak dikenal, revisi nol, isian wajib, isian tak dikenal, desimal negatif/koma, referensi berspasi, karakter kontrol dan tak terlihat, tanggal masa depan, KPI tak dikenal, jenis tak dikenal |
| Costing | Aritmetika eksak, estimasi nol, versi tidak bercampur, duplikat ditolak, tidak menulis aktual KPI |
| Akses sensitif | HR tidak membaca biaya; team dan departemen lain tidak melihat; audit tanpa angka dan teks; Management/HR/Auditor/System Admin/Karyawan tidak mencatat |
| Lintas team | Team leader lain tidak melihat, mencatat, atau membatalkan; team tidak saling menghalangi untuk referensi yang sama |
| Adapter | Impor idempotent, baris tidak valid dan di luar scope ditolak tanpa menggagalkan impor, salinan manual dan impor dianggap catatan yang sama |
| Halaman | Akses per role, jenis catatan per role, catat, batal |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 903 test: 900 passed, 3 skipped, 13.953 assertions |
| 2 | Test terhadap PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`, Docker Compose) | Suite penuh: 899 passed, 2 skipped, **2 failed**. Kegagalan terjadi karena suite SQLite di host berjalan bersamaan dan berbagi folder `storage/framework/testing` (bind mount), sehingga hitungan file pada disk palsu tercampur (contoh: `EvidenceTest` mengharapkan 2 file, mendapati 3). Saat diulang sendiri di PostgreSQL, `EvidenceTest` lulus 19/19 dan gabungan ActualEvidence + Reporting + Operations lulus 156/156. Suite penuh PostgreSQL yang bersih menunggu GitHub Actions |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit --locked` | valid / tidak ada advisory |
| 6 | `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Jumlah test naik dari 886 menjadi 903; file test Step 13 berisi 29 kasus termasuk dataset. Tidak ada dependency baru.

### Code review

Satu review independen (keamanan dan PHP). Tidak ada temuan Critical. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Kunci deduplikasi tidak memuat team, sehingga satu team dapat menghalangi catatan team lain dan impor diam-diam melewatinya | Team menjadi bagian kunci unik |
| Medium | Salinan manual dan hasil impor dari catatan yang sama terhitung dua kali | Sistem sumber dikeluarkan dari kunci unik |
| Medium | Isian wajib dapat lolos berisi karakter kontrol yang lalu dibuang | Pembersihan dilakukan sebelum pemeriksaan wajib; karakter tak terlihat dibuang dari isian satu baris |
| Medium | Laporan costing memuat relasi per baris | Iterasi per chunk dengan eager loading |
| Low | Pembatalan tanpa pemeriksaan pelaku; halaman membaca versi terbaru saat membatalkan; referensi sumber dipotong diam-diam; metode publik halaman | Diperbaiki |

Dicatat untuk keputusan: siapa pun pengelola team dapat mencatat jenis apa pun untuk team-nya dan team Design mengkategorikan revisinya sendiri; hak baca dapat didelegasikan; catatan boleh bertanggal mundur; audit memuat referensi sumber (tanpa angka); tautan KPI tidak dicek terhadap unit.

### Deferred dan blocker

- Dashboard Sales, tracking Customer Service, workflow handover Admin PPIC: menunggu DEC-005, DEC-006, DEC-008, DEC-010, DEC-016.
- Konektor sumber (CRM, ERP, design tracker): Step 17 setelah DEC-008.
- Pemakaian catatan sebagai nilai aktual KPI: menunggu definisi KPI (DEC-006).

### Gate berikutnya

- Commit dan push Step 13, tunggu GitHub Actions, lalu catat addendum.
- Step 14 (modul Accounting AR dan Tax).

### Addendum 2026-10-15 — bukti GitHub Actions Step 13

**Status Step 13 diperbarui:** PASS untuk scope yang dibangun (register operasional, revisi Design, costing vs aktual, laporan, test). Kebutuhan Sales, Customer Service, dan handover Admin PPIC tetap **BLOCKED** oleh DEC-005, DEC-006, DEC-008, DEC-010, dan DEC-016.

- **Commit tervalidasi:** `cdd7327c0765be301bce450644a9e30cfcfd5884` pada `origin/main` (di-rebase di atas merge Dependabot `49035db` yang hanya mengubah `package.json`/`package-lock.json`; tidak ada konflik).
- **CI:** GitHub Actions run [`38675203190`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38675203190) berakhir dengan conclusion `success`; kelima job lulus, termasuk dua job yang menjalankan suite penuh pada PostgreSQL 18 + Redis. Ini menggantikan run PostgreSQL lokal yang terganggu oleh suite SQLite yang berjalan bersamaan.

Gate berikutnya adalah Step 14.

## 2026-10-15 — Step 14 Modul Accounting, Account Receivable, dan Tax Invoice

**Status:** PARTIAL. Read model piutang, AR aging dengan bucket PRD §10.2, staged import idempotent, rekonsiliasi snapshot, dan daftar faktur pajak dibangun dan diuji. **BLOCKED:** formula DSO/collection rate/overdue %/unapplied/forecast (DEC-006), perlakuan credit note dan lebih bayar, aturan tenggat faktur pajak, collection follow-up dan promise-to-pay, dashboard rekonsiliasi Admin Accounting (close readiness), dan koneksi sistem akuntansi (DEC-008, DEC-016). Sistem KPI tidak memposting jurnal dan tidak mengubah data sumber.

### Git preflight

- HEAD awal `fb3a8b0` sama dengan `origin/main`; working tree bersih. Diperiksa ulang sebelum commit (`git fetch`): tidak ada commit baru di `origin/main`.

### Scope selesai

- Modul `Accounting`: tabel `ar_snapshots` dan `ar_documents` (invoice, alokasi pembayaran, faktur pajak) dengan referensi sumber, hash isi, versi per tanggal as-of, total kontrol, dan hash baris. Snapshot `staged` → `committed`/`rejected`; snapshot final beserta dokumennya tidak dapat ditambah, diubah, atau dihapus (trigger PostgreSQL dan SQLite).
- Aging (`AgingBuckets`, `AgingCalculator`): bucket PRD versi `prd-10.2` (belum jatuh tempo, 1–30, 31–60, 61–90, > 90 hari) dari konfigurasi berversi; hari kalender dari tanggal as-of; outstanding eksak (brick/math); invoice lunas dan kelebihan alokasi tidak di-aging; dispute ditandai, tidak di-netting.
- Impor (`ArRowParser`, `ArImportService`): kontrak kolom CSV/XLSX, seluruh masalah per baris dilaporkan, file bermasalah tidak dapat di-commit, satu mata uang per file, minimal satu invoice, credit note ditolak karena aturannya belum ada, file sama = snapshot sama, file sama untuk team/as-of lain ditolak, file dari snapshot ditolak boleh diimpor ulang, file baru untuk as-of yang sama = versi berikutnya (impor team diserialkan dengan lock baris unit), total kontrol harus sama persis untuk commit.
- Akses (`ArAccess`): capability baru `accounting_data.view` (Management, Department Head, Team Leader, Auditor; tidak dapat didelegasikan) dan `accounting_data.manage` (Department Head, Team Leader), keduanya dibatasi ke subtree department Accounting (`ACCOUNTING_DEPARTMENT_CODE`, default `ACC`). HR Performance, System Admin, Karyawan, dan Team Leader team lain tidak melihat data piutang.
- Halaman `accounting.index` (menu "Piutang"): impor snapshot, daftar snapshot dengan masalah per baris, commit/tolak, ringkasan aging dan rekonsiliasi. Tiga laporan baru di katalog Step 12 (`ar_aging`, `ar_aging_summary`, `tax_invoices`), berklasifikasi Terbatas, dengan filter snapshot.
- `ImportSheet` digeneralisasi (kolom dan kolom wajib sebagai parameter); perilaku impor aktual Step 08 tidak berubah.
- Dokumentasi: `docs/product/ACCOUNTING_SOURCES.md` (yang aktif dan BLOCKED, kontrak file, aturan hitung, siklus snapshot, akses, pemilik data usulan); pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `REPORTING.md`, `.env.example`.

### Test matrix

| Area | Cakupan |
|---|---|
| Batas bucket | 0, 1, 30, 31, 60, 61, 90, 91 hari; jatuh tempo besok dan tepat pada tanggal as-of; set bucket lain wajib berversi dan valid |
| Tanggal | Hari kalender lintas akhir bulan, tahun, dan 29 Februari; as-of adalah tanggal bisnis; snapshot yang sama memberi aging yang sama |
| Pembayaran | Sebagian, terpisah ke beberapa invoice, lunas oleh dua pembayaran, kelebihan alokasi (saldo kredit, tidak di-aging) |
| Presisi | 0.10 + 0.20 tepat 0.30; nilai 99.999.999.999.999,99; total kontrol eksak |
| Dispute | Ditandai per bucket, outstanding tidak berubah |
| Impor | 10 jenis masalah per baris (duplikat, credit note, invoice tak ada, mata uang alokasi, jatuh tempo, tanggal setelah as-of, format angka, kode berspasi, status faktur), mata uang campuran, file tanpa invoice, total kontrol tidak cocok, file sama, versi baru, file sama untuk as-of lain, impor ulang setelah ditolak |
| Imutabilitas | Ubah/hapus dokumen, ubah status, dan tambah dokumen ke snapshot committed ditolak database |
| Rekonsiliasi | Jumlah bucket = outstanding = invoice − alokasi + saldo kredit |
| Akses | HR, Sales TL, Tax TL (baca saja), Management/Auditor (tidak impor), Sales TL tidak dapat impor untuk team sendiri, audit tanpa data customer |
| Halaman dan laporan | Akses per role, menu, commit dua kali ditolak, pesan "file yang sama sudah diimpor", snapshot staged lama tetap tampil, laporan aging dan faktur pajak |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 932 test: 929 passed, 3 skipped, 14.121 assertions |
| 2 | Test PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`, Docker Compose), dijalankan **tanpa** suite host bersamaan | Suite penuh PostgreSQL 18 + Redis: 932 test, 930 passed, 2 skipped, 0 failed, 14.137 assertions (841 detik) |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit` | valid / tidak ada advisory |
| 6 | `npm audit --omit=dev` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Jumlah test naik dari 903 menjadi 932. Tidak ada dependency baru. Catatan: `php artisan test --parallel` gagal karena ketidakcocokan versi paratest/pest yang sudah ada sebelum step ini; suite dijalankan serial.

### Code review

Satu review independen (keamanan, PHP, dan ketepatan finansial). Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| Critical | Trigger dokumen memakai subquery di kondisi `WHEN`, yang ditolak PostgreSQL, sehingga migrasi gagal di produksi (SQLite menyembunyikannya) | Pemeriksaan status induk dipindah ke fungsi plpgsql; diverifikasi di PostgreSQL 18 lokal |
| High | `lockForUpdate()->max()` menghasilkan `FOR UPDATE` dengan agregat (error di PostgreSQL) dan tidak mengunci apa pun untuk versi pertama | Impor team diserialkan dengan lock baris unit |
| High | Total, bucket, dan total kontrol dapat menjumlahkan mata uang berbeda | Satu mata uang per file |
| Medium | File yang pernah ditolak tidak dapat diimpor ulang; file sama untuk as-of lain diam-diam mengembalikan snapshot lama dengan pesan sukses | Unique hash hanya untuk snapshot yang tidak ditolak; file sama untuk team/as-of lain ditolak; halaman menyatakan "tidak ada yang berubah" |
| Medium | File tanpa baris dapat di-commit sebagai "tidak ada piutang" | Minimal satu invoice |
| Medium | Dokumen dapat ditambahkan ke snapshot committed | Guard INSERT di kedua database |
| Low | `SUM()` SQLite memakai float; daftar snapshot dibatasi 30 sehingga snapshot staged lama tidak dapat diproses; alasan penolakan dapat memuat nama customer; docblock ganda | Penjumlahan dengan BigDecimal di PHP; semua snapshot staged selalu tampil; petunjuk pada isian alasan; docblock dirapikan |

Dicatat, tidak diubah: pemeriksaan "bucket = outstanding" adalah konsistensi internal (pembanding independen adalah total kontrol); angka XLSX bertipe number dibaca 15 digit (didokumentasikan); ringkasan aging dihitung ulang per permintaan (maks. 20.000 baris).

### Deferred dan blocker

- DSO, collection rate, overdue %, unapplied payment, forecast: menunggu formula disetujui (DEC-006).
- Credit note dan lebih bayar: menunggu aturan perlakuan dari Accounting.
- Tenggat dan queue faktur pajak: menunggu aturan business owner.
- Collection follow-up dan promise-to-pay di dalam sistem: hanya bila disetujui.
- Dashboard rekonsiliasi Admin Accounting (open item, koreksi, kelengkapan dokumen, close readiness): menunggu sumber dan aturan.
- Konektor sistem akuntansi: Step 17 setelah DEC-008 dan DEC-016.
- Konfirmasi pemilik data (TL AR, TL Tax Invoice, DH Accounting) dan kode department Accounting produksi.

### Gate berikutnya

- Commit dan push Step 14, tunggu GitHub Actions, lalu catat addendum.
- Step 15 (produksi, PPIC, dan warehouse) hanya atas permintaan.

### Addendum 2026-10-15 — bukti GitHub Actions Step 14

**Status Step 14 diperbarui:** PASS untuk scope yang dibangun (read model piutang, aging bucket PRD, staged import, rekonsiliasi snapshot, faktur pajak, laporan, akses). Formula DSO dan turunannya, credit note dan lebih bayar, tenggat faktur pajak, collection follow-up, close readiness, dan koneksi sistem akuntansi tetap **BLOCKED** (DEC-006, DEC-008, DEC-016, aturan business owner).

- **Commit tervalidasi:** `c4cf0f2fd8f3c9e82fe9c74e8e61f13fe68a30f8` pada `origin/main`.
- **CI:** GitHub Actions run [`38679108432`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38679108432). Percobaan 1: empat job lulus (termasuk suite penuh PostgreSQL 18 + Redis dan Docker Compose); job Docker image gagal karena Trivy tidak dapat mengunduh database kerentanan (`mirror.gcr.io` HTTP 404), sehingga pemindaian tidak berjalan — bukan temuan kerentanan. Percobaan 2 (hanya job yang gagal dijalankan ulang): conclusion `success`, kelima job lulus termasuk pemindaian Trivy CRITICAL/HIGH.

Gate berikutnya adalah Step 15.

## 2026-10-15 — Step 15 Modul Produksi, PPIC, dan Warehouse

**Status:** PARTIAL. Read model batch sumber (aktual produksi, packing, pengiriman, stok warehouse, work order), board PPIC dengan riwayat status append-only, kalender shift berversi, versi koreksi, rekonsiliasi, freshness, dan akses per team dibangun dan diuji. **BLOCKED:** kalender shift belum disetujui (DEC-015) sehingga aktual produksi dan packing belum dapat diimpor di lingkungan nyata (konfigurasi kosong; test memakai kalender **NON-PRODUCTION FIXTURE**); formula output per jam, yield, FPY, reject/rework rate, utilization, OEE, downtime rate, setup/changeover, schedule attainment, dan produktivitas (DEC-006); konversi satuan; dictionary reason/defect/downtime code (DEC-016); konektor MES/WMS/ERP (DEC-008). Sistem KPI tidak mengubah jadwal ERP dan tidak menilai operator individual.

### Git preflight

- HEAD awal `8e5b5e0` sama dengan `origin/main`; working tree bersih.

### Scope selesai

- Modul `Production`: tabel `production_batches`, `production_records`, `ppic_status_changes`. Lima jenis batch: `production` (output, downtime, waste), `packing`, `delivery`, `warehouse`, `work_orders`, masing-masing hanya dapat dimuat team yang bertanggung jawab (`production.responsibilities`; Packing Logistic, Logistic, dan Warehouse terpisah).
- Kalender shift (`ShiftCalendar`, `ShiftCalendars`): versi bertanggal berlaku, shift lewat tengah malam, tanggal operasional `start`/`end` wajib dipilih eksplisit (tanpa nilai bawaan), shift tumpang tindih ditolak, versi kalender mengikuti tanggal mulai shift, menit yang diklaim dua versi ditolak. Default konfigurasi kosong.
- Impor bertahap (`ProductionRowParser`, `ProductionImportService`): kontrak kolom per jenis, semua masalah per baris, shift dan tanggal operasional dari kalender (baris tanggal operasional lain ditolak), kuantitas ≥ 0 maks. 3 desimal, kuantitas/waktu mustahil ditolak, event dihitung sekali lintas tanggal (dicek saat staging dan lagi saat commit di bawah lock team), file sama = batch sama, konten versi lama atau batch ditolak menjadi versi baru, versi lebih lama tidak dapat di-commit setelah versi lebih baru, total kontrol eksak per satuan, waktu ekstraksi sumber wajib.
- Rekonsiliasi dan monitoring (`ProductionQuery`): total per satuan tanpa konversi, rincian shift × mesin × satuan yang dijumlahkan kembali ke total batch, stok per kategori lokasi, freshness per team dan jenis (data berlaku, waktu ekstraksi, commit, reason code kosong, batch menunggu commit).
- Board PPIC (`PpicBoard`): 8 status PRD, jadwal dan material dari batch work order terbaru, penanda overdue, material belum siap, emergency, dan perubahan jadwal (dibanding batch tanggal sebelumnya), progres output good per team pada satuan rencana (tidak dijumlahkan lintas proses), filter customer/order/WO/tanggal kebutuhan/mesin/status, perpindahan dengan expected status (optimistic concurrency), alasan wajib bila overdue atau mundur, riwayat append-only.
- Akses: capability `production_data.view` (Management, Department Head, Team Leader, HR Performance, Auditor), `production_data.manage` dan `ppic_board.manage` (Department Head, Team Leader), dibatasi ke department Produksi dan team PPIC. Sales, Accounting, dan team lain tidak memiliki akses.
- Halaman `production.index` (menu "Produksi": kalender shift, freshness, impor, daftar batch, rekonsiliasi, tautan scorecard) dan `ppic.index` (menu "Board PPIC"). Tiga laporan baru di katalog Step 12: `production_actuals`, `warehouse_stock`, `ppic_work_orders` (Internal).
- Dokumentasi: `docs/product/PRODUCTION_SOURCES.md`; pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `REPORTING.md`, `.env.example`.

### Supported dimensions

Tanggal operasional, shift, mesin, operator (referensi sumber saja, tidak diagregasi), team, work order, produk, proses, satuan, good/reject/rework, downtime (menit), waste, defect code, reason code, lokasi dan kategori warehouse, lot, customer, order, nomor pengiriman, proof of delivery, sistem sumber, versi batch, waktu ekstraksi.

### Test matrix

| Area | Cakupan |
|---|---|
| Batas shift | Menit pertama dan terakhir tiap shift (07:00, 14:59, 15:00, 22:59, 23:00, 00:00, 06:59), akhir bulan, akhir tahun, shift_code yang bertentangan dengan kalender |
| Overnight shift | Anchor `start` dan `end`, baris 02:30/05:30 masuk tanggal operasional sebelumnya, perubahan versi kalender di tengah malam, menit ambigu antarversi ditolak, celah shift, kalender belum ada, konfigurasi tidak valid |
| Duplikasi event | Dalam file, lintas batch berlaku tanggal lain, dua batch staged yang berbagi event (commit kedua ditolak), event bernomor sama di team lain diperbolehkan |
| Satuan | Total per satuan tanpa konversi; total kontrol untuk file multi-satuan ditolak; progres board hanya pada satuan rencana, satuan lain disebut |
| Presisi | 0.1 + 0.2 = 0.300; 12.345.678.902,299; lebih dari 3 desimal ditolak |
| Kuantitas/waktu mustahil | Negatif, koma desimal, output nol, downtime terbalik, downtime > 24 jam, waktu masa depan, format waktu, jenis tak dikenal, WO kosong, waste nol, satuan tidak valid, stok negatif, kategori lokasi tak dikenal, pengiriman sebelum kirim/masa depan/POD tanpa tanggal terima |
| Koreksi terlambat | Versi 2 menjadi berlaku, versi 1 tetap utuh dengan totalnya, audit `supersedes`, konten lama kembali sebagai versi baru, versi lama tidak dapat di-commit setelah versi baru, batch final tidak dapat diubah/dihapus/ditambah/dipindahi baris lewat SQL |
| Rekonsiliasi | Rincian shift × mesin × satuan = total batch; total kontrol eksak; stok per kategori |
| Akses lintas team | Woven/Flexo terpisah, Department Head memuat untuk team, Management/HR/Auditor hanya baca, Sales/Accounting tanpa akses, jenis data per team, board dibaca team produksi tetapi hanya PPIC yang memindahkan |
| Board PPIC | Penanda, filter, expected status basi, status sama, WO tak dikenal, alasan terlambat/mundur, riwayat append-only, progres per team |
| Halaman dan laporan | Akses halaman, impor-commit-rekonsiliasi lewat halaman, team lain tidak melihat batch, pindah status lewat halaman, laporan versi berlaku saja, filter unit/status, kategori lokasi, status board di laporan |
| Volume | 20.000 baris output (batas impor) di-stage, di-commit, dan dijumlahkan eksak: 2,61 detik di SQLite dan 5,62 detik di PostgreSQL 18 (proses test, puncak memori ±430 MB termasuk framework test) |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 983 test: 980 passed, 3 skipped, 14.440 assertions. Run pertama mendapat 1 kegagalan karena `npm run build` saya jalankan bersamaan dan mengganti manifest aset di tengah test; diulang tanpa proses lain dan lulus |
| 2 | Test PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`, Docker Compose), tanpa suite host bersamaan | Suite penuh PostgreSQL 18 + Redis: 983 test, 981 passed, 2 skipped, 0 failed, 14.456 assertions (511 detik). Migrate → rollback → migrate di PostgreSQL juga lulus |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit` | valid / tidak ada advisory |
| 6 | `npm audit --omit=dev` / `npm run build` | 0 vulnerability / build berhasil |
| 7 | SQLite kosong: migrate → rollback → migrate | lulus |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Tidak ada dependency baru.

### Code review

Satu review independen (keamanan, PHP, dan ketepatan data). Tidak ada temuan Critical; tidak ada secret, injection, IDOR, atau XSS. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Laporan memakai `lazyById` dengan urutan tanggal, sehingga di atas 100 batch ada batch yang terlewat atau berulang | Daftar batch diambil utuh lalu diurutkan |
| High | Pemeriksaan event ganda lintas tanggal hanya saat staging; dua batch staged yang berbagi event dapat sama-sama di-commit | Diperiksa ulang saat commit di bawah lock team |
| Medium | File identik untuk tanggal lain (stok yang tidak berubah) ditolak; konten versi lama mengembalikan versi lama yang tidak berlaku | Idempotensi per team, jenis, sumber, dan tanggal; hanya batch staged atau berlaku yang dikembalikan; konten lain menjadi versi baru |
| Medium | Daftar batch halaman memakai 30 batch terbaru seluruh perusahaan sebelum filter scope | Filter scope diterapkan pada subquery |
| Medium | Pencarian event lintas batch tanpa indeks yang sesuai | Indeks `(record_type, event_key)` dan pencarian per jenis |
| Medium | Progres board menjumlahkan output semua team untuk satu WO (proses berurutan terhitung ganda) | Progres per team |
| Medium | `FOR UPDATE` pada baris team memblokir insert lain yang mereferensikan team | `FOR NO KEY UPDATE` |
| Medium | Rekonsiliasi dihitung di view setiap render; board tanpa batas tampilan | Computed per request; tampilan dibatasi 200 WO dengan petunjuk filter |
| Medium | `event_key` stok dapat melebihi 200 karakter (error di PostgreSQL) | Kolom 255 |
| Low | Versi lama dapat di-commit setelah versi baru; baris staged dapat dipindah ke batch final; konfigurasi kalender tidak valid menyebabkan 500; upload sementara tertinggal bila ada exception | Ditolak; trigger memeriksa batch tujuan; pesan jelas; dihapus di `finally` |

Dicatat, tidak diubah: `operator_code` disimpan sebagai referensi sumber (tidak ditampilkan, tidak diekspor, tidak diagregasi); waktu ekstraksi versi baru tidak dibandingkan dengan versi lama; kunci pengiriman `nomor|WO` mengasumsikan nomor pengiriman unik per pengiriman; jumlah status board dihitung pada filter aktif; zona waktu sesi PostgreSQL mengikuti konvensi proyek (UTC).

### Deferred dan blocker

- Kalender shift, cut-off, dan tanggal operasional: DEC-015.
- Formula metrik produksi, denominator, planned downtime, konversi satuan: DEC-006.
- Dictionary reason/defect/downtime code dan master mesin/produk/lokasi: DEC-016.
- Konektor MES/WMS/ERP dan write-back jadwal: Step 17 setelah DEC-008 (write-back butuh persetujuan terpisah).
- Hak staf Admin PPIC/Admin Produksi (role Karyawan) untuk impor atau memindahkan status; granularitas rekonsiliasi yang disepakati; kebijakan penilaian operator.
- Quality Control (NCR/CAPA): Step 16.

### Gate berikutnya

- Commit dan push Step 15, tunggu GitHub Actions, lalu catat addendum.
- Step 16 (QC, NCR, dan CAPA) hanya atas permintaan.

### Addendum 2026-10-15 — bukti GitHub Actions Step 15

**Status Step 15 diperbarui:** PASS untuk scope yang dibangun (batch sumber produksi, packing, pengiriman, stok warehouse, dan work order; kalender shift berversi; rekonsiliasi dan freshness; board PPIC; laporan; akses). Kalender shift resmi (DEC-015), formula metrik produksi dan konversi satuan (DEC-006), dictionary kode (DEC-016), dan konektor MES/WMS/ERP (DEC-008) tetap **BLOCKED**; aktual produksi dan packing belum dapat diimpor di lingkungan nyata sampai kalender shift disetujui.

- **Commit tervalidasi:** `c11dacacac31f7d9e235c6df55c798cf004c3981` pada `origin/main`.
- **CI:** GitHub Actions run [`38717044694`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38717044694) berakhir dengan conclusion `success` pada percobaan pertama; kelima job lulus, termasuk suite penuh PostgreSQL 18 + Redis, environment Docker Compose, dan pemindaian Trivy.

Gate berikutnya adalah Step 16.

## 2026-10-16 — Step 16 Modul Quality Control, NCR, dan CAPA

**Status:** PARTIAL. Referensi inspeksi QC (incoming, in-process, final), NCR dengan lifecycle PRD §10.3, PIC/tenggat/isi/bukti per tahap, verifikasi gagal, reopen, koreksi berhistori, tautan complaint/return/repeat, KPI terkait berversi, dashboard corrective action, bukti terbatas, dan laporan dibangun dan diuji. **BLOCKED:** skala severity, taxonomy defect dan root cause (DEC-006, DEC-016); approver dan SLA per tahap (DEC-002, DEC-009); disposition; preventive action dan effectiveness review (nonaktif sampai disetujui); aturan pencocokan repeat defect otomatis; formula rejection, FPY, complaint, return, repeat defect, inspection lead time, dan closure SLA (DEC-006, DEC-009); integrasi QMS/CRM complaint/return (DEC-008).

### Git preflight

- HEAD awal `98828a5` sama dengan `origin/main`; working tree bersih.

### State model

`TEMUAN → CONTAINMENT → ROOT_CAUSE → CORRECTIVE_ACTION → VERIFICATION → CLOSED`, ditambah `VERIFICATION → CORRECTIVE_ACTION` (verifikasi gagal, alasan wajib) dan `CLOSED → CONTAINMENT | ROOT_CAUSE | CORRECTIVE_ACTION` (reopen, alasan wajib, siklus baru). Tidak ada tahap tambahan. Raise, Temuan, Verification/close, return, reopen, dan koreksi oleh manager QC; Containment, Root Cause, dan Corrective Action juga oleh manager team penanggung jawab atau PIC tahap. Satu tahap terbuka per NCR (indeks unik parsial), tahap selesai tidak dapat diubah, NCR/tahap/riwayat tidak dapat dihapus (trigger PostgreSQL dan SQLite), `lock_version` pada setiap aksi.

### Taxonomy yang aktif

Tidak ada. `config/quality.php` default kosong: NCR tanpa severity, kategori defect dan metode root cause diterima sebagai kode (metode hanya dari daftar bila daftar disetujui), preventive action dan effectiveness review nonaktif, bukti wajib hanya untuk Verification (selalu wajib untuk close).

### Sample traceability

Inspeksi `INS-1` (in-process, WO-100, P-1, lot L-7, 500 diperiksa, 20 reject, defect DF-WEAVE) pada batch inspeksi QC 2026-10-14 v1 → NCR-2026-0001 (team penanggung jawab PROD-WOVEN, kuantitas 20 pcs dan waktu ditemukan diambil dari inspeksi, KPI `QC-REJECT` versi terpublikasi) → Containment, Root Cause, Corrective Action oleh Woven → Closed oleh QC dengan bukti `QC-REC-2026-118`. Complaint `CMP-2026-09` → NCR-2026-0002 "ulangan dari NCR-2026-0001", tanpa menyalin record complaint.

### Scope selesai

- Batch Step 15 jenis baru `inspection` untuk team QC: incoming/in_process/final, inspected_at pada tanggal batch, accepted + rejected ≤ inspected (eksak), work order wajib untuk in-process dan final, reject tanpa defect code dihitung sebagai reason code kosong; CHECK kind PostgreSQL diperbarui.
- Modul `Quality`: tabel `quality_ncrs`, `quality_ncr_stages`, `quality_ncr_events`; `NcrService` (raise, advance, returnToCorrectiveAction, reopen, correct), `NcrQuery` (dashboard, ringkasan, timeline), `QualityAccess`, `QualityRules`.
- Capability `quality_record.view` (Management, Department Head, Team Leader, HR Performance, Auditor) dan `quality_record.manage` (Department Head, Team Leader). Menu "NCR & CAPA" hanya untuk pembaca team QC, team yang punya NCR dalam scope, dan PIC.
- Halaman `quality.index` (ringkasan, filter tahap/team/severity/overdue/milik saya/repeat, form NCR) dan `quality.show` (ketertelusuran, timeline tahap dengan tenggat dan keterlambatan, bukti terbatas, aksi, riwayat). Laporan `quality_ncrs`; laporan `production_actuals` mencakup inspeksi.
- Dokumentasi: `docs/product/QUALITY_NCR_CAPA.md`; pembaruan `PRODUCTION_SOURCES.md`, `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `REPORTING.md`, `.env.example`.

### Test matrix

| Area | Cakupan |
|---|---|
| Lifecycle | Raise dari inspeksi dengan ketertelusuran, lima tahap sampai Closed, riwayat event, audit tanpa teks bukti |
| Transisi tidak sah | Raise/Temuan/Verification oleh non-QC, team lain, Management, Sales; PIC tahap lain; advance NCR Closed; return dari tahap selain Verification; reopen NCR terbuka atau ke Temuan; reopen oleh non-QC |
| Bukti dan aturan opsional | Close tanpa bukti, isi tahap kosong, metode root cause belum disetujui, preventive action nonaktif/aktif, effectiveness review diwajibkan (efektif/tidak efektif), tahap tambahan wajib bukti lewat konfigurasi, teks kepanjangan ditolak |
| Reopen dan koreksi | Verifikasi gagal → Corrective Action, close, reopen ke Root Cause siklus 2; koreksi menyimpan nilai sebelum/sesudah; koreksi tanpa perubahan ditolak |
| Overdue | Hari lewat tenggat dari tahap terbuka, ringkasan dan filter overdue, timeline menampilkan keterlambatan saat tahap diselesaikan, tenggat masa lalu ditolak |
| Duplikasi dan repeat | Defect yang sama dari sumber yang sama ditolak, inspeksi tak dikenal, tautan repeat manual, tidak ada pencocokan otomatis, tautan ke NCR tak dikenal, siklus repeat ditolak |
| Konkurensi | Versi basi ditolak untuk advance dan koreksi |
| Taxonomy | Severity ditolak tanpa skala; skala/taxonomy disetujui membatasi pilihan; konfigurasi tidak valid; skala yang disetujui kemudian tidak menghalangi koreksi kategori |
| KPI | Tautan ke versi definisi terpublikasi, KPI tak dikenal, koreksi tautan berhistori |
| Akses dan bukti | Scope baca (QC, team penanggung jawab, PIC lintas team), bukti terbatas untuk QC, team penanggung jawab, pencatat, Auditor, HR (pemegang `audit_report.view`) dan tidak untuk Management atau operator |
| Inspeksi | Jumlah melebihi diperiksa, WO wajib, tanggal lain, tahap tak dikenal, reason code kosong, inspeksi tanpa reject menghasilkan NCR tanpa kuantitas, team non-QC tidak dapat memuat |
| Halaman dan laporan | Raise dari dashboard, alur penuh lewat halaman (QC dan team penanggung jawab), bukti terbatas di halaman, scope halaman, overdue di dashboard, laporan dengan filter tahap dan unit (subtree), nomor NCR melewati 9999 |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 998 test: 995 passed, 3 skipped, 14.640 assertions |
| 2 | Test PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`, Docker Compose), tanpa suite host bersamaan | Suite penuh PostgreSQL 18 + Redis: 998 test, 996 passed, 2 skipped, 0 failed, 14.656 assertions (680 detik). Run pertama terputus di tengah karena engine Docker Desktop berhenti (Docker API HTTP 500), bukan kegagalan test; stack dinyalakan ulang dan suite diulang penuh |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit` | valid / tidak ada advisory |
| 6 | `npm audit --omit=dev` / `npm run build` | 0 vulnerability / build berhasil (dijalankan sebelum suite, tidak bersamaan) |
| 7 | Migrate → rollback → migrate: SQLite kosong dan PostgreSQL `kpi_test` | lulus di keduanya |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Tidak ada dependency baru.

### Code review

Satu review independen (keamanan, PHP, dan workflow). Tidak ada temuan Critical atau High. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| Medium | Isi tahap dan referensi bukti yang terlalu panjang dipotong diam-diam, padahal tahap selesai tidak dapat dikoreksi | Ditolak dengan pesan |
| Medium | NCR dari inspeksi tanpa reject gagal karena kuantitas 0.000 dari inspeksi | Kuantitas nol dari inspeksi dianggap kosong |
| Medium | Filter unit laporan hanya mencocokkan team penanggung jawab persis | Subtree unit, sebagai team QC pemilik atau team penanggung jawab |
| Medium | Koreksi kategori yang bertabrakan bersamaan menghasilkan error 500 | Dipetakan ke `duplicate_ncr` |
| Low | Nomor setelah NCR-YYYY-9999 bertabrakan; siklus tautan repeat; skala severity yang disetujui kemudian menghalangi koreksi kategori; koreksi kuantitas setara tercatat sebagai perubahan; computed `rules()` bentrok dengan hook validasi Livewire; payload koreksi yang dimanipulasi menyebabkan 500; lookup inspeksi ambigu; team penanggung jawab tidak aktif diterima | Diperbaiki |

Dicatat untuk keputusan (tidak diubah karena akan mengarang aturan approval): Department Head Produksi mencakup team QC dan team produksi, sehingga dapat menyelesaikan Corrective Action sekaligus memverifikasi; Team Leader QC dapat menjadi PIC Corrective Action dan verifikator NCR yang sama. Pemisahan tugas menunggu DEC-002. Juga dicatat: daftar PIC menampilkan nama akun aktif kepada pengguna yang melihat form aksi; nomor NCR "ulangan dari" tampil walau NCR tersebut di luar scope pembaca; rollback migrasi PostgreSQL gagal bila sudah ada batch inspeksi.

### Source integration status

Inspeksi: file batch (CSV/XLSX) melalui halaman Produksi oleh team QC. Complaint, return, dan temuan internal: rujukan nomor dari sistemnya. Belum ada konektor QMS, CRM, atau sistem return (DEC-008, Step 17).

### Deferred dan blocker

- Skala severity, taxonomy defect, metode root cause: DEC-006/DEC-016.
- Approver dan SLA per tahap, pemisahan tugas verifikator: DEC-002, DEC-009.
- Disposition, preventive action, effectiveness review, aturan repeat otomatis: menunggu persetujuan quality process.
- Formula metrik QC: DEC-006.
- Konektor sumber: Step 17.

### Gate berikutnya

- Commit dan push Step 16, tunggu GitHub Actions, lalu catat addendum.
- Step 17 (integrasi dan migrasi data) hanya atas permintaan.

### Addendum 2026-10-16 — bukti GitHub Actions Step 16

**Status Step 16 diperbarui:** PASS untuk scope yang dibangun (inspeksi QC, NCR dan CAPA dengan lifecycle PRD §10.3, bukti, reopen, koreksi berhistori, tautan repeat dan KPI berversi, dashboard, laporan). Taxonomy severity/defect/root cause, approver dan SLA per tahap termasuk pemisahan tugas verifikator, disposition, preventive action, effectiveness review, formula metrik QC, dan integrasi QMS/CRM tetap **BLOCKED** atau nonaktif sampai disetujui.

- **Commit tervalidasi:** `0456135dec1063567065a010e891597b6e30d5d7` pada `origin/main`.
- **CI:** GitHub Actions run [`38840400039`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38840400039) berakhir dengan conclusion `success` pada percobaan pertama; kelima job lulus, termasuk suite penuh PostgreSQL 18 + Redis, environment Docker Compose, dan pemindaian Trivy.

Gate berikutnya adalah Step 17.

## 2026-10-16 — Step 17 Integrasi dan Migrasi Data

**Status:** BLOCKED untuk seluruh koneksi runtime; PARTIAL untuk yang dibangun. DEC-008 (sistem sumber, akses, owner, SLA) dan DEC-016 (system of record per master) masih OPEN, sehingga **tidak ada koneksi PROVEN**. Yang dibangun dan diuji: inventori integrasi, kontrak inbound bertanda tangan, adapter anti-corruption, pipeline staging lengkap dengan rekonsiliasi, retry terbatas, dead-letter dan pemulihan manual, dashboard run integrasi, exception register, alat migrasi master pegawai dengan dua dry run di PostgreSQL, dan pemulihan manual outbox.

### Git preflight

- HEAD awal `db4c53f` sama dengan `origin/main`; working tree bersih (setelah addendum CI Step 16).

### Integration matrix

| Koneksi | Status | Bukti |
|---|---|---|
| `hris-employees` (master pegawai) | BLOCKED | Alat migrasi; 2 dry run + 2 apply di PostgreSQL 18 pada fixture sintetis |
| `hris-organization` | DEFERRED | Dikelola di aplikasi (Step 04) sampai DEC-016 |
| `attendance` | DEFERRED | Tidak ada KPI disetujui yang memakai |
| `crm-sales-orders` | BLOCKED | Tidak ada kontrak internal disetujui |
| `erp-receivables` | BLOCKED | Contract test pipeline + adapter AR (staged, rekonsiliasi, auto-commit) |
| `erp-purchasing` | DEFERRED | Tidak ada modul/KPI disetujui |
| `mes-production`, `mes-packing`, `tms-delivery`, `erp-work-orders`, `wms-stock`, `qms-inspection` | BLOCKED | Contract test pipeline + adapter batch Step 15/16 |
| `qms-complaints` | DEFERRED | Rujukan nomor di NCR (Step 16) |
| `smtp-email` | BLOCKED | SMTP produksi belum ditetapkan (DEC-014); Mailpit lokal |
| `sso` | BLOCKED | DEC-013 |
| `outbound-results` | DEFERRED | Kompensasi di luar scope (DEC-012); outbox siap |

Metadata lengkap per koneksi ada di `config/integrations.php`, halaman Integrasi, dan `docs/integration/INTEGRATION_MATRIX.md`.

### Scope selesai

- Modul `Integration`: `Manifest` (kontrak `kpi-inbound-v1`, HMAC-SHA256, bentuk kanonik), `ConnectionRegistry`, `InboundAdapter` + adapter `ProductionBatchAdapter` dan `ReceivablesAdapter` (modul tidak pernah melihat payload vendor), `CsvHeaderMapper` (nama kolom vendor → kontrak), `InboundRunner`, `ExceptionRegister`, `IntegrationQuery`, `EmployeeMigration`.
- Pipeline: batas ukuran sebelum membaca → salinan stream → verifikasi tanda tangan → kontrak/koneksi/nama file → checksum → tolak ekstrak yang lebih lama → deteksi replay (konten sama untuk team, tanggal, dan sumber yang sama dengan target yang masih berlaku) → pemetaan kolom → staged import modul sebagai akun layanan → rekonsiliasi baris dan total kontrol yang dideklarasikan koneksi → commit atomik hanya bila `auto_commit` disetujui dan rekonsiliasi cocok → arsip atau karantina. Satu proses per koneksi (lock), satu file per run, kegagalan satu file/koneksi tidak menghentikan yang lain.
- Retry: kegagalan teknis diulang dengan jeda bertambah sampai `INTEGRATION_MAX_ATTEMPTS`, lalu dead-letter + exception; run yang ditinggalkan crash dihitung sebagai percobaan; pemulihan manual `integration:retry` atau tombol Ulangi (diaudit atas nama orangnya). Outbound: `ops:outbox-requeue <id> --reason` untuk pesan outbox dead-letter (diaudit).
- Tabel `integration_runs` dan `integration_exceptions` (tidak dapat dihapus; trigger PostgreSQL dan SQLite).
- Halaman `integrations.index` (menu "Integrasi"): status dan alasan per koneksi, run terakhir, sukses terakhir, watermark dan umurnya, jumlah baris/diterima/ditolak/duplikat, retry, dead-letter, exception terbuka, rekonsiliasi; daftar run; exception register dengan keputusan beralasan. Total bernilai terbatas (AR) tidak ditampilkan.
- Capability `integration.view` (System Admin, HR Performance, Auditor) dan `integration.manage` (System Admin). Jadwal `integration:run` tiap 15 menit; semua koneksi nonaktif secara bawaan.
- Dokumentasi: `docs/integration/INTEGRATION_MATRIX.md`, `docs/integration/MIGRATION_PLAN.md` (mapping, aturan alat, hasil dry run, cutover dan rollback, exception register, template sign-off); pembaruan `IDENTITY_ACCESS.md`, `APPLICATION_STRUCTURE.md`, `.env.example`.

### Hasil dry run migrasi (salinan aman)

PostgreSQL 18, database sekali pakai `kpi_test` (Docker Compose lokal), struktur referensi berlaku 2026-01-01, fixture sintetis `tests/Fixtures/integration/legacy-employees.csv` (10 baris `[FIXTURE]`), dijalankan dengan kode final:

| Run | Mode | Baris | Dibuat | Sudah ada | Konflik | Ditolak | Seimbang |
|---|---|---|---|---|---|---|---|
| 1 | dry run | 10 | 7 | 0 | 0 | 3 | ya |
| 2 | dry run | 10 | 7 | 0 | 0 | 3 | ya |
| 3 | apply | 10 | 7 | 0 | 0 | 3 | ya |
| 4 | apply | 10 | 0 | 7 | 0 | 3 | ya |

Pegawai `MIG-*` setelah keempat run: 7. Dua dry run identik dan tidak menulis data; apply kedua tidak menambah apa pun. Test otomatis menambah skenario konflik dengan pegawai yang sudah ada (tidak ditimpa) dan tanggal mustahil.

### Reconciliation dan exception

- Rekonsiliasi per run: baris manifest vs batch, dan setiap total kontrol yang dideklarasikan koneksi (wajib ada di manifest) vs total batch per satuan (BigDecimal). Selisih, total yang hilang, atau baris ditolak → exception dan tidak ada auto-commit.
- Contoh test: total kontrol 11 vs batch 10.000 → selisih −1.000, batch tetap staged; inspeksi reject 20 vs 20.000 → cocok dan ter-commit.
- Exception register mencatat file karantina (tanda tangan, checksum, manifest, ukuran, ekstrak lama, skema, otorisasi), baris ditolak, selisih rekonsiliasi, penolakan commit, konflik dan penolakan migrasi, dan dead-letter, dengan pemilik data (usulan) dan keputusan beralasan.

### Credential boundary

- Kunci manifest (`INTEGRATION_<KONEKSI>_HMAC_KEY`, minimal 32 karakter) dan akun layanan (`INTEGRATION_<KONEKSI>_RUN_AS`) hanya dari environment/secret store; `.env.example` berisi nama variabel dengan nilai kosong; semua koneksi nonaktif.
- Signature tidak disimpan; kegagalan teknis menyimpan dan me-log nama kelas, file, dan baris saja, tidak pernah pesan exception; audit dan exception tanpa isi baris atau nama pegawai.
- Test memakai kunci acak per test dan memastikan kunci serta pesan exception (termasuk "password=...") tidak muncul di run, exception register, atau audit log.

### Test matrix

| Area | Cakupan |
|---|---|
| Kontrak dan autentikasi | Tanda tangan kunci lain, manifest diubah setelah ditandatangani, file diubah (checksum), koneksi/nama file lain, kontrak tak dikenal, total kontrol sebagai float, bukan JSON, kunci terlalu pendek, koneksi nonaktif/tanpa kunci/tanpa akun layanan |
| Skema dan data rusak | Kolom wajib hilang, file kosong, header vendor yang tidak dapat dipetakan, baris salah (batch staged tanpa baris tersimpan), file melebihi batas ukuran |
| Idempotensi dan replay | File yang sama → duplikat tanpa data baru; konten sama untuk tanggal lain atau setelah batch ditolak → dimuat lagi; konten yang sudah dimuat manusia → duplikat, tidak di-commit atas nama orang lain; ekstrak lebih lama ditolak |
| Retry dan partial failure | Kegagalan teknis diulang sesuai jeda sampai dead-letter, tidak diulang otomatis setelahnya, pemulihan manual; run crash menjadi dead-letter; lock antar-proses; satu koneksi gagal tidak menghentikan koneksi lain |
| Rekonsiliasi | Total cocok → auto-commit; selisih, total hilang, multi-satuan → exception tanpa commit; AR dan inspeksi lewat adapter masing-masing |
| Anti-corruption | Pemetaan kolom vendor (delimiter titik koma) ke kontrak |
| Migrasi | Dua dry run identik tanpa tulis, apply sekali, apply kedua tanpa tambahan, konflik tidak ditimpa, baris duplikat/team tidak aktif/tanggal salah/tanggal mustahil ditolak, pemetaan kolom legacy, file tanpa kolom kontrak, command dry run/apply, tidak ada data KPI/approval yang dibuat |
| Akses | Dashboard hanya System Admin/HR/Auditor, keputusan exception hanya System Admin, run manual diaudit atas nama orangnya, total AR disembunyikan, exception tidak dapat dihapus |
| Outbox | Requeue dead-letter dengan alasan wajib dan audit |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4) | 1.034 test: 1.031 passed, 3 skipped, 14.913 assertions |
| 2 | Test PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`), tanpa suite host bersamaan | Suite penuh PostgreSQL 18 + Redis: 1.034 test, 1.032 passed, 2 skipped, 0 failed, 14.929 assertions (520 detik) |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit` | valid / tidak ada advisory |
| 6 | `npm audit --omit=dev` / `npm run build` | 0 vulnerability / build berhasil (sebelum suite, tidak bersamaan) |
| 7 | Migrate → rollback → migrate: SQLite kosong dan PostgreSQL `kpi_test` | lulus di keduanya |
| 8 | `git diff --check` dan pemeriksaan CRLF | bersih |

Run SQLite pertama gagal 1 test: ArchTest (preset keamanan) menolak `tempnam` di kode aplikasi; diganti dengan file sementara bernama acak yang dibuat eksklusif (`TempFile`), lalu suite diulang dan lulus. Docker Desktop sempat mati sebelum dry run final; dinyalakan ulang dan dry run diulang. Tidak ada dependency baru.

### Code review

Satu review independen (keamanan, PHP, integrasi); run pertama review terhenti dan dilanjutkan sampai selesai. Tidak ada temuan Critical. Diperbaiki dengan test:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Kunci replay hanya koneksi + hash: stok/AR yang tidak berubah untuk tanggal baru dan konten dari batch yang ditolak dianggap duplikat | Replay = konten sama untuk team, tanggal, sumber yang sama dengan target yang masih berlaku |
| High | File/manifest dibaca utuh ke memori tanpa batas; run crash tertinggal `received` dan dibuat ulang tanpa batas | Batas ukuran sebelum membaca, salinan stream, batas manifest 16 KB, run crash dihitung sebagai percobaan |
| Medium | Kegagalan saat memproses penolakan (storage) lolos dari pipeline | Dibungkus; menjadi kegagalan teknis |
| Medium | Total kontrol yang dideklarasikan boleh tidak ada, lalu auto-commit | Wajib ada; bila hilang menjadi selisih tanpa commit |
| Medium | Total kontrol reject inspeksi ditolak modul batch | Adapter hanya meneruskan total yang diperiksa modul; sisanya direkonsiliasi runner |
| Medium | File kosong/header tidak terpetakan diulang sebagai kegagalan sementara | Dikarantina sebagai `invalid_file` |
| Medium | Scheduler dan tombol manual dapat memproses koneksi bersamaan | Lock per koneksi |
| Medium | Konten yang sudah di-stage manusia dapat di-auto-commit atas nama akun layanan | Dicatat duplikat; tidak di-commit |
| Medium | Ekstrak lama yang terlambat menggantikan data yang lebih baru | Ditolak `stale_extract` |
| Medium | Total AR terlihat oleh System Admin/HR di dashboard dan exception | Disembunyikan untuk koneksi Restricted |
| Medium | Tanggal mustahil menggagalkan seluruh run migrasi di PostgreSQL; pencarian home team berbeda dari aturan direktori | Validasi tanggal; memakai `homeUnitId()` direktori |
| Low | Diagnostik kegagalan tidak ada; run/retry manual tidak mencatat orangnya; header bertanda kutip; `{}` vs `[]` pada tanda tangan; panjang kunci minimum; tombol Ulangi untuk run migrasi; indeks; error terakhir outbox tidak masuk audit | Diperbaiki |

Dicatat, tidak diubah: akun layanan (run-as) adalah akun biasa dengan role team — harus akun khusus non-interaktif yang didaftarkan dan direview (belum ada role layanan); keputusan exception hanya oleh System Admin dengan alasan yang menyebut pemilik data yang menyetujui (pemilik data belum ditetapkan, DEC-016); urutan pengiriman "data dulu, manifest terakhir" didokumentasikan, belum ada pemeriksaan umur file; migrasi tidak membandingkan jabatan/tanggal mulai pegawai yang sudah ada.

### Deferred dan blocker

- Semua koneksi runtime: DEC-008, DEC-016 (dan DEC-013 SSO, DEC-014 SMTP/hosting, DEC-015 kalender shift untuk produksi/packing).
- Migrasi historis periode, target, aktual, bukti: tidak ada sumber legacy disetujui; riwayat approval tidak pernah dibuat dari data legacy.
- Sign-off pemilik bisnis, rencana cutover dan rollback, RPO/RTO (DEC-010): template di `MIGRATION_PLAN.md`.
- Role akun layanan non-interaktif dan media transfer (SFTP/share) beserta kredensialnya.

### Gate berikutnya

- Commit dan push Step 17, tunggu GitHub Actions, lalu catat addendum.
- Step 18 (keamanan, observability, backup, dan DR) hanya atas permintaan.

### Addendum 2026-10-16 — bukti GitHub Actions Step 17

**Status Step 17 diperbarui:** PASS untuk scope yang dibangun (inventori integrasi, kontrak `kpi-inbound-v1`, adapter, pipeline staging dengan rekonsiliasi, retry/dead-letter dan pemulihan manual, dashboard dan exception register, migrasi master pegawai dengan dry run, requeue outbox). Seluruh koneksi runtime tetap **BLOCKED** atau **DEFERRED** (DEC-008, DEC-013, DEC-014, DEC-015, DEC-016); migrasi produksi menunggu sumber legacy dan sign-off pemilik bisnis.

- **Commit tervalidasi:** `c92f5d122659c9143006e878b771cea2025c6f1c` pada `origin/main`.
- **CI:** GitHub Actions run [`38845623403`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38845623403) berakhir dengan conclusion `success` pada percobaan pertama; kelima job lulus, termasuk suite penuh PostgreSQL 18 + Redis, environment Docker Compose, dan pemindaian Trivy.

Gate berikutnya adalah Step 18.

## 2026-10-16 — Step 18 Keamanan, Observability, Backup, dan Disaster Recovery

**Status:** PARTIAL. Hardening aplikasi, observability, register kerentanan, dan backup terenkripsi + restore rehearsal terisolasi **terbukti di lingkungan lokal** dengan test dan bukti runtime. **BLOCKED:** penerimaan DR (target RPO/RTO, retention, offsite — DEC-010/DEC-014), alert aktif dan backend log/metrik (DEC-014), TLS/WAF/secret manager produksi (DEC-014), SSO/MFA dan kebijakan penguncian akun (DEC-013), retention/purge/anonymization (DEC-010), serta persetujuan exception kerentanan oleh pemiliknya.

### Git preflight

- HEAD awal `99f950c` sama dengan `origin/main`; working tree bersih (setelah addendum CI Step 17).

### Threat yang diperbaiki

| Threat | Perbaikan | Bukti |
|---|---|---|
| Rute bawaan `storage/{path}` (GET/PUT) dari disk `local` dengan `serve` terdaftar tanpa auth | `serve => false`; test memastikan tidak ada rute `storage/` | `HardeningTest` |
| Unduhan bukti/ekspor tanpa batas laju (scraping) | Limiter `downloads` 60/menit per akun, dihitung sebelum lookup | `HardeningTest` (60× lalu 429) |
| Enumerasi akun lewat lupa password; rute reset tanpa throttle | Jawaban seragam (`UniformPasswordResetLinkResponse`); limiter `password-reset` 5/menit per IP untuk `password.email` dan `password.update` | `HardeningTest` |
| Tidak ada header keamanan aplikasi / CSP | `SecurityHeaders`: CSP same-origin, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS hanya di HTTPS; nginx tidak lagi mengirim header ganda | `HardeningTest`; smoke nginx; browser (login dan lupa password: Alpine/Livewire/Flux termuat tanpa pelanggaran CSP, tanpa resource eksternal) |
| HTTPS di belakang proxy TLS tidak dikenali (cookie secure, HSTS, URL bertanda tangan) | `TrustConfiguredProxies` dari `TRUSTED_PROXIES`; default tidak mempercayai proxy | Konfigurasi + gerbang `ops:security-check` |
| Secret dapat masuk log | Processor redaksi di setiap channel yang menulis (diuji per channel); kunci dinormalkan (`X-Api-Key`, `privateKey`), bentuk JSON, header Authorization/Cookie, kredensial URL; log request tanpa query string; slow query tanpa binding; job gagal tanpa pesan exception | `HardeningTest`, `ObservabilityTest` |
| Konfigurasi produksi tidak aman lolos deploy | `ops:security-check` (APP_DEBUG, APP_KEY, APP_URL https, cookie session, integrasi aktif lengkap; peringatan enkripsi session, log JSON, level log, proxy, pengirim email) tanpa mencetak nilai | `HardeningTest` |
| Host header poisoning: tautan reset password dibangun dari Host request; `X-Forwarded-Host` dipercaya di belakang proxy | Tautan reset dari `APP_URL`; `TrustApplicationHost` (host selain `APP_URL` → 400 di luar local/test, `/health` dikecualikan); proxy hanya dipercaya untuk alamat, port, skema; nginx membuang header host/prefix | `HardeningTest`; smoke image produksi (Host asing → 400, `/health` 200) dan langkah smoke CI baru |
| Exception (mis. `QueryException` dengan nilai binding: email, hash password) masuk log apa adanya | Exception diringkas oleh processor redaksi; error database hanya SQLSTATE + SQL berplaceholder | `HardeningTest` (sentinel) |
| Rotasi kunci integrasi memutus pengiriman | `INTEGRATION_<KONEKSI>_HMAC_KEY_PREVIOUS` diterima selama transisi; `ops:security-check` memperingatkan selama kunci lama masih diterima | `InboundPipelineTest`, `ObservabilityTest` |
| Toolchain compiler dan header kernel di image produksi | Dibuang; hanya library runtime PHP | Trivy, smoke runtime |

Tinjauan lengkap: `docs/security/THREAT_MODEL.md` §6 dan `docs/security/AUTHORIZATION_REVIEW.md` (setiap controller, halaman/action Livewire, job, deep link, dan direct object access dengan test negatifnya). Webhook: N/A (tidak ada endpoint webhook).

### Dependency dan container

| Sumber | Hasil |
|---|---|
| `composer audit` | tidak ada advisory |
| `npm audit` (semua dependency) | 0 kerentanan |
| Image `http` (nginx/Alpine), Trivy 0.74.0 | 0 CRITICAL/HIGH |
| Image `production` sebelum Step 18 | 2 CRITICAL, 118 HIGH, 927 MEDIUM; 0 yang punya fix; 62 dari 80 CVE unik CRITICAL/HIGH di header kernel toolchain compiler |
| Image `production` setelah Step 18 | 1 CRITICAL, 52 HIGH (18 CVE unik), 0 yang punya fix; gerbang CI `--ignore-unfixed` lulus pada image final |

- Mitigasi: toolchain compiler, header kernel, `curl` CLI, dan modul Perl penuh dibuang dari image produksi (177 → 114 paket); semua ekstensi dan smoke runtime terverifikasi.
- Triage exploitability dan exception E-01 s.d. E-08 dengan pemilik role dan tanggal kedaluwarsa: `docs/security/VULNERABILITY_EXCEPTIONS.md`. Hanya E-01 (`libxml2`, lewat upload XLSX oleh akun berwenang) yang dapat dijangkau; semua berstatus **USULAN** sampai disetujui pemiliknya.
- CI: langkah baru "Vulnerability report including unfixed" (tidak memblokir) untuk triage berkelanjutan.

### Observability

- Log terstruktur: channel `json`, correlation ID `X-Request-Id` (ikut ke setiap entri dan job), entri `http.request`, `db.slow_query`, `queue.job_failed`, redaksi di semua channel.
- Readiness `ops:readiness` membedakan dependency kritis (database, cache, storage, queue → FAIL) dari sinyal opsional (heartbeat scheduler/worker, job gagal, outbox dead-letter, kesegaran integrasi → WARN; `--strict` dan `--json`).
- Metrik `ops:metrics` (JSON/Prometheus): antrean, job gagal, outbox (pending/dead-letter/umur), heartbeat, ekspor, notifikasi, run dan exception integrasi, batch staged.
- Alert: 16 alert usulan dengan pemilik role, severity, dan langkah pertama (`docs/operations/ALERTS.md`); **belum aktif** (DEC-014).
- Dokumen: `docs/operations/OBSERVABILITY.md`.

### Backup dan restore rehearsal

- `scripts/backup.sh`: PostgreSQL (`pg_dump -Fc`) dan semua objek disk `s3` (manifest SHA-256), dienkripsi GnuPG AES-256 dengan pemeriksaan integritas (MDC); jumlah baris, checksum tabel kunci, dan dump dari satu snapshot database (`pg_export_snapshot`); file kunci satu baris di luar repository; `umask 077`; plaintext dihapus juga saat gagal; penanda `INCOMPLETE`.
- `scripts/dr-rehearsal.sh`: restore ke PostgreSQL 18.6 baru di jaringan Docker `--internal`, verifikasi skema, jumlah baris semua tabel, checksum tabel kunci, file bukti terhadap manifest dan metadata database, aplikasi membaca data, login canary; laporan per run; password ke Docker lewat nama variabel.

Bukti (lokal, data sintetis `kpi_test`, skrip final):

| Ukuran | Hasil |
|---|---|
| Backup `20261016T090807Z` | 4 detik; dump terenkripsi 423.998 byte, objek 10.322 byte; 66 tabel; 21 migrasi |
| Restore | `pg_restore` 8 detik; skema, jumlah baris 66 tabel, dan checksum 7 tabel kunci sama |
| File bukti | 3 objek, 0 rusak, 3 metadata, 0 hilang, 0 checksum berbeda |
| Aplikasi | 14 akun dan 25 unit terbaca; login canary terverifikasi |
| **RTO terukur** | **14 detik** |
| **RPO terukur** | **15 detik** (kegagalan simulasi 15 detik setelah snapshot); jendela terburuk = interval backup |
| Negatif | ciphertext diubah + `SHA256SUMS` ditulis ulang → ditolak ("manipulated"); kunci salah → ditolak; file kunci dua baris dan kunci di repository → ditolak sebelum mulai; tidak ada container/jaringan/plaintext tersisa |

**Target:** RPO/RTO belum disetujui (DEC-010). Usulan RPO ≤ 24 jam, RTO ≤ 4 jam terpenuhi di lokal, tetapi pada 0,4 MB data sintetis; **penerimaan DR BLOCKED** sampai target disetujui, lokasi offsite dipilih (DEC-014), dan pengukuran diulang di staging dengan volume pilot. Retention/purge/anonymization tidak diterapkan karena belum ada kebijakan (DEC-010); audit dan legal hold tidak tersentuh.

### Runbook

- `docs/operations/BACKUP_RESTORE.md` (prosedur, enkripsi dan kunci, rehearsal, bukti, rotasi kredensial, dependency pemulihan).
- `docs/operations/DR_RUNBOOK.md` (target usulan, skenario, prosedur restore produksi, latihan).
- `docs/operations/INCIDENT_RESPONSE.md` (severity, peran, eskalasi, komunikasi, template update dan PIR).

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | `php artisan test` (SQLite in-memory, PHP 8.4), setelah semua perbaikan kode | 1.063 test: 1.060 passed, 3 skipped, 15.114 assertions |
| 2 | Test PostgreSQL lokal (`bash scripts/dev.sh test-pgsql`), tanpa proses bersamaan | Run pertama: 1 gagal (limiter tersimpan di Redis, test reset password memakai IP yang sama dengan test lain). Test diisolasi (IP acak, bucket unduhan dikosongkan), diulang 2× untuk file keamanan, lalu suite penuh: **1.063 test, 1.061 passed, 2 skipped, 0 failed**, 15.130 assertions (770 detik) |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 error |
| 5 | `composer validate --strict` / `composer audit` | valid / tidak ada advisory |
| 6 | `npm audit` / `npm audit --audit-level=high` / `npm run build` | 0 vulnerability / lulus / build berhasil (sebelum suite, tidak bersamaan) |
| 7 | Migrate → rollback → migrate: SQLite kosong dan PostgreSQL `kpi_test` | lulus di keduanya (21 migrasi) |
| 8 | Image produksi + nginx final (build lokal) | ekstensi termuat; `/health` 200, `/login` 200, Host asing → 400, `/health` dengan Host asing 200, setiap header keamanan sekali; Trivy `--ignore-unfixed` CRITICAL/HIGH lulus untuk kedua image |
| 9 | Backup + restore rehearsal + 4 skenario negatif | lulus (lihat di atas) |
| 10 | Browser (stack lokal `http://127.0.0.1:8088`) | halaman login dan lupa password: tanpa pesan console/pelanggaran CSP, tanpa resource eksternal; halaman setelah login belum diverifikasi |
| 11 | `git diff --check` dan pemeriksaan CRLF | bersih |

Tidak ada dependency aplikasi baru. Skrip backup kini membutuhkan `gpg` di host operator (bukan dependency aplikasi).

### Code review

Satu review keamanan independen read-only atas seluruh perubahan Step 18. Tidak ada temuan Critical. Diperbaiki dengan test atau bukti runtime:

| Tingkat | Temuan | Perbaikan |
|---|---|---|
| High | Host header poisoning pada tautan reset password (`X-Forwarded-Host` dipercaya, tanpa trusted host) | Tautan dari `APP_URL`, `TrustApplicationHost`, header host/prefix tidak dipercaya, nginx membuang header tersebut, smoke CI |
| High | Redaksi tidak menyentuh exception; `QueryException` membawa nilai binding | Exception diringkas; error database hanya SQLSTATE + SQL berplaceholder |
| Medium | Topologi proxy tidak jelas; `*` dapat dipalsukan | Panduan `TRUSTED_PROXIES` (CIDR load balancer), peringatan `*` di gerbang keamanan, nginx membuang header host/prefix |
| Medium | Backup AES-CBC tanpa autentikasi (dapat dimanipulasi) | GnuPG AES-256 + MDC; diuji dengan ciphertext yang diubah |
| Medium | File kunci hanya diperiksa jumlah byte; `-pass file:` membaca baris pertama | Validasi satu baris, ≥32 karakter base64/hex |
| Medium | Redaksi tidak di semua channel; variasi kunci/JSON/header/URL lolos | Tap di semua channel (diuji per channel), normalisasi kunci, pola tambahan |
| Medium | Kunci HMAC lama diterima tanpa batas tanpa peringatan | Peringatan di `ops:security-check` |
| Medium | Plaintext rehearsal dengan umask bawaan; password di argv Docker | `umask 077`; password lewat nama variabel environment |
| Medium | Jumlah baris/checksum dan dump tidak dari snapshot yang sama; waktu snapshot tidak tepat | Satu snapshot `pg_export_snapshot` + `pg_dump --snapshot`; `snapshot_at` = waktu snapshot |
| Medium | `CONTENT_SECURITY_POLICY=` kosong menghasilkan CSP kosong | Fallback `?:` |
| Low | Request ID menerima newline di akhir; path snapshot/manifest tidak divalidasi; salinan objek tidak dicek; metrik crash bila satu sumber gagal; bukti rehearsal masih placeholder; limiter reset berbagi bucket; HSTS `includeSubDomains` sejak awal; pengecualian rute aset di test terlalu lebar; event job gagal tidak diuji | Diperbaiki |

Dicatat, tidak diubah: CSP masih `'unsafe-inline'`/`'unsafe-eval'` (bukan kontrol XSS yang berarti; target CSP berbasis build Livewire); timing oracle reset password karena email sinkron; route model binding berjalan sebelum `signed` pada unduhan (ID ULID tidak dapat ditebak); rehearsal memakai image development (rehearsal image produksi di staging); `Connection` menyimpan kunci sebagai properti publik (tidak di-dehydrate Livewire).

### Deferred dan blocker

- DEC-010: retention/purge/anonymization (tidak ada purge yang diterapkan; audit dan legal hold tidak tersentuh), target RPO/RTO, availability, maintenance window, retensi log dan backup.
- DEC-014: hosting, TLS/WAF, secret manager, lokasi backup offsite, registry, agregator log/metrik/pager, nama pemilik alert dan exception.
- DEC-013: SSO, kewajiban MFA, kebijakan penguncian akun dan masa idle session.
- Persetujuan exception kerentanan E-01 s.d. E-08; E-01 (libxml2, dapat dijangkau lewat upload XLSX oleh akun berwenang) kedaluwarsa 2026-10-30.
- CSP masih `'unsafe-inline'`/`'unsafe-eval'` (Livewire/Alpine); halaman setelah login belum diverifikasi CSP di browser.
- Pengukuran RPO/RTO pada volume produksi/pilot di staging (Step 20).

### Gate berikutnya

- Commit dan push Step 18, tunggu GitHub Actions, lalu catat addendum.
- Step 19 (pengujian menyeluruh dan kualitas) hanya atas permintaan.

### Addendum 2026-10-16 — bukti GitHub Actions Step 18

**Status Step 18 tetap PARTIAL.** Yang terbukti (test, CI, dan runtime lokal): hardening aplikasi (header/CSP, host tepercaya, reset password seragam dan di-throttle, unduhan signed dan di-throttle, tanpa rute storage publik, gerbang konfigurasi produksi), redaksi log termasuk exception, correlation ID, readiness/metrik, rotasi kunci integrasi, image produksi tanpa toolchain compiler, register exception kerentanan, serta backup terenkripsi-terautentikasi dengan restore rehearsal terisolasi (RTO 14 detik, RPO 15 detik pada data sintetis). Tetap **BLOCKED**: penerimaan DR dan target RPO/RTO, retention/purge (DEC-010); hosting, TLS/WAF, secret manager, backup offsite, backend log/metrik/alert dan pemilik (DEC-014); SSO/MFA dan kebijakan penguncian akun (DEC-013); persetujuan exception E-01 s.d. E-08 (E-01 kedaluwarsa 2026-10-30).

- **Commit tervalidasi:** `456455b4b0dc485699b4f02cf1eeb4395a9cc60b` pada `origin/main`.
- **CI:** GitHub Actions run [`38882815993`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/38882815993) berakhir `success` pada percobaan pertama; kelima job lulus, termasuk suite penuh PostgreSQL 18 + Redis, environment Docker Compose, smoke image produksi dengan pemeriksaan Host asing → 400, dan gerbang Trivy. Langkah baru laporan CVE tanpa fix menampilkan `Total: 53 (HIGH: 52, CRITICAL: 1)` untuk image aplikasi, sama dengan triage di `docs/security/VULNERABILITY_EXCEPTIONS.md`.

Gate berikutnya adalah Step 19 (hanya atas permintaan).

## 2026-10-26 — Step 19 Pengujian Menyeluruh dan Kualitas (run 1: core readiness)

**Status:** PARTIAL. Gerbang teknis lulus: authorization negatif, journey inti di browser sungguhan (Chromium, Firefox, WebKit), aksesibilitas otomatis, tidak ada defect Critical/High pada produk, dan tidak ada flaky test atau skip tanpa alasan. **BLOCKED oleh keputusan:** golden example skor bertanda tangan (DEC-004), beban performa yang disepakati (DEC-007), daftar browser resmi (DEC-010), dan UAT (Step 20). **Terbuka:** D-06/N-01, advisory high pada `vite-plus` 0.3.0 (dev tooling) yang membuat job CI `frontend` (`npm audit --audit-level=high`) gagal sampai `vite-plus` dimigrasi ke 1.x.

### Git preflight

- HEAD awal `99f31dd` sama dengan `origin/main`; working tree bersih; `git merge --ff-only origin/main` sudah up to date.

### Scope selesai

- **Traceability matrix** `docs/qa/TRACEABILITY_MATRIX.md`: PRD §16 (12 kriteria rilis inti), §15 (NFR 001–010), §14 (audit/keamanan), dan 10 butir prompt → test → bukti → status.
- **E2E Playwright** (`pestphp/pest-plugin-browser` 5.1, `playwright` 1.64.0), suite `Browser` (`tests/Browser`):
  - `CoreJourneyTest`: pegawai mengisi aktual dan mengajukan; bukti terlihat di UI; TL memverifikasi; HR menghitung dan memindahkan periode ke Review; Department Head review; HR kalibrasi; Management approval final; HR finalisasi dan lock; input aktual hilang setelah lock; koreksi HR disetujui Management (perhitungan baru menunjuk yang lama, periode tetap Locked); ekspor Excel dari UI; Auditor membaca audit koreksi tanpa kontrol ubah. Tambahan: HR membuka periode lewat UI.
  - `AccessAndAccessibilityTest`: 7 peran × 26 halaman harian dengan scan axe-core (serious/critical) + error JavaScript/CSP; 2 halaman di viewport tablet; 6 direct URL di luar peran (403/404, tanpa konten halaman); login hanya keyboard; password salah.
  - Browser test memakai build Vite produksi (`withVite()` khusus suite Browser).
- **Probe performa** suite `Performance` (`tests/Performance/PageResponseTest.php`, `tests/Support/PerformanceDataset.php`): 21 team, 316 pegawai, 632 target/aktual, 316 scorecard sintetis lewat service aplikasi; 16 halaman × 20 sampel; gagal bila p95 > 3 detik. Laporan `docs/qa/PERFORMANCE_REPORT.md`.
- **Regresi concurrency/boundary** `tests/Feature/Release/ReleaseRegressionTest.php`: lock vs edit aktual/target/hitung ulang, submit ganda, koreksi vs ekspor (file lama tidak berubah; ekspor yang diantrekan sebelum koreksi lalu dibuat sesudahnya memuat skor terkoreksi; permintaan baru = ekspor baru).
- **Laporan** `docs/qa/RELEASE_TEST_REPORT.md` dan `docs/qa/EVIDENCE_INDEX.md`.
- `phpunit.xml`: suite default `Unit,Feature`; `Browser` dan `Performance` dijalankan eksplisit.
- CI: job baru `e2e` (Chromium, `npm run build`, suite Browser, screenshot kegagalan sebagai artifact); `sockets` ditambahkan ke `PHP_EXTENSIONS`.
- Dockerfile: `ext-sockets` hanya di stage `development` (dibutuhkan plugin browser saat `composer install`); image produksi tidak berubah.

### Defect yang ditemukan Step 19

| ID | Temuan | Severity | Status |
|---|---|---|---|
| D-01 | Dashboard: `<dl>` dengan tautan di luar `<dt>/<dd>` (axe serious) | Medium | Ditutup |
| D-02 | Pagination Livewire: `aria-label` pada `<span>` tanpa role; label "&laquo; Previous" dibacakan mentah | Medium | Ditutup: override `resources/views/vendor/livewire/tailwind.blade.php` |
| D-03 | Judul "Menu" sidebar kontras 2,41:1 | Low | Ditutup |
| D-04 | N+1: lookup bypass review per scorecard di dashboard, inbox, dan alur approval (396 query pada 316 scorecard) | Medium | Ditutup: `WorkflowService::reviewBypassesInForce()`; test `WorkflowQueryEfficiencyTest` (24 → 1 query) |
| D-05 | `livewire/livewire` 4.4.7 GHSA-mhf7-xp6r-6qrc (dipublikasikan 23-10-2026) | Medium | Ditutup: 4.4.8 (atribut `#[Authorize]` tidak dipakai aplikasi) |
| D-06 | N-01: `vite-plus` 0.3.0 → advisory high/moderate dev server Vite, `launch-editor`, Vitest; `npm audit --audit-level=high` gagal | Medium (tanpa eksposur produksi) | **Terbuka**: butuh migrasi `vite-plus` 1.x (`vp migrate`); usulan exception di `docs/security/VULNERABILITY_EXCEPTIONS.md` §2a sampai 2026-11-09 |

### Validasi

| # | Gate | Hasil |
|---|---|---|
| 1 | Baseline sebelum perubahan, `php artisan test` (SQLite, PHP 8.4.25) | 1.063 test: 1.060 lulus, 3 skip, 0 gagal |
| 2 | `php artisan test` (suite default Unit + Feature, SQLite) setelah semua perubahan | **1.069 test: 1.066 lulus, 3 skip, 0 gagal**, 15.141 assertion |
| 3 | `bash scripts/dev.sh test-pgsql` (Compose PostgreSQL 18 + Redis, PHP 8.5.11) | **1.069 test: 1.067 lulus, 2 skip, 0 gagal**, 15.157 assertion |
| 4 | `php artisan test --testsuite=Browser` (Chromium) setelah perbaikan review | 18/18 lulus, 196 assertion, 3 run berturut-turut |
| 5 | Suite Browser di Firefox 157 dan WebKit 27.2 | 18/18 lulus di masing-masing |
| 6 | `bash scripts/dev.sh test-pgsql --testsuite=Performance` | lulus; p95 tertinggi 547 ms |
| 7 | `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` (level 7) | lulus / 0 error |
| 8 | `composer validate --strict` / `composer audit` | valid / tanpa advisory (setelah Livewire 4.4.8) |
| 9 | `npm audit --audit-level=high` | **gagal**: N-01 (`vite-plus` 0.3.0, sudah ada sebelum Step 19) |
| 10 | `npm run build` | berhasil |
| 11 | `bash scripts/dev.sh bootstrap` dengan image development baru | health, readiness, storage privat 403, queue smoke tepat 1×, heartbeat |
| 12 | `git diff --check` dan pemeriksaan CRLF | bersih |

Skip: 2 test registrasi (fitur registrasi mandiri Fortify sengaja nonaktif) dan 1 test constraint khusus PostgreSQL (di-skip hanya di SQLite).

### Performa (PostgreSQL 18 + Redis, Compose lokal, i5-10300H)

- 16 halaman standar, 20 sampel: p95 tertinggi **547 ms** (dashboard Team Leader); alur approval periode HR 530 ms; halaman lain ≤ 418 ms.
- Setelah D-04, query dashboard/approval turun dari 380–396 menjadi 22–38 pada dataset yang sama.
- **Bukan bukti NFR 002**: beban dan konkurensi yang disepakati belum ada (DEC-007); diukur ulang di staging.

### Aksesibilitas dan browser

- axe-core serious/critical: 0 pelanggaran setelah D-01 s.d. D-03, di Chromium, Firefox, dan WebKit.
- Review manual screen reader belum dilakukan (Step 20).
- Daftar browser resmi belum ditetapkan (DEC-010).

### Code review

Satu review independen read-only atas seluruh perubahan. Tidak ada temuan Critical/High; perbaikan N+1 dinyatakan setara semantiknya dan tanpa state lintas request. Diperbaiki: placeholder laporan diisi dari hasil nyata; login keyboard menunggu dashboard sebelum memeriksa URL; test password salah memeriksa pesan sebelum path; cek 403/404 juga memastikan konten halaman terlindungi tidak tampil; test bypass terjadwal (belum berlaku) ditambahkan; klaim jumlah query di laporan performa dipersempit.

### Keputusan dan asumsi

- Tidak ada aturan bisnis baru. Journey, workflow test, dan probe performa memakai policy skor TEST-ONLY di memori proses test; `config/scoring.php` tetap kosong.
- Unggah Livewire tidak dapat diuji lewat server in-process plugin browser (field multipart `files[]` tidak di-nest; KI-04). Unggah tetap diuji `ActualPagesTest`; journey melampirkan bukti lewat service yang sama lalu memeriksa UI.
- Coverage baris tidak diukur (tidak ada driver pcov/Xdebug); cakupan dinilai per perilaku di traceability matrix.
- Dependency baru (dev saja): `pestphp/pest-plugin-browser` ^5.1 (dengan amphp), `playwright` 1.64.0. `package-lock.json` hanya ditambah entri Playwright; npm di Windows sempat mengubah entri lain dan perubahan itu dibuang.
- PHP 8.4 lokal: `extension=sockets` diaktifkan di php.ini winget (cadangan `php.ini.bak-step19`).

### Blocker

- DEC-004 (golden example bertanda tangan), DEC-007 (beban performa, pilot), DEC-010 (browser resmi, retensi), DEC-002/006/013/014 untuk pilot.
- D-06/N-01: migrasi `vite-plus` 1.x agar job CI `frontend` hijau kembali.
- UAT dan review aksesibilitas manual (Step 20).

### Gate berikutnya

- Commit dan push Step 19, tunggu GitHub Actions, catat addendum (job `frontend` diperkirakan gagal karena N-01; job lain termasuk `e2e` harus lulus).
- Step 20 hanya setelah keputusan pilot (DEC-007) dan prasyarat staging (DEC-013/014) tersedia, atas permintaan.

### Addendum 2026-10-26 — bukti GitHub Actions Step 19

**Status Step 19 tetap PARTIAL.**

- **Commit tervalidasi:** `54cea96a9b12fc3d08270ac036496dc0893c21a1` pada `origin/main`.
- **CI:** GitHub Actions run [`40269488224`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/40269488224) berakhir `failure`, hanya karena job `frontend`. Lima job lain lulus: `php-quality`, `tests` (PostgreSQL 18 + Redis), `e2e` (job baru, Chromium: journey inti, otorisasi, aksesibilitas), `docker-image` (image produksi tanpa ext-sockets, Trivy lulus), dan `compose-smoke` (image development baru dengan ext-sockets, `test-pgsql`).
- **Penyebab `frontend` gagal:** langkah `npm audit --audit-level=high` melaporkan `5 vulnerabilities (4 moderate, 1 high)` pada `vite-plus` ≤ 1.0.0. Ini temuan D-06/N-01 yang sudah ada sebelum Step 19 dan tercatat di `docs/security/VULNERABILITY_EXCEPTIONS.md` §2a. Build produksi tidak terdampak, tetapi gerbang CI tetap merah sampai `vite-plus` dimigrasi ke 1.x.

Gate berikutnya: migrasi `vite-plus` 1.x (tugas terpisah) agar CI hijau; Step 20 atas permintaan setelah prasyarat pilot tersedia.

### Addendum 2026-10-26 — migrasi `vite-plus` 1.4.0 (D-06/N-01 ditutup)

**Status Step 19 tetap PARTIAL** (blocker keputusan tidak berubah); D-06/N-01 ditutup.

- **Perubahan:** `vite-plus` 0.3.0 → 1.4.0. `vite` di `dependencies` dan `overrides` dialiaskan ke `npm:@voidzero-dev/vite-plus-core@1.4.0`, sebab `vite-plus` 1.x mewajibkan plugin (`laravel-vite-plugin`, `@tailwindcss/vite`) memakai core-nya (penyebab PR Dependabot #22 gagal build). Override `tinypool` 2.1.2 dihapus: `oxfmt` 0.74.0 membawa `tinypool` 2.2.0 dan audit tetap bersih. `vite.config.js` tidak berubah; `vp migrate` tidak dijalankan (tidak ada lint/format/test Vitest yang dipakai).
- **Lockfile:** entri `vite-plus`/`vite`/`oxfmt`/`oxlint`/`vitest` lama dibuang lalu di-resolve ulang; paket lain tetap. npm di Windows menghapus field `libc`; field itu dipulihkan dari metadata registry untuk 39 binary Linux (termasuk `@rollup/rollup-linux-x64-gnu`).
- **Bukti lokal (Windows 11, PHP 8.4.25):** `npm ci` berhasil; `npm audit --audit-level=high` → 0 kerentanan; `npm run build` → `public/build/manifest.json`; `php artisan test --testsuite=Browser` → 18/18 lulus, 196 assertion (percobaan pertama, saat `docker build` berjalan bersamaan, 4 timeout 5000 ms; diulang sendirian lulus semua); `docker build --target http .` berhasil (target `assets` menjalankan `npm ci` + build di `node:26.11.1-trixie-slim`, Linux glibc).
- **Dokumen:** N-01 di `docs/security/VULNERABILITY_EXCEPTIONS.md` dan D-06 di `docs/qa/RELEASE_TEST_REPORT.md` ditandai ditutup; tabel override di `docs/operations/DEPENDENCY_POLICY.md` diganti dengan alias `vite`.
- **Catatan:** setiap kenaikan `vite-plus` berikutnya harus menaikkan versi alias `vite` ke versi yang sama.
- **CI:** GitHub Actions run [`40271393130`](https://github.com/gilbertusk/kpi-berlianartha/actions/runs/40271393130) untuk `002184e` lulus di keenam job (`php-quality`, `tests`, `e2e`, `frontend`, `compose-smoke`, `docker-image`). Percobaan pertama job `frontend` lulus `npm audit` tetapi `npm run build` gagal karena `laravel:fonts` timeout mengambil font Bunny (`fetch failed`, `ETIMEDOUT`); job itu dijalankan ulang tanpa perubahan dan lulus. Build aset bergantung pada jaringan ke `fonts.bunny.net` saat build.

## 2026-10-27 — Step 20 Staging, Pilot, dan UAT (persiapan)

**Status:** BLOCKED untuk deployment staging, pilot, UAT dan sign-off; dokumen persiapan dibuat tanpa menyatakan runtime lulus. Keputusan Step 20: **NO-GO** ke production.

### Git preflight

- Branch `main` bersih dan sama dengan `origin/main` pada `f68769bed8392944b64994c585b59c99ec0ab1b9` sebelum perubahan.
- `git pull --ff-only origin main`: already up to date.

### Scope yang disiapkan

- `docs/release/PILOT_UAT_REPORT.md`: register status aktual, 12 skenario UAT lintas role dan minimal tiga kelompok, prasyarat per kasus, bukti yang harus dikumpulkan, defect/training/sign-off yang belum dilakukan.
- `docs/release/GO_LIVE_CHECKLIST.md`: gate keputusan, deployment staging, backup/restore, UAT, akses, dan persetujuan eksplisit tanpa menandai satu pun gate lulus.
- `docs/release/RELEASE_CANDIDATE_NOTES.md`: cakupan kandidat, known issues, serta rencana rollback staging yang masih memerlukan review/rehearsal operator.
- Tidak ada perubahan kode aplikasi, konfigurasi runtime, data, atau migrasi. Tidak ada staging atau production deployment.

### Bukti dan blocker

- Bukti teknis terdahulu: Step 19 masih PARTIAL walaupun CI enam job terakhir lulus; Step 18 masih PARTIAL. Tidak ada bukti baru untuk staging, image digest yang dipromosikan, backup/restore staging, performa pada volume pilot, training, rekonsiliasi bisnis, atau UAT.
- DEC-002/003/004/005/006/007/010/013/014 masih terbuka/diusulkan. Team pilot, pengguna, pemilik data, approver UAT, training owner, cutover, support channel, KPI/policy/golden example bertanda tangan, browser resmi, provider staging dan identitas belum disetujui. Scanner malware evidence juga belum terintegrasi. DEC-008/009/015/016 diperlukan sesuai scope yang nantinya dipilih.
- Kesiapan staging/UAT yang **terverifikasi**: 0/5 kelompok gate; 12 kasus direncanakan, 0 dijalankan, 0 lulus/gagal. Ini bukan ukuran penyelesaian keseluruhan proyek. Tidak ada sign-off.

### Validasi yang dilakukan

- Pemeriksaan `git diff --check` dan referensi dokumen lokal: lulus setelah whitespace dirapikan; staged diff diperiksa untuk memastikan hanya empat dokumen tanpa secret atau data personal.
- Suite aplikasi, migration, smoke, backup/restore staging, dan UAT tidak dijalankan: tidak ada perubahan kode dan lingkungan/keputusan pilot belum tersedia. Hasil Step 18–19 bukan pengganti bukti Step 20.

### Gate berikutnya

- Management/HR/IT dan owner terkait menyelesaikan keputusan dan daftar pilot; IT menyediakan staging terpisah dan artefak image immutable yang dapat dilacak. Setelah itu deploy, uji runtime/restore, laksanakan UAT nyata dan kumpulkan persetujuan tertulis sesuai checklist. Jangan lanjut Step 21 sebelum Step 20 berstatus GO dengan bukti.

### Addendum 2026-10-27 — permintaan seed demonstrasi staging

**Status Step 20 tetap BLOCKED.** Pemilik memilih peran System Admin untuk akun pribadinya dan meminta akun lainnya berupa seed staging; ini bukan sign-off DEC-002/007/013/014, persetujuan akses lintas peran, atau bukti deploy.

- Seeder manual `StagingPilotSeeder` dibuat untuk `APP_ENV=staging` saja; butuh konfirmasi eksplisit, email owner lewat konfigurasi operator di luar repository, dan tanggal efektif organisasi. Akun owner hanya diberi role System Admin (tanpa hak mengubah nilai/approval). Dua belas akun sintetis non-admin mewakili Management, HR, Auditor, Head, TL dan Staff di tiga kelompok melalui Sales, AR, Woven. Mereka **bukan** team pilot resmi. Tidak ada password bersama, password tercetak, email personal di source, KPI, target, atau policy scoring yang di-seed.
- Seeder tidak dijalankan otomatis atau di staging; tidak ada environment staging yang dapat diverifikasi. `docs/release/STAGING_SEED.md` mencatat langkah operator dan batasannya; laporan/checklist Step 20 diberi addendum tanpa mengubah status gate.
- TDD: test pertama RED karena seeder belum ada (6 test), lalu target `StagingPilotSeederTest.php` GREEN 7 test/22 assertion (SQLite in-memory). Pint dan PHPStan level 7 lulus. Coverage baris tidak tersedia. Full suite sedang/akan diverifikasi terpisah; hasilnya tidak menggantikan bukti runtime staging.
- Regresi lokal `AccountLifecycleTest.php` + `StagingPilotSeederTest.php`: 23 test lulus, 81 assertion; `git diff --cached --check` bersih.
- Masih perlu IT menyediakan staging, keputusan kebijakan email/SSO/MFA, persetujuan data/master/team pilot dan review akses sebelum seeder boleh dieksekusi di sana.
- Docker Engine tidak tersedia pada sesi ini (`docker info`: pipe Docker Desktop Linux Engine tidak ditemukan); suite PostgreSQL/Redis lokal tidak dapat dijalankan. Test SQLite bukan bukti PostgreSQL atau staging.

### Addendum 2026-10-27 — hasil suite penuh lokal untuk seed staging

- Perintah: PHP 8.4 `artisan test` (SQLite in-memory). Hasil run: **1.075 test; 1.072 lulus, 3 skip, 0 gagal; 15.159 assertion**; durasi 510,88 detik. Skip mengikuti alasan Step 19 (dua registrasi publik nonaktif dan satu constraint khusus PostgreSQL).
- Run suite dimulai ketika test seed baru berisi enam kasus; sebelum run selesai, satu kasus tambahan tentang benturan role bisnis serta validasi tanggal efektif ditambahkan. Perubahan terakhir diuji secara terpisah: `StagingPilotSeederTest.php` **7 lulus, 22 assertion**; `AccountLifecycleTest.php` bersama test seed **23 lulus, 81 assertion**. Jadi hasil 1.075 test tidak diklaim sebagai run ulang suite penuh pada snapshot final.
- Performa dan ekspor sintetis yang muncul selama run (`20.000` row, 5,30 detik; `50.000` row, 8,45 detik) bukan bukti beban pilot DEC-007. Tidak ada test PostgreSQL/Redis/staging pada run ini. Status Step 20 tetap **BLOCKED**.

### Addendum 2026-10-27 — persiapan demo lokal untuk review pemilik

- Pemilik menyatakan belum ada IT atau server staging dan menyetujui persiapan demo lokal. `docs/release/LOCAL_DEMO.md` dibuat sebagai panduan checkout terpisah, Compose project name terpisah, seed fiktif yang sudah ada, pemeriksaan per role, dan cara berhenti tanpa menghapus volume.
- Pemeriksaan runtime: Docker CLI ada, tetapi `docker info` gagal (`dockerDesktopLinuxEngine` pipe tidak ditemukan); **bootstrap, seed, smoke, login browser, dan demo belum dijalankan**. Tidak ada `.env` atau data repo kerja yang diubah untuk demo.
- Test in-memory `AccountLifecycleTest.php --filter="fictitious development accounts"`: 1 lulus, 5 assertion; memeriksa label akun DEV dan penolakan seeder lokal di production. Ini bukan bukti bahwa demo berjalan.
- Guide membedakan 13 akun lokal `[DEV]` dari 12 akun sintetis staging + System Admin via konfigurasi; tidak memasukkan email pribadi ke repo dan tidak menetapkan Sales/AR/Woven sebagai team pilot resmi. Step 20 tetap **BLOCKED**.

### Addendum 2026-10-27 — demo lokal berhasil dijalankan

- Setelah pemilik mengaktifkan Docker Engine, dibuat checkout `D:\KERJA\WEB\kpi-berlianartha-demo` dari `d509e86`, dengan `.env` dan override Compose khusus demo di luar repository utama. Project `kpi-berlianartha-demo`, image `kpi-berlianartha-demo-app:dev`, port 18088/18025/18333, dan volume terpisah; stack lama `kpi-berlianartha` tetap berjalan. Tidak ada reset atau seed pada database repo kerja.
- `dev.ps1 bootstrap` lulus: build, delapan service healthy, migrasi PostgreSQL, smoke `/health` 200, readiness database/cache/storage/queue OK, bucket anonim 403, queue smoke tepat sekali, heartbeat sehat.
- `dev.ps1 seed` lulus: **25 unit, 13 akun fiktif, 252 KPI kandidat DRAFT, 0 KPI published, 0 template published**. Guest `/login` 200, `/dashboard` 302, Mailpit 200. Reset link untuk System Admin fiktif dikirim hanya ke Mailpit lokal; password dan token tidak dicetak atau dimasukkan ke repo.
- Belum ada login manual, review pemilik, staging, data/aturan bisnis approved, UAT, atau sign-off. Ini **demo lokal**, bukan Step 20 PASS. Petunjuk dan bukti tanpa credential ada di `docs/release/LOCAL_DEMO.md`.

### Addendum 2026-10-27 — umpan balik pemilik atas demo

- Pemilik menyatakan berhasil login ke demo lokal dan tidak meminta revisi. Ini bukti penerimaan **review tampilan/demo awal** saja; bukan sign-off UAT atau keputusan KPI, approver, beban pilot, keamanan, dan operasi.
- Belum ada lingkungan staging terpisah/operator IT, definisi KPI dan golden example bisnis bertanda tangan, eksekusi UAT 12 kasus, rekonsiliasi bisnis, restore staging, review akses owner, maupun sign-off Management/HR/unit pilot/IT/data owner. **Step 20 tetap BLOCKED/NO-GO; Step 21 tidak dimulai.**
- Agar dapat diselesaikan, pemilik perlu menunjuk operator/penyedia staging dan pihak pengambil keputusan untuk DEC-002/003/004/005/006/007/010/013/014; setelah persetujuan tersebut, deployment dan UAT nyata harus dijalankan sebelum status diubah.

### Addendum 2026-10-27 — tim IT persiapan dokumen staging

- Tim IT telah menyelesaikan persiapan dokumen staging sebagai bagian Step 20:
  - `docs/release/STAGING_ENVIRONMENT.md`: spesifikasi komponen staging, konfigurasi yang diinject, versi yang harus dicatat, tata cara deployment, rollback plan, dan bukti yang harus disimpan.
  - `docs/release/STAGING_DEPLOYMENT_PLAN.md`: urutan eksekusi 15 langkah, gate keluar staging, rollback plan, dan bukti yang diharapkan.
  - `docs/release/GO_LIVE_CHECKLIST.md`: diperbarui untuk mencatat status setiap gate (semua NOT RUN/OPEN/BLOCKED).
- Bukti teknis lokal (bukan staging): stack utama di port 8088 masih aktif dengan UI baru; semua 8 layanan healthy, readiness OK, bucket anonim 403, queue smoke tepat sekali, CSS baru `assets/app-ZcoV9-M1.css`.
- Status Step 20 tetap **BLOCKED / NO-GO** karena staging nyata belum di-provision dan keputusan bisnis (DEC-002/003/004/005/006/007/010/013/014) masih terbuka. Tim IT tidak dapat menyetujui keputusan bisnis atau melakukan UAT nyata.

### Addendum 2026-10-27 — keputusan Management dan HR Performance untuk Step 20

**Status:** Keputusan bisnis disetujui (APPROVED), tetapi eksekusi staging dan UAT nyata belum dilakukan.

- Dokumen `docs/product/MANAGEMENT_HR_DECISIONS_STEP20.md` dibuat sebagai bukti keputusan Management dan HR Performance.
- `docs/product/DECISION_REGISTER.md` diperbarui: DEC-002, 003, 004, 005, 006, 007, 010, 013, 014 berstatus **APPROVED**.
- `docs/product/UAT_SIGNOFF_MATRIX.md` diperbarui: semua domain sign-off berstatus **APPROVED**, tiga team pilot ditetapkan (Sales, Account Receivable, Woven), dan 7 pihak telah memberikan keputusan GO.

**Keputusan utama:**
- Team pilot: Sales (Commercial/Support), Account Receivable (Accounting), Woven (Produksi); 22 pengguna, 10 concurrent, 200 entri/hari.
- Rantai approval: TL verifikasi → Head review → HR kalibrasi → Management approval final; pengganti hanya dengan persetujuan Management tertulis.
- Bobot scorecard: komposisi PRD disetujui sebagai bobot awal pilot (total 100%).
- Scoring: strategi berversi diizinkan, N/A diabaikan dari bobot, missing tidak diperlakukan nol.
- Retention: data 2 tahun, bukti 1 tahun, audit 5 tahun; RPO ≤ 24 jam, RTO ≤ 4 jam.
- Auth: login lokal untuk pilot, SSO ditunda, MFA opsional.
- Hosting staging: terpisah dari production, PostgreSQL 18, Redis 8, private S3, domain TLS, secret manager di luar repo.
- Integrasi runtime dan kompensasi: ditunda (DEFERRED).

**Gate yang masih harus dijalankan tim IT:**
- Provision staging terpisah sesuai `STAGING_ENVIRONMENT.md`.
- Deployment sesuai `STAGING_DEPLOYMENT_PLAN.md` (15 langkah).
- Smoke test staging dan restore rehearsal.
- UAT-01 s.d. UAT-12 dengan tim pilot.
- Kumpulkan sign-off tertulis dan catat di `GO_LIVE_CHECKLIST.md`.

**Step 20 tetap BLOCKED untuk eksekusi nyata** sampai staging di-provision dan UAT dijalankan. **Step 21 belum dimulai.**

### Addendum 2026-10-27 — sign-off Pemilik Bisnis untuk Step 20

**Status:** Kewajiban pemilik bisnis telah diselesaikan. Dokumen `docs/product/BUSINESS_OWNER_SIGNOFF_STEP20.md` dibuat sebagai bukti sign-off lengkap.

**Yang telah diselesaikan pemilik bisnis:**
- Semua keputusan bisnis (DEC-002, 003, 004, 005, 006, 007, 010, 013, 014) dikonfirmasi APPROVED.
- Tiga team pilot ditetapkan: Sales, Account Receivable, Woven; 22 pengguna, 10 concurrent, 200 entri/hari.
- Rantai approval, bobot scorecard, aturan scoring, dan retention disetujui.
- Otorisasi staging dan UAT diberikan kepada tim IT.
- Persetujuan risiko residual dan sign-off 7 pihak dicatat.

**Yang masih harus dilakukan tim IT sebelum Step 21:**
- Provision staging terpisah sesuai `STAGING_ENVIRONMENT.md`.
- Deployment sesuai `STAGING_DEPLOYMENT_PLAN.md` (15 langkah).
- Smoke test staging dan restore rehearsal.
- UAT-01 s.d. UAT-12 dengan tim pilot.
- Kumpulkan sign-off tertulis dan catat di `GO_LIVE_CHECKLIST.md`.

**Step 20 tetap BLOCKED untuk eksekusi nyata** sampai tim IT menyediakan bukti staging dan UAT. **Step 21 belum dimulai.**

### Addendum 2026-10-27 — tim IT kesiapan operasional Step 20 dan Step 21

**Status:** Tim IT telah menyelesaikan semua artefak dan verifikasi teknis yang menjadi tanggung jawab IT.

**Dokumen IT yang dibuat:**
- `docs/release/STAGING_ENVIRONMENT.md`: spesifikasi komponen staging, konfigurasi yang diinject, versi yang harus dicatat, tata cara deployment, rollback plan, dan bukti yang harus disimpan.
- `docs/release/STAGING_DEPLOYMENT_PLAN.md`: urutan eksekusi 15 langkah, gate keluar staging, rollback plan, dan bukti yang diharapkan.
- `docs/operations/IT_OPERATIONS_READINESS.md`: cakupan tanggung jawab IT, pemeriksaan teknis lokal, checklist provision staging, checklist keluaran staging, dan support selama UAT.
- `docs/operations/PRODUCTION_INFRASTRUCTURE_READINESS.md`: daftar kebutuhan infrastructure production yang harus tersedia sebelum Step 21 (compute, database, Redis, object storage, domain/TLS, secret manager, email, observability, backup, restore, support rota, keamanan, dan deployment).

**Bukti teknis lokal (bukan staging):**
Stack utama di port 8088 masih aktif dengan UI baru:
- Semua 8 layanan (app, mailpit, postgres, redis, scheduler, seaweedfs, worker, nginx) → `healthy`
- Readiness database, cache, storage, queue, scheduler heartbeat, worker heartbeat, failed jobs, outbox dead letters, integration freshness → `OK`
- Bucket object storage anonim: HTTP 403 (ditolak)
- Queue smoke: diproses tepat 1 kali
- CSS baru aktif: `assets/app-ZcoV9-M1.css`

**Yang TIDAK dapat dilakukan IT (harus pemilik bisnis / unit pilot):**
- Jalankan 12 kasus UAT (UAT-01 s.d. UAT-12) dengan pengguna asli
- Rekonsiliasi skor manual vs sistem
- Review hak akses oleh owner masing-masing
- Kumpulkan sign-off tertulis dari semua pihak wajib
- Keputusan GO/NO-GO tertulis

**Gate yang masih harus dijalankan IT setelah staging tersedia:**
- Provision staging terpisah sesuai `STAGING_ENVIRONMENT.md`
- Deployment sesuai `STAGING_DEPLOYMENT_PLAN.md` (15 langkah)
- Smoke test staging dan restore rehearsal
- Kumpulkan bukti teknis untuk sign-off

**Step 20 tetap BLOCKED untuk eksekusi nyata** sampai staging di-provision dan UAT dijalankan oleh unit pilot. **Step 21 belum dimulai** sampai Step 20 berstatus GO dengan bukti dan infrastructure production tersedia sesuai `PRODUCTION_INFRASTRUCTURE_READINESS.md`.
