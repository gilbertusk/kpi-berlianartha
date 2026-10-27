# Demo Lokal untuk Review Awal — Bukan Staging/UAT

**Status 27 Oktober 2026 (addendum runtime di bawah):** panduan awal dibuat ketika Docker Engine belum aktif. Setelah pemilik mengaktifkan Docker, demo lokal berhasil dijalankan pada checkout terpisah. Ini tetap bukan staging/UAT.

## Tujuan dan batas

Demo ini membantu pemilik proyek melihat tampilan, struktur organisasi, dan batas akses dengan **data fiktif**. Ini tidak menetapkan team pilot, KPI, bobot, formula, target, approver, atau skor resmi. Jangan menyalin data pegawai/customer asli. Akun System Admin di demo lokal adalah akun fiktif `dev.sysadmin@kpi.example.test`, **bukan akun pribadi pemilik**. Seeder `StagingPilotSeeder` memang menolak `APP_ENV=local`; jangan mengubah guard itu untuk demo.

Yang tersedia setelah seed lokal: 1 company, 3 department, 21 team; 13 akun `[DEV]` ber-domain `kpi.example.test` untuk Management, HR, Auditor, System Admin, tiga Head, dan TL/Staff Sales, AR, Woven; 252 kandidat KPI PRD **DRAFT**; serta dataset operasional `[DEMO]` yang terdiri dari 6 KPI published, 3 template, 1 periode, 12 target, dan 12 aktual sintetis. Semua kode dataset operasional diawali `DEMO-`, hanya dapat dibuat pada `APP_ENV=local`, dan bukan KPI, bobot, formula, target, aktual, sign-off, atau hasil bisnis resmi. Bukti yang membutuhkan scan malware tetap tidak boleh dianggap aman untuk staging/production.

## Sebelum menjalankan

1. Jalankan **Docker Desktop dengan Linux containers** dan tunggu sampai `docker info` berhasil. Jika tidak bisa, berhenti di sini; test otomatis bukan pengganti demo browser.
2. Gunakan checkout baru di samping repo utama agar `.env`, data, dan volume demo terpisah. Pastikan ruang disk dan port lokal 8088 (aplikasi), 8025 (Mailpit), 8333 (S3) tersedia. Jangan lakukan `migrate:fresh`, `reset-db`, atau `docker compose down --volumes` pada repo kerja yang mungkin berisi data milik pengguna.
3. Jalankan perintah berikut di **PowerShell** dari root repo utama, lalu pertahankan terminal yang sama. Nilai `COMPOSE_PROJECT_NAME` harus tetap disetel setiap kali menjalankan `dev.ps1` di checkout demo; tanpa itu nama project default dalam `compose.yaml` dapat menyentuh volume repo utama. Jika stack utama masih hidup, pakai port dan tag image demo yang berbeda agar container dan image tidak berbagi target.

```powershell
git clone . ..\kpi-berlianartha-demo
Set-Location ..\kpi-berlianartha-demo
$env:COMPOSE_PROJECT_NAME = 'kpi-berlianartha-demo'
docker info --format '{{.ServerVersion}}'
```

Bila stack utama memakai port default, buat `.env` dari `.env.example` **di checkout demo saja**, tambahkan `APP_PORT=18088`, `MAILPIT_PORT=18025`, `S3_PORT=18333`, dan `COMPOSE_PROJECT_NAME=kpi-berlianartha-demo`; jangan menyalin `.env` utama. Buat `compose.override.yaml` lokal (jangan commit) dengan `image: kpi-berlianartha-demo-app:dev` untuk service `app`, `worker`, dan `scheduler`. Jangan mulai bootstrap bila project, port, atau image masih sama dengan stack utama. Operator dapat menyiapkan password DEV acak di `.env` demo tanpa mencetaknya dan memakai reset password melalui Mailpit untuk login; jangan pakai password contoh yang mudah ditebak.

Sesudah persiapan isolasi tersebut:

```powershell
.\scripts\dev.ps1 bootstrap
.\scripts\dev.ps1 seed
.\scripts\dev.ps1 smoke
```

`bootstrap` mengisi secret lokal yang masih kosong, membangun stack dan menjalankan migrasi; `seed` menolak lingkungan selain `APP_ENV=local`. Bila `DEV_SEED_PASSWORD` dibiarkan kosong, seeder mencetak password acak akun fiktif baru **satu kali di terminal**. Simpan hanya secara pribadi; jangan kirim screenshot terminal, tempel ke chat, log, atau commit. Jika password tidak diketahui pada seed ulang, jangan mereset database; gunakan alur reset melalui Mailpit lokal. Kegagalan command harus dihentikan dan dicatat, bukan dianggap lulus.

## Pemeriksaan manual bersama pemilik

