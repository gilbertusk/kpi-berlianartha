# Prompt Claude Code — Step 02 Bootstrap Aplikasi Laravel

Anda adalah Senior Laravel Engineer yang bekerja langsung pada repository Sistem KPI PT Berlian Artha Label.

## Lokasi dan remote

- Workspace Windows: `D:\KERJA\WEB\kpi-berlianartha`
- Repository GitHub: `https://github.com/gilbertusk/kpi-berlianartha.git`
- Branch target: `main`
- Commit step ini: `step-02: bootstrap Laravel application`

Kerjakan seluruh tugas sampai selesai, termasuk validasi, update progress, commit, push, dan verifikasi SHA remote. Jangan berhenti setelah hanya membuat rencana.

## 1. Baca konteks sebelum mengubah file

Baca seluruh file berikut terlebih dahulu:

1. `AGENTS.md`
2. `README.md`
3. `PROJECT_PROGRESS.md`
4. `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx`; jika DOCX tidak dapat dibaca, gunakan `scripts/build_prd.py`
5. `docs/product/DECISION_REGISTER.md`
6. `docs/product/ROLE_APPROVAL_MATRIX.md`
7. `docs/architecture/SOLUTION_ARCHITECTURE.md`
8. `docs/architecture/DOMAIN_BOUNDARIES.md`
9. seluruh `docs/architecture/adr/*.md`
10. `docs/implementation/BACKLOG.md`
11. `docs/implementation/TEST_STRATEGY.md`
12. `docs/implementation/RELEASE_STRATEGY.md`
13. `docs/security/THREAT_MODEL.md`
14. `docs/security/DATA_CLASSIFICATION.md`
15. `prompts/ai-development/02-bootstrap-aplikasi-laravel.md`

Dokumen dan perubahan pengguna yang sudah ada harus dipertahankan.

## 2. Git preflight wajib

Sebelum coding:

1. Jalankan `git status --short`, `git branch --show-current`, `git remote -v`, dan `git log --oneline -5`.
2. Pastikan remote `origin` mengarah ke repository di atas.
3. Pastikan tidak ada secret, `.env`, credential, token, atau data sensitif yang akan masuk commit.
4. Periksa apakah perubahan Step 00 dan Step 01 sudah mempunyai commit terpisah dan sudah berada di `origin/main`.
5. Bila Step 00/01 masih berupa perubahan lokal, jangan menghapus atau menimpanya. Commit keduanya secara terpisah sesuai scope dan message berikut, lalu push berurutan:
   - `step-00: add product governance decision artifacts`
   - `step-01: define architecture and implementation plan`
6. Sinkronkan dengan `git pull --ff-only origin main`. Jangan melakukan rebase, force-push, reset hard, atau overwrite perubahan pengguna.
7. Catat SHA awal Step 02.

Jika terjadi conflict, authentication failure, branch protection, atau remote divergence, berhenti dari coding dan laporkan bukti blocker. Jangan menggunakan force-push.

## 3. Stack yang dikunci

- Laravel 13
- PHP 8.4
- Blade
- Livewire
- Alpine.js
- Tailwind CSS
- Vite
- PostgreSQL 18 sebagai target database
- Redis sebagai target cache/queue
- S3-compatible object storage sebagai target filesystem evidence
- Pest untuk unit/feature test
- Playwright baru disiapkan pada step testing yang relevan; jangan membangun suite E2E besar sekarang

Jangan menambahkan Next.js, React SPA, Vue SPA, NestJS, microservices, Kubernetes, GraphQL, atau frontend repository terpisah.

## 4. Pemeriksaan environment

Periksa dan catat versi aktual:

```powershell
php --version
composer --version
node --version
npm --version
git --version
```

Pastikan PHP memenuhi kebutuhan Laravel 13. Gunakan package manager dan installer resmi. Jika runtime wajib tidak tersedia atau versinya tidak kompatibel, jangan diam-diam mengganti stack; dokumentasikan blocker dengan command output yang relevan.

## 5. Bootstrap aman pada repository non-kosong

