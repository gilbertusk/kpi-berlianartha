# Backup dan Restore

**Status:** Step 18 — 16 Oktober 2026. Backup terenkripsi PostgreSQL + object storage dan restore rehearsal ke lingkungan terisolasi **terbukti di lingkungan lokal** (Docker Compose, data sintetis). **Penerimaan DR BLOCKED**: target RPO/RTO, retention, dan lokasi backup offsite belum diputuskan (DEC-010, DEC-014), dan pengukuran belum dilakukan pada volume data produksi.

## 1. Apa yang di-backup

| Komponen | Cara | Isi |
|---|---|---|
| PostgreSQL | `pg_dump -Fc` dari container `postgres` | Seluruh skema dan data database aplikasi (termasuk audit log, session, outbox, metadata objek) |
| Object storage (bukti, ekspor, file integrasi di disk `s3`) | `php artisan ops:storage-snapshot` | Setiap objek + `MANIFEST.json` (path, ukuran, SHA-256) |
| Data verifikasi | query saat backup | jumlah baris per tabel (`table-counts.txt`), MD5 tabel kunci (`checksums.txt`, definisi di `scripts/dr/checksums.sql`), jumlah dan nama migrasi terakhir (`migration.txt`) |

Tidak di-backup oleh skrip ini (dikelola terpisah): secret environment, kunci backup, Redis (cache/antrean, dapat dibangun ulang; job yang hilang dipulihkan lewat outbox/ekspor ulang), log.

## 2. Enkripsi, integritas, dan kunci

- GnuPG simetris AES-256 dengan pemeriksaan integritas (MDC), S2K SHA-512 dengan 65.011.712 iterasi, tanpa kompresi (`scripts/dr/lib.sh`). File yang diubah **gagal didekripsi** ("message has been manipulated"), juga bila `SHA256SUMS` ikut diganti; `SHA256SUMS` hanya pemeriksaan cepat sebelum dekripsi.
- GnuPG memakai direktori home sementara per run; keyring operator tidak disentuh. Host operator wajib punya `gpg` (Git for Windows dan distribusi Linux umumnya menyertakannya).
- Kunci = file berisi **tepat satu baris** ≥32 karakter base64/hex, mis. `openssl rand -base64 48 > backup.key`; path di `BACKUP_ENCRYPTION_KEY_FILE`. Skrip menolak file kunci yang kosong, lebih dari satu baris, terlalu pendek, berisi karakter lain, atau berada di dalam repository. Passphrase tidak pernah muncul di command line.
- Kunci disimpan di secret store/vault operator (DEC-014), dengan minimal dua pemegang. **Kehilangan kunci = backup tidak dapat dipulihkan.**
- Rotasi kunci: buat kunci baru untuk backup berikutnya; kunci lama disimpan sampai semua backup yang dienkripsi dengannya melewati retention.
- Skrip berjalan dengan `umask 077`; plaintext dump/arsip hanya ada selama proses dan dihapus oleh trap, juga saat gagal; backup gagal diberi penanda `INCOMPLETE`.
- Jumlah baris, checksum tabel kunci, dan `pg_dump` diambil dari **satu snapshot database** (transaksi `REPEATABLE READ READ ONLY` + `pg_export_snapshot` + `pg_dump --snapshot`), sehingga perbandingan restore tetap tepat walau aplikasi terus menulis. `snapshot_at` di `backup.json` adalah waktu snapshot itu (dasar perhitungan RPO). Objek disalin **setelah** dump, sehingga setiap baris metadata di dump punya file.

## 3. Menjalankan backup

```bash
BACKUP_ENCRYPTION_KEY_FILE=/secure/backup.key bash scripts/backup.sh /backups
```

Argumen: `[output-root]` (default `BACKUP_DIR` atau `tmp/backups`) dan `[database]` (default database container). Output `<root>/<UTC stamp>/`: `db.dump.gpg`, `objects.tar.gpg`, `table-counts.txt`, `checksums.txt`, `migration.txt`, `SHA256SUMS`, `backup.json` (waktu snapshot, durasi, ukuran, revisi git).

**Jadwal (usulan, menunggu DEC-010):** harian + sebelum setiap deploy yang menjalankan migrasi; salin ke lokasi offsite terpisah akun/region (DEC-014); retention mengikuti DEC-010.

## 4. Restore rehearsal

```bash
BACKUP_ENCRYPTION_KEY_FILE=/secure/backup.key DR_CANARY_EMAIL=... DR_CANARY_PASSWORD_FILE=/secure/canary.pw \
  bash scripts/dr-rehearsal.sh /backups/<stamp> [waktu-kegagalan-UTC]
```

Langkah dan verifikasi (berhenti pada kegagalan pertama):

1. `sha256sum -c SHA256SUMS` — file terenkripsi utuh.
2. Dekripsi dengan kunci; pemeriksaan integritas GnuPG menolak file yang diubah.
3. PostgreSQL baru (`postgres:18.6-alpine`, password acak per run) di **jaringan Docker `--internal`** tanpa rute ke stack atau internet; `pg_restore`.
4. Skema: jumlah dan migrasi terakhir sama dengan saat backup.
5. Jumlah baris **setiap tabel** sama.
6. MD5 tabel kunci sama (users, unit organisasi, pegawai, role, versi KPI, metadata objek, audit log).
7. File bukti: setiap file snapshot cocok dengan `MANIFEST.json`, dan setiap baris `stored_object_metadata` database hasil restore punya file dengan checksum yang sama (`ops:storage-verify`).
8. Image aplikasi **development** (`kpi-berlianartha-app:dev`, kode checkout ini lewat bind mount) dijalankan pada jaringan terisolasi terhadap database hasil restore: membaca akun dan unit, dan **login** akun canary diverifikasi (`Auth::validate`).
9. RTO terukur (mulai dekripsi sampai verifikasi aplikasi selesai) dan jendela RPO (waktu kegagalan − waktu snapshot).

