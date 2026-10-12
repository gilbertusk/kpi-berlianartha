# Migration Policy

**Status:** Baseline Step 03 — 12 Oktober 2026
**Referensi:** `RELEASE_STRATEGY.md` §4–§5, ADR-0006 (effective dating dan versioning)

## 1. Prinsip

1. **Forward-compatible (expand/contract).** Kode versi N dan N+1 harus dapat berjalan terhadap schema yang sama selama rollout. Perubahan yang memutus kompatibilitas dipecah menjadi beberapa release:
   - *expand*: tambah tabel/kolom nullable/index baru; kode menulis ke struktur lama dan baru;
   - *migrate data*: backfill terkontrol dan dapat diulang (idempotent);
   - *contract*: hapus struktur lama hanya setelah tidak ada versi yang memakainya.
2. **Migration dijalankan satu kali** secara terkontrol (job release), bukan oleh setiap container saat start. Image production tidak menjalankan `migrate` otomatis.
3. **Tidak ada destructive migration tanpa rencana data.** `DROP`/`TRUNCATE`/rename kolom/perubahan tipe yang dapat kehilangan data wajib disertai rencana: backup, verifikasi, rollback/forward-fix, dan persetujuan pemilik data.
4. **Data final tidak diubah in-place** (ADR-0006). Koreksi data bisnis dilakukan melalui workflow aplikasi, bukan migration.
5. **Down migration bukan rencana rollback production.** `down()` boleh ada untuk development, tetapi rollback production memakai forward-fix atau restore sesuai rencana yang ditinjau.

## 2. Review sebelum merge

- Tinjau SQL aktual: `php artisan migrate --pretend` dan lampirkan ringkasannya di PR untuk perubahan schema penting.
- Periksa lock: operasi pada tabel besar (index, constraint, perubahan tipe) memakai strategi minim-lock PostgreSQL (`CREATE INDEX CONCURRENTLY` di migration terpisah tanpa transaksi, `NOT VALID` lalu `VALIDATE CONSTRAINT`).
- Numeric/decimal mengikuti ADR-0008; timestamp mengikuti ADR-0007 (konvensi detail di Step 04).
- CI menjalankan migration pada database PostgreSQL kosong dan rehearsal `migrate:rollback` lalu `migrate` (`.github/workflows/ci.yml`, job `tests`).

## 3. Eksekusi production

1. Pastikan release disetujui dan CI hijau untuk commit yang sama.
2. **Backup/recovery point** database sebelum migration (prosedur Step 18).
3. Jalankan `php artisan migrate --force` satu kali dari image release yang sama, dengan monitoring durasi dan lock.
4. Deploy web/worker/scheduler; restart worker agar tidak memproses payload baru dengan kode lama.
5. Smoke/reconciliation; putuskan lanjut atau rollback berdasarkan threshold yang disepakati.

## 4. Perlindungan bawaan

- `DB::prohibitDestructiveCommands()` aktif saat `APP_ENV=production` (`AppServiceProvider`), memblokir `migrate:fresh`, `migrate:refresh`, `migrate:reset`, dan `db:wipe`.
- `scripts/dev.sh reset-db` hanya berjalan untuk `APP_ENV=local` terhadap PostgreSQL Compose dan memerlukan konfirmasi nama database.
- Test suite menolak database yang namanya tidak berakhiran `_test` (`tests/Support/TestDatabaseGuard.php`).