Repository sudah berisi PRD, prompt, dan dokumentasi. Jangan menjalankan installer yang menghapus atau menimpa file tersebut.

Gunakan pendekatan aman berikut:

1. Buat Laravel 13 di temporary directory di dalam workspace atau OS temp.
2. Periksa generated files sebelum merge.
3. Pindahkan/merge skeleton Laravel ke root repository tanpa menimpa:
   - `AGENTS.md`
   - root `README.md` tanpa review manual
   - `PROJECT_PROGRESS.md`
   - `docs/`
   - `prompts/`
   - `output/`
   - `scripts/`
   - `.git/`
   - `.gitignore` tanpa merge aturan existing
4. Gabungkan `.gitignore` dan README secara sadar. Jangan membuang aturan project yang sudah ada.
5. Bersihkan temporary directory hanya setelah target diperiksa dan tidak ada file penting di dalamnya.

Gunakan official Livewire starter kit yang kompatibel dengan Laravel 13. Jika nama installer/package berubah, verifikasi melalui dokumentasi Laravel resmi atau help command lokal; jangan menebak command atau memasang starter kit lama yang sudah deprecated.

## 6. Scope implementasi Step 02

### 6.1 Skeleton aplikasi

- Laravel dapat dijalankan dari root repository.
- Gunakan struktur standar Laravel dan namespace modular sesuai `DOMAIN_BOUNDARIES.md`.
- Buat module/domain skeleton hanya bila mempunyai tujuan nyata. Jangan membuat puluhan class kosong.
- Controller dan Livewire component tetap tipis; business logic nanti berada di domain/application service.

### 6.2 Frontend foundation

- Blade, Livewire, Alpine.js, Tailwind CSS, dan Vite berfungsi.
- Buat authenticated application shell yang sederhana dan profesional.
- Buat navigation placeholder untuk Dashboard, KPI, Approval, Reports, dan Administration tanpa mengimplementasikan hak akses/domain yang menjadi scope Step 05+.
- Buat reusable UI primitives minimum:
  - button;
  - input dan label;
  - validation error;
  - alert;
  - status badge;
  - page header;
  - table shell;
  - empty state;
  - loading state;
  - error state.
- Layout harus responsif untuk desktop dan tablet serta mempunyai keyboard focus yang terlihat.

### 6.3 Halaman dan route dasar

- Landing/login dari starter kit.
- Authenticated dashboard placeholder yang menyatakan data KPI belum dikonfigurasi.
- Halaman unauthorized/403.
- Halaman 404 dan 500 production-safe.
- Endpoint `GET /health` yang mengembalikan status aplikasi secara minimum tanpa membocorkan environment, secret, credential, stack trace, atau konfigurasi sensitif.

Health check database/Redis/storage yang mendalam adalah scope Step 03/18; jangan membuat endpoint publik yang menampilkan detail dependency.

### 6.4 Configuration foundation

Siapkan `.env.example` tanpa secret dengan konfigurasi target:

- `APP_TIMEZONE=Asia/Jakarta` untuk presentation/business configuration;
- PostgreSQL sebagai database target;
- Redis untuk cache dan queue target;
- S3-compatible filesystem variables;
- mail variables;
- safe default untuk local development.

Timestamp persistence dan schema conventions diselesaikan pada Step 04. Jangan membuat tabel domain KPI pada step ini.

### 6.5 Testing dan quality tools

- Pastikan Pest terpasang dan berfungsi.
- Tambahkan smoke test untuk:
  - aplikasi boot;
  - `/health` berhasil;
  - guest dapat membuka halaman login/landing yang sesuai;
  - protected dashboard menolak guest;
  - authenticated test user dapat membuka dashboard placeholder;
  - health response tidak memuat key/secret/config sensitif.
- Pastikan Laravel Pint tersedia.
- Tambahkan static analysis yang kompatibel dengan Laravel 13, misalnya Larastan/PHPStan, dengan konfigurasi yang realistis.
- Jangan menonaktifkan rule atau mengabaikan error secara luas hanya agar pipeline hijau.

