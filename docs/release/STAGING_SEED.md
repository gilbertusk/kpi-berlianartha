# Seed Demonstrasi Staging (bukan UAT resmi)

**Status:** Kode tersedia; belum dijalankan di staging. Pemilik proyek memilih peran **System Admin** untuk akun pribadinya. Alamat email pribadi tidak disimpan di repository; operator mengisi `STAGING_PILOT_ADMIN_EMAIL` melalui secret manager milik staging. System Admin mengelola akun/role, **tidak** mengubah nilai KPI atau menjadi approver. Untuk peran bisnis, gunakan akun terpisah dan sign-off pada DEC-002.

## Yang dibuat

- Satu akun System Admin dari `STAGING_PILOT_ADMIN_EMAIL`, hanya jika email itu belum dimiliki akun lain; nama penanda `[STAGING] System Admin`.
- Dua belas akun sintetis `[STAGING]` pada domain cadangan `kpi.example.test`: Management, HR, Auditor, tiga Department Head, Team Leader dan Staff untuk Sales, Account Receivable, dan Woven. Ini **contoh lintas tiga kelompok**, bukan penetapan tiga team pilot resmi (DEC-007). Pegawai sintetis memakai nomor `STG-...`. Tidak ada KPI/template published, target, formula, skor, rantai approver resmi, atau data nyata yang di-seed.
- Password acak berbeda untuk setiap akun baru, tidak dicetak atau dibagikan. Akun memerlukan proses reset password melalui mail staging yang **sudah disetujui dan diarahkan hanya ke penerima aman**; alamat `example.test` tidak boleh dikirim ke luar. Operator tidak boleh mengandalkan password fixture lokal `DEV_SEED_PASSWORD`. Email owner belum otomatis diverifikasi.

## Prasyarat sebelum menjalankan

1. IT menetapkan environment staging yang terpisah dan otorisasi operator. Konfigurasi `APP_ENV=staging` dan database staging harus diverifikasi secara independen; **jangan** arahkan proses ke database produksi. Jangan memakai `DatabaseSeeder` sebagai substitusi: seeder ini dipanggil secara eksplisit saja.
2. Struktur organisasi dan tanggal efektif yang disetujui HR sudah ada (`OrganizationReferenceSeeder` / `ORGANIZATION_REFERENCE_EFFECTIVE_FROM`). Seeder demo tidak membuat atau mengubah struktur otomatis.
3. IT menyiapkan secret/config staging: `STAGING_PILOT_ADMIN_EMAIL` (alamat System Admin dari pemilik, di luar repo) dan `STAGING_PILOT_SEED_CONFIRM=staging`. Konfigurasi Laravel harus dimuat ulang setelah secret diubah. Kedua nilai tidak ditempel di commit, log, screenshot, atau chat.
4. Kebijakan local auth/SSO/MFA (DEC-013), mail safe recipient dan dukungan operasional (DEC-014), pemilik akun dan review akses disetujui. Menjalankan seed **bukan** persetujuan atas keputusan tersebut.

Setelah memeriksa environment, database target, dan policy mail melalui prosedur IT, operator menjalankan dari image aplikasi staging yang disetujui:

```text
php artisan db:seed --class=StagingPilotSeeder --force
```

Perintah menolak environment selain `staging`, konfirmasi yang tidak cocok, email admin kosong/tidak valid atau domain fixture, serta benturan akun. Operasi data dilakukan dalam transaksi dan dapat diulang tanpa menggandakan akun/role. Tidak ada seeder ini dalam jalur `DatabaseSeeder`; tidak ada eksekusi otomatis pada deploy.

Setelah seed: operator memeriksa jumlah dan scope di staging, meninjau akses System Admin (larangan ubah KPI/approval), mengaktifkan reset password hanya sesuai kebijakan mail staging, dan menyimpan bukti tanpa data pribadi di repo. Bila kelompok pilot resmi berbeda, jangan mengubah seed sintetis menjadi master resmi; konfigurasi ulang pilot sesuai DEC-007 dan approval owner. Backup/restore, UAT, dan sign-off tetap mengikuti `GO_LIVE_CHECKLIST.md`.

## Bukti TDD lokal (bukan bukti staging)

Journey: operator menyiapkan admin teknis tanpa hak bisnis dan akun lintas tiga kelompok untuk demonstrasi, tanpa membuka akses seed di environment lain. Test `tests/Feature/IdentityAccess/StagingPilotSeederTest.php` terlebih dahulu menghasilkan RED karena class seeder belum ada (6 test gagal/errored untuk class yang hilang); setelah implementasi, 7 test lulus (22 assertion) pada SQLite in-memory. Test mencakup environment/konfirmasi/email/tanggal acuan yang ditolak, idempotency, akun sintetis lintas kelompok, collision akun existing, dan penolakan role bisnis untuk admin. Pint dan PHPStan level 7 lulus. Coverage baris tidak diukur (tidak tersedia driver coverage); PostgreSQL/staging runtime belum dibuktikan pada run ini. Tidak ada sign-off UAT dari test tersebut.
