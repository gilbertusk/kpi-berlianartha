# Prompt Step 02 — Bootstrap Aplikasi Laravel

Anda bertindak sebagai Senior Laravel Engineer. Bangun skeleton aplikasi sesuai arsitektur Step 01. Scope step ini hanya bootstrap yang dapat dijalankan, bukan implementasi domain KPI.

## Prasyarat

Baca PRD, decision register, seluruh ADR, backlog, test strategy, dan `PROJECT_PROGRESS.md`. Pertahankan file pengguna yang sudah ada. Jangan mengganti keputusan stack.

## Pekerjaan

1. Inisialisasi Laravel 13 dengan PHP 8.4 dan starter kit Livewire resmi yang kompatibel.
2. Gunakan Blade, Livewire, Alpine.js, Tailwind CSS, dan Vite. Jangan menambahkan React, Vue, Next.js, atau frontend repository terpisah.
3. Susun struktur modular sesuai `DOMAIN_BOUNDARIES.md`. Tambahkan namespace/domain skeleton seperlunya tanpa membuat abstraksi kosong berlebihan.
4. Konfigurasikan environment untuk PostgreSQL, Redis, queue, mail, dan S3-compatible filesystem melalui environment variable.
5. Buat halaman dasar: login placeholder/starter, authenticated shell, navigation placeholder berbasis role, unauthorized page, error page, dan `/health` yang tidak membocorkan rahasia.
6. Buat design token dasar, layout desktop/tablet, accessible form/error component, status badge, table shell, empty/loading/error state.
7. Pasang dan konfigurasikan Pest. Tambahkan smoke test untuk bootstrap, health endpoint, halaman publik, dan protected route.
8. Tambahkan Laravel Pint dan static analysis yang kompatibel. Jangan mematikan rule hanya untuk membuat pipeline hijau.
9. Buat `.env.example` tanpa secret, README setup awal, dan command development yang reproducible.
10. Pastikan generated key, credential, dan file runtime tidak masuk Git.
11. Perbarui `PROJECT_PROGRESS.md` secara append-only.

## Guardrail

- Jangan membuat tabel KPI atau business workflow pada step ini.
- Jangan hard-code akun, password, approver, bobot, atau formula.
- Jangan menyatakan Docker atau layanan eksternal berhasil jika belum dijalankan.
- Hindari package tambahan jika kemampuan Laravel bawaan mencukupi; catat alasan setiap dependency baru.

## Acceptance criteria

- Aplikasi dapat di-install dari checkout bersih menggunakan instruksi README.
- Asset frontend dapat dibangun.
- Test smoke lulus.
- Halaman protected tidak dapat diakses tanpa authentication.
- Konfigurasi memakai environment variable dan tidak menyimpan secret.
- Struktur kode konsisten dengan ADR.

## Validasi wajib

Jalankan setidaknya dependency audit yang tersedia, formatter check, static analysis, unit/feature smoke test, dan frontend production build. Catat perintah, exit code, serta hasil aktual. Jika environment tidak memungkinkan, tandai sebagai blocker—jangan mengarang hasil.

## Laporan akhir

Sebutkan file penting, dependency yang ditambahkan, command setup, hasil validasi, technical debt, dan status `PASS/PARTIAL/BLOCKED`. Jangan lanjut ke domain feature.