### 6.6 Dokumentasi developer

Perbarui root `README.md` dengan:

- prerequisites;
- installation commands;
- environment setup;
- key generation;
- database setup target;
- frontend asset commands;
- development server command;
- test, format, static analysis, dan build commands;
- batasan bahwa Docker/CI lengkap baru Step 03;
- status bahwa business modules belum diimplementasikan.

Buat atau perbarui dokumentasi struktur aplikasi bila diperlukan.

## 7. Larangan keras

- Jangan membuat migration/table KPI, organization, approval, score, target, atau actual pada Step 02.
- Jangan mengaktifkan kandidat KPI dari PRD.
- Jangan mengarang bobot, formula, rating, target, approver, SLA, atau data seed bisnis.
- Jangan menyimpan secret atau generated app key ke Git.
- Jangan mengganti PostgreSQL dengan MySQL sebagai keputusan project.
- Jangan mengklaim PostgreSQL/Redis/S3/Docker berjalan jika belum dibuktikan.
- Jangan menghapus dokumen, prompt, PRD, atau perubahan pengguna.
- Jangan mulai Step 03.

## 8. Validasi wajib

Jalankan secara berurutan dan catat command serta exit code aktual:

1. `composer validate --strict`
2. dependency/security audit Composer yang tersedia
3. dependency/security audit npm yang tersedia
4. Laravel Pint dalam check/test mode
5. static analysis
6. `php artisan test`
7. `npm run build`
8. `php artisan route:list`
9. pemeriksaan bahwa `.env`, `vendor/`, `node_modules/`, build temp, test report, dan secret tidak staged

Jika command berbeda karena script project, gunakan script yang ekuivalen dan dokumentasikan. Jangan menyatakan PASS bila command gagal atau tidak dijalankan.

## 9. Update progress

Append—jangan overwrite—`PROJECT_PROGRESS.md` dengan bagian:

```text
YYYY-MM-DD — Step 02 Bootstrap Aplikasi Laravel
Status: PASS / PARTIAL / BLOCKED
Scope selesai
File/package penting
Command validasi dan hasil aktual
Keputusan/asumsi
Blocker
Gate berikutnya
```

Status hanya `PASS` bila install dari root, tests, static analysis, dan production asset build lulus. Runtime eksternal yang belum tersedia harus dilaporkan terpisah dan tidak boleh diklaim.

## 10. Review, commit, dan push

Sebelum commit:

1. Jalankan `git status --short`.
2. Jalankan `git diff --check`.
3. Tinjau `git diff` dan `git diff --cached`.
4. Pastikan tidak ada secret, `.env`, credential, personal data, generated cache, `vendor`, atau `node_modules`.
5. Pastikan perubahan hanya Step 02 dan preservasi dokumen existing.

Commit dan push:

```powershell
git add .
git commit -m "step-02: bootstrap Laravel application"
git push origin main
```

Setelah push, verifikasi:

```powershell
$localSha = git rev-parse HEAD
$remoteSha = (git ls-remote origin refs/heads/main).Split("`t")[0]
Write-Host "Local : $localSha"
Write-Host "Remote: $remoteSha"
if ($localSha -ne $remoteSha) { throw "SHA lokal dan remote berbeda" }
git status --short
```

Jangan menyatakan push berhasil sebelum SHA sama. Working tree harus bersih, kecuali ada perubahan pengguna yang sejak awal sengaja tidak masuk scope; jika ada, sebutkan secara eksplisit.

## 11. Format laporan akhir Claude Code

Berikan laporan singkat tetapi berbukti:

1. Status Step 02: `PASS`, `PARTIAL`, atau `BLOCKED`.
2. Ringkasan hasil dan architecture compliance.
3. File/package utama yang ditambahkan.
4. Semua command validasi beserta hasil aktual.
5. Hal yang sengaja belum dikerjakan karena berada di Step 03+.
6. Commit SHA dan hasil verifikasi SHA remote.
7. Working-tree status.
8. Blocker dan next step yang diizinkan.