Setiap run menulis `restore-report-<stamp>-<run>.txt` di direktori backup (diakhiri `== Rehearsal lulus` atau `== Rehearsal GAGAL`). Container, jaringan, dan file plaintext dibersihkan di akhir, juga saat gagal. Password database sementara dan password canary diteruskan ke Docker lewat nama variabel environment, tidak sebagai nilai di command line. Rehearsal dengan image produksi dilakukan di staging (Step 20).

Sumber rehearsal lokal disiapkan dengan `scripts/dr/prepare-rehearsal-source.php` (hanya pada database `*_test`): akun canary `dr-canary@fixtures.example.test` dan tiga objek bukti sintetis.

## 5. Bukti rehearsal

Rehearsal lokal 16 Oktober 2026 dengan skrip final (Docker Compose; sumber `kpi_test` hasil `migrate:fresh --seed` + `scripts/dr/prepare-rehearsal-source.php`; data sintetis, tidak ada data produksi):

| Ukuran | Hasil |
|---|---|
| Backup `20261016T090807Z` | 4 detik; `db.dump.gpg` 423.998 byte, `objects.tar.gpg` 10.322 byte; 66 tabel; 21 migrasi (terakhir `2026_10_16_001500_create_integration_tables`); snapshot `2026-10-16T09:08:08Z` |
| Integritas | `SHA256SUMS` cocok; dekripsi + MDC lulus |
| `pg_restore` ke PostgreSQL 18.6 terisolasi | selesai 8 detik sejak mulai |
| Skema | 21 migrasi, migrasi terakhir sama |
| Jumlah baris | 66 tabel sama persis dengan snapshot |
| Checksum tabel kunci | users, organization_units, employees, role_assignments, kpi_definition_versions, stored_object_metadata, audit_logs sama |
| File bukti | 3 objek di snapshot, 0 rusak, 3 metadata, 0 tanpa file, 0 checksum tidak cocok |
| Aplikasi | membaca 14 akun dan 25 unit organisasi; login canary terverifikasi |
| **RTO terukur** | **14 detik** (dekripsi sampai verifikasi aplikasi) |
| **RPO terukur** | **15 detik** untuk kegagalan simulasi `09:08:23Z` (15 detik setelah snapshot); jendela terburuk = interval backup |

Skenario negatif (semuanya gagal dengan benar, laporan diakhiri `== Rehearsal GAGAL` atau ditolak sebelum mulai, tanpa container/jaringan/plaintext tersisa):

| Skenario | Hasil |
|---|---|
| Satu bit `db.dump.gpg` diubah **dan** `SHA256SUMS` ditulis ulang | Dekripsi ditolak: "encrypted message has been manipulated" |
| Kunci lain | Dekripsi ditolak |
| File kunci dua baris | Ditolak sebelum memulai |
| File kunci di dalam repository | Backup ditolak sebelum memulai |

Rehearsal sebelumnya hari yang sama dengan format lama (openssl AES-256-CBC tanpa MAC) juga lulus (RTO 19 detik); format itu diganti karena tidak mendeteksi perubahan ciphertext.

**Perbandingan dengan target:** target RPO/RTO belum disetujui (DEC-010). Usulan RPO ≤ 24 jam dan RTO ≤ 4 jam (`DR_RUNBOOK.md` §1) terpenuhi di lokal, tetapi data hanya 0,4 MB; ukur ulang di staging dengan volume pilot sebelum penerimaan DR.

## 6. Rotasi kredensial integrasi (service-to-service)

Kunci tanda tangan manifest per koneksi dapat dirotasi tanpa jeda:

1. Buat kunci baru (≥32 karakter acak) di secret store.
2. Set `INTEGRATION_<KONEKSI>_HMAC_KEY_PREVIOUS` = kunci lama dan `INTEGRATION_<KONEKSI>_HMAC_KEY` = kunci baru; deploy. Manifest bertanda tangan kunci lama atau baru diterima.
3. Pengirim beralih ke kunci baru.
4. Setelah tidak ada lagi file bertanda tangan kunci lama (minimal satu siklus pengiriman), kosongkan `..._HMAC_KEY_PREVIOUS`; deploy.

Kredensial lain (DB, S3, SMTP, `APP_KEY`) mengikuti secret manager (DEC-014). `APP_KEY` **tidak boleh diganti** tanpa rencana: dipakai untuk kolom terenkripsi (rahasia 2FA, respons idempotensi) dan cookie; kehilangan `APP_KEY` berarti 2FA semua akun harus disetel ulang.

## 7. Dependency pemulihan

| Item | Diperlukan untuk | Disimpan di |
|---|---|---|
| Kunci backup | dekripsi backup | secret store, ≥2 pemegang (DEC-014) |
| `APP_KEY` produksi | data terenkripsi, session, URL bertanda tangan | secret store |
| Kredensial PostgreSQL/S3/Redis/SMTP target | menjalankan stack hasil restore | secret store / penyedia (DEC-014) |
| Kunci HMAC integrasi | menerima file integrasi setelah pulih | secret store |
| Image aplikasi dengan digest yang sama dengan backup | menjalankan skema yang cocok (`backup.json` mencatat revisi git) | registry (DEC-014) |
| Runbook ini dan `DR_RUNBOOK.md` | prosedur | repository + salinan offline |
