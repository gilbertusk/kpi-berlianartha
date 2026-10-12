# Prompt Step 03 — Infrastruktur Lokal dan CI

Anda bertindak sebagai Platform/DevOps Engineer. Buat lingkungan development dan continuous integration yang reproducible untuk skeleton Laravel yang sudah lulus Step 02.

## Prasyarat

Baca architecture, release strategy, README, `.env.example`, serta `PROJECT_PROGRESS.md`. Verifikasi aplikasi lokal sebelum mengubah infrastruktur.

## Pekerjaan

1. Buat multi-stage Dockerfile yang menghasilkan image production untuk Laravel. Image yang sama harus dapat menjalankan web, queue worker, dan scheduler melalui command berbeda.
2. Buat Docker Compose development dengan service:
   - Nginx;
   - PHP-FPM/Laravel web;
   - queue worker/Horizon;
   - scheduler;
   - PostgreSQL;
   - Redis;
   - S3-compatible storage lokal;
   - mail catcher lokal bila dibutuhkan.
3. Gunakan named volume untuk data lokal dan healthcheck yang bermakna. Jangan expose PostgreSQL/Redis ke interface publik secara default.
4. Pisahkan konfigurasi development, test, dan production. Jangan memasukkan secret ke image atau repository.
5. Tambahkan scripts/commands untuk bootstrap, migrate, seed development, test, lint, static analysis, build asset, queue check, dan reset **khusus database lokal** dengan konfirmasi yang aman.
6. Buat GitHub Actions yang menjalankan dependency install, security audit, formatting, static analysis, unit/feature test dengan PostgreSQL/Redis, serta frontend build. Gunakan cache secara aman.
7. Buat policy migration: forward-compatible, review SQL, backup sebelum production, dan tidak menjalankan destructive migration tanpa rencana data.
8. Tambahkan dependency update policy dan software bill of materials/image scan jika tooling tersedia.
9. Dokumentasikan Windows development, Linux deployment, port, volume, troubleshooting, serta cara membuktikan service sehat.
10. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- `docker compose up` membangun lingkungan dari checkout bersih.
- Web, PostgreSQL, Redis, object storage, worker, dan scheduler mempunyai health evidence.
- Queue job percobaan dapat diproses sekali saja.
- Persistent data tetap ada setelah container restart.
- CI menjalankan gate nyata, bukan script kosong/no-op.
- Build image tidak membawa `.env`, test artifact sensitif, atau development dependency yang tidak diperlukan.

## Validasi wajib

Jalankan build image, start environment, migrate test database, smoke HTTP request, queue smoke job, test suite, static analysis, dan production asset build. Inspeksi log untuk error tersembunyi. Jika Docker/runtime tidak tersedia, dokumentasikan command yang belum terbukti dan jangan memberi status penuh.

## Laporan akhir

Laporkan service, port, volume, health result, CI jobs, ukuran image, risiko, dan status `PASS/PARTIAL/BLOCKED`.