| Yang dilihat | Akun fiktif | Harapan demo, bukan keputusan bisnis |
|---|---|---|
| Login dan menu administrasi | `dev.sysadmin@kpi.example.test` | Bisa mengelola akun/role, tidak mengubah nilai KPI atau approval |
| Organisasi dan kandidat KPI | `dev.hr@kpi.example.test` | Tiga kelompok dan 21 team terlihat; 252 kandidat tetap DRAFT |
| Periode dan data operasional demo | `dev.hr@kpi.example.test` | Periode `[DEMO] Pilot KPI Lokal`, 12 target, dan 12 aktual sintetis terlihat; scoring resmi tetap tidak tersedia selama DEC-004 terbuka |
| Lingkup satu department | `dev.head.accounting@kpi.example.test` | Tidak melihat/mengubah data di luar scope yang diizinkan |
| Lingkup team Sales, AR, Woven | `dev.tl.sales@kpi.example.test`, `dev.tl.ar@kpi.example.test`, `dev.tl.woven@kpi.example.test` | Akses team masing-masing, bukan team lain |
| Data sendiri | `dev.staff.sales@kpi.example.test`, `dev.staff.ar@kpi.example.test`, `dev.staff.woven@kpi.example.test` | Akses terbatas ke data milik sendiri |
| Audit/read-only | `dev.auditor@kpi.example.test` | Dapat membaca sesuai scope tanpa hak edit |

Buka `http://localhost:18088/login` di mesin yang menjalankan Docker (host ini sesuai `APP_URL` Compose untuk `APP_PORT=18088`). Mailpit demo ada di `http://localhost:18025` (hanya bind localhost). Jika port lain dipilih, sesuaikan URL. Catat komentar pemilik tentang pilihan team, menu, bahasa, hak akses, dan data yang perlu ditambahkan; **jangan** menandai review demo sebagai UAT atau sign-off. Scorecard resmi, alur approval resmi, data nyata, integrasi runtime, dan performa pilot tidak dapat diperagakan secara sah sebelum keputusan bisnis terkait disetujui.

Untuk demo yang disiapkan pada 27 Oktober: buka Mailpit demo, temukan pesan reset untuk `dev.sysadmin@kpi.example.test`, ikuti tautannya di browser **lokal** dan tentukan password sendiri. Jangan kirim tautan reset atau password melalui chat/repository. Untuk melihat role lain, gunakan halaman `http://localhost:18088/forgot-password` dengan alamat akun fiktif pada tabel, kemudian buka pesan baru di Mailpit demo. Tautan reset pertama hanya dibuat untuk akun System Admin fiktif. **Belum dilakukan login browser dengan akun tersebut** pada pemeriksaan otomatis; pengguna perlu mencoba sendiri.

## Berhenti dan tindak lanjut

Saat selesai, di terminal checkout demo yang masih memiliki `COMPOSE_PROJECT_NAME`:

```powershell
.\scripts\dev.ps1 down
```

Perintah itu **mempertahankan volume demo**. Jangan menghapus checkout/volume tanpa meninjau apakah ada data yang ingin disimpan. Setelah owner mengirim daftar revisi (tanpa data pribadi/credential), perubahan seed atau UI dapat direncanakan. Step 20 tetap BLOCKED sampai ada staging terpisah, keputusan pilot dan KPI resmi, UAT nyata, serta persetujuan tertulis.

## Addendum runtime lokal 27 Oktober 2026

- Checkout demo: `D:\KERJA\WEB\kpi-berlianartha-demo`, dari commit `d509e86`. `.env` dan `compose.override.yaml` dibuat **hanya di checkout demo** dan tidak di-commit. Project Compose `kpi-berlianartha-demo`, image `kpi-berlianartha-demo-app:dev`, port 18088/18025/18333; stack lama `kpi-berlianartha` tetap berjalan terpisah. Password fixture demo dibuat acak tanpa dicetak.
- `dev.ps1 bootstrap`: build image, Compose delapan service healthy, 21 migration PostgreSQL lulus, `/health` 200, readiness database/cache/object storage/queue OK, bucket anonim 403, queue smoke diproses tepat sekali, heartbeat worker/scheduler sehat.
- `dev.ps1 seed`: 25 unit organisasi, 13 akun fiktif `[DEV]`, 252 kandidat PRD DRAFT; query pada database demo: KPI published **0**, template published **0**.
- HTTP dari host: `/health` 200, `/login` 200, `/dashboard` sebagai guest 302 ke login; Mailpit 200. Reset link akun System Admin fiktif berhasil dikirim ke Mailpit demo (respons `passwords.sent`, satu pesan; token tidak dicetak). Login autentikasi dan review UI **belum dijalankan oleh pemilik**.
- Tidak ada akun pribadi, data pegawai nyata, atau seeder staging yang dijalankan. Tidak ada bukti staging, beban pilot, atau sign-off UAT. Step 20 tetap **BLOCKED**.
