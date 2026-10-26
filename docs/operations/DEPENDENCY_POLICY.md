# Dependency Update Policy

**Status:** Baseline Step 03 — 12 Oktober 2026

## 1. Sumber dependency

| Ekosistem | Lockfile/pin | Pembaruan otomatis |
|---|---|---|
| Composer | `composer.lock` | Dependabot mingguan (minor/patch dikelompokkan) |
| npm | `package-lock.json` | Dependabot mingguan (minor/patch dikelompokkan) |
| GitHub Actions | dipin ke commit SHA (komentar versi) | Dependabot mingguan |
| Docker base image | tag versi eksak pada baris `FROM` `Dockerfile` dan `compose.yaml` | Dependabot `docker` dan `docker-compose` |
| Image service CI (`postgres`, `redis`) dan `TRIVY_IMAGE` di `ci.yml` | tag versi eksak | **Manual**: samakan dengan `compose.yaml` setiap kali Dependabot menaikkan image Compose |

Konfigurasi: `.github/dependabot.yml`.

## 2. Aturan

1. Setiap dependency baru harus mempunyai alasan tertulis (PR/`PROJECT_PROGRESS.md`). Utamakan kemampuan bawaan Laravel.
2. Versi dipin eksak untuk image dan action; tag bergerak seperti `latest` tidak dipakai.
3. Pembaruan **patch/minor** dapat digabung setelah CI hijau.
4. Pembaruan **major** (framework, PHP, PostgreSQL, Redis, Node) memerlukan catatan upgrade, review changelog/breaking change, dan pengujian di lingkungan Compose.
5. **Keamanan:**
   - `composer audit` dan `npm audit --audit-level=high` harus lulus di CI;
   - Trivy memblokir image dengan CVE **CRITICAL/HIGH yang sudah memiliki fix**; CRITICAL/HIGH yang belum memiliki fix ditampilkan (tidak memblokir) di langkah CI terpisah dan wajib tercatat di `docs/security/VULNERABILITY_EXCEPTIONS.md` (Step 18);
   - advisory Critical/High ditangani maksimal 7 hari kalender; Medium dijadwalkan pada pembaruan berikutnya;
   - pengecualian sementara wajib dicatat (ID advisory, alasan, mitigasi, pemilik, tanggal kedaluwarsa) di PR, `PROJECT_PROGRESS.md`, dan `docs/security/VULNERABILITY_EXCEPTIONS.md`.
6. Image dibangun dengan `apt-get upgrade`/`apk upgrade` agar perbaikan keamanan OS tidak menunggu rilis image upstream. Image `production` membuang toolchain compiler base image dan hanya menyimpan library yang dipakai PHP (Step 18). Konsekuensinya, build tidak sepenuhnya bit-for-bit reproducible; artifact yang dipromosikan diidentifikasi dengan digest image (`RELEASE_STRATEGY.md`).

## 3. Pengecualian/override yang aktif

| Item | Alasan | Tinjau ulang |
|---|---|---|
| npm `overrides.vite = npm:@voidzero-dev/vite-plus-core@<versi vite-plus>` (dan `dependencies.vite` dengan alias yang sama) | `vite-plus` 1.x mewajibkan `vite` milik plugin (`laravel-vite-plugin`, `@tailwindcss/vite`) di-resolve ke core-nya; tanpa alias, `vp build` gagal ("Expected @voidzero-dev/vite-plus-core …, but found vite …"). Override `tinypool` 2.1.2 dihapus 26 Oktober 2026 karena `oxfmt` 0.74.0 sudah membawa `tinypool` 2.2.0 | Setiap kenaikan `vite-plus`: versi alias harus sama dengan versi `vite-plus` |
| Composer `extra.aws/aws-sdk-php = ["S3"]` + `pre-autoload-dump` | Membuang service AWS SDK yang tidak dipakai (vendor AWS 76 MB → 5 MB) | Bila layanan AWS lain dibutuhkan |

### Dependency yang ditambahkan setelah baseline

| Paket | Step | Alasan |
|---|---|---|
| `dompdf/dompdf` | 12 | Ekspor laporan PDF (prompt Step 12). Murni PHP, tanpa binary eksternal. Dipakai dengan resource remote, PHP, dan JavaScript dimatikan, dan hanya untuk laporan sampai 500 baris |
| `brick/math` | 09 | Aritmetika desimal dan rasional eksak untuk mesin skor (ADR-0008 melarang float). Paket ini sudah menjadi dependency Laravel; dicantumkan eksplisit karena kini dipakai langsung |
| `openspout/openspout` | 08 | Membaca file XLSX pada import aktual secara streaming. Laravel tidak memiliki pembaca spreadsheet bawaan; paket ini ringan dan tidak membutuhkan ekstensi tambahan |

## 4. SBOM dan scanning

- Job CI `docker-image` membuat SBOM CycloneDX (`trivy image --format cyclonedx`) untuk image aplikasi dan nginx, diunggah sebagai artifact `sbom-<sha>` (retensi 30 hari).
- Scan vulnerability memakai `aquasec/trivy:0.74.0`; tidak memakai action pihak ketiga yang tidak dipin.
