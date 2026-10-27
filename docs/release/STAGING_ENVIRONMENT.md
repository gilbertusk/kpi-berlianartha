# Staging Environment Specification — Step 20

**Status:** Disiapkan oleh tim IT, belum di-provision ke penyedia staging nyata. Dokumen ini adalah spesifikasi teknis yang harus dipenuhi sebelum deployment staging dan UAT.

## 1. Prinsip isolasi

Staging harus **terpisah secara fisik/logis dari production**. Tidak boleh ada koneksi database, object storage, atau secret yang sama dengan production. Data yang digunakan hanya data sintetis atau data pilot yang telah disanitasi dan disetujui pemilik data.

## 2. Komponen wajib

| Komponen | Spesifikasi minimum | Catatan |
|---|---|---|
| Compute | 2 vCPU, 4 GB RAM, SSD | Untuk web, worker, dan scheduler |
| Database | PostgreSQL 18, terpisah dari production | `kpi_staging`, user aplikasi terpisah |
| Cache/Queue | Redis 8, terpisah dari production | DB 13/14 untuk cache dan queue |
| Object storage | Private S3-compatible, bucket `kpi-staging-*` | Tidak boleh publik, akses via signed URL |
| Email | Mailpit atau SMTP test, safe recipient only | Tidak boleh mengirim email ke pelanggan asli |
| Domain/TLS | `staging.kpi.example.com` (contoh), TLS valid | Disediakan IT, bukan `localhost` |
| Secret manager | Secret disimpan di luar kode/repo | `APP_KEY`, DB password, S3 credential, dll |
| Observability | Log, metrik, dan alert dasar | Health check, readiness, queue age |
| Backup | Snapshot harian + sebelum deploy | Restore rehearsal wajib sebelum UAT |
| Antivirus scanner | Produk terpilih dan terintegrasi | Evidence tidak boleh diverifikasi tanpa scanner |

## 3. Konfigurasi yang diinject (bukan di commit)

Variabel berikut harus diisi melalui secret manager atau environment staging, **tidak boleh ada di repository**:

- `APP_KEY`
- `DB_PASSWORD`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`
- `REDIS_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- `STAGING_PILOT_ADMIN_EMAIL`
- `STAGING_PILOT_SEED_CONFIRM`
- `EVIDENCE_SCANNER` (tidak boleh `local-unscanned` di staging)

## 4. Versi yang harus dicatat sebelum deploy

| Item | Nilai saat persiapan | Bukti yang diperlukan |
|---|---|---|
| Git SHA | `ecaa14d90125f54b1697e53653ee2346afbc6f5d` | CI log untuk SHA ini |
| Image app digest | Belum tersedia | Digest dari `docker build --target production` |
| Image http digest | Belum tersedia | Digest dari `docker build --target http` |
| Versi migrasi | Terakhir `2026_10_16_001500` | `php artisan migrate --status` |
| Versi konfigurasi | Belum ditentukan | Hash konfigurasi staging |
| Waktu deploy | Belum ditentukan | Catatan waktu deploy nyata |

## 5. Prasyarat sebelum deployment staging

- [ ] DEC-014 disetujui (hosting, domain/TLS, secret manager, email)
- [ ] DEC-013 disetujui (auth provider, MFA kebijakan)
- [ ] DEC-010 disetujui (browser resmi, retensi, RPO/RTO)
- [ ] DEC-007 disetujui (team pilot, pengguna, volume)
- [ ] DEC-002/003/004/005/006 disetujui (approver, bobot, scoring, KPI)
- [ ] Staging environment terprovision dan terisolasi dari production
- [ ] Image immutable dari CI dengan digest tercatat
- [ ] Vulnerability scan (Trivy) lulus untuk image staging
- [ ] Konfigurasi staging diverifikasi tanpa nilai secret di repo
- [ ] Recovery point/database backup sebelum migrasi

## 6. Tata cara deployment staging

1. Verifikasi SHA dan CI untuk commit yang dipilih.
2. Buat backup database staging sebagai recovery point.
3. Deploy image immutable (app dan http) dengan digest yang sama.
4. Jalankan `php artisan migrate --force` **sekali saja**, pantau durasi dan lock.
5. Restart web, worker, dan scheduler dengan versi yang sama.
6. Jalankan smoke test: health, readiness, queue tepat sekali, scheduler, object private, notifikasi safe recipient.
7. Jalankan restore rehearsal staging pada volume representatif.
8. Catat waktu deploy, aktor, dan versi konfigurasi.

## 7. Rollback staging

- Jangan gunakan `migrate:rollback` atau `reset-db` sebagai rollback staging.
- Bila schema kompatibel, kembalikan image immutable sebelumnya.
- Bila schema tidak kompatibel, gunakan forward-fix ter-review atau restore dari snapshot terisolasi atas keputusan pemilik data.
- Catat waktu mulai/selesai, penyebab, versi akhir, dan hasil verifikasi.

## 8. Bukti yang harus disimpan

- CI log untuk SHA staging
- Image digest app dan http
- Hasil `php artisan migrate --status`
- Output smoke test staging
- Laporan restore rehearsal staging (RPO/RTO terukur)
- Catatan deploy (waktu, aktor, versi konfigurasi)
- Hasil vulnerability scan image staging