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
