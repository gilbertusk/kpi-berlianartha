# Demo Lokal untuk Review Awal — Bukan Staging/UAT

**Status 27 Oktober 2026:** panduan siap, **demo belum dijalankan**. Docker CLI tersedia, tetapi Docker Desktop Linux Engine belum aktif (`docker info` gagal karena named pipe tidak ada). Tidak ada server, akun, atau data baru yang dibuat pada sesi persiapan ini.

## Tujuan dan batas

Demo ini membantu pemilik proyek melihat tampilan, struktur organisasi, dan batas akses dengan **data fiktif**. Ini tidak menetapkan team pilot, KPI, bobot, formula, target, approver, atau skor resmi. Jangan menyalin data pegawai/customer asli. Akun System Admin di demo lokal adalah akun fiktif `dev.sysadmin@kpi.example.test`, **bukan akun pribadi pemilik**. Seeder `StagingPilotSeeder` memang menolak `APP_ENV=local`; jangan mengubah guard itu untuk demo.

Yang tersedia setelah seed lokal: 1 company, 3 department, 21 team; 13 akun `[DEV]` ber-domain `kpi.example.test` untuk Management, HR, Auditor, System Admin, tiga Head, dan TL/Staff Sales, AR, Woven; serta 252 kandidat KPI PRD **DRAFT**. Tidak ada template atau KPI published otomatis. Bukti yang membutuhkan scan malware tetap tidak boleh dianggap aman untuk staging/production.

## Sebelum menjalankan

1. Jalankan **Docker Desktop dengan Linux containers** dan tunggu sampai `docker info` berhasil. Jika tidak bisa, berhenti di sini; test otomatis bukan pengganti demo browser.
2. Gunakan checkout baru di samping repo utama agar `.env`, data, dan volume demo terpisah. Pastikan ruang disk dan port lokal 8088 (aplikasi), 8025 (Mailpit), 8333 (S3) tersedia. Jangan lakukan `migrate:fresh`, `reset-db`, atau `docker compose down --volumes` pada repo kerja yang mungkin berisi data milik pengguna.
3. Jalankan perintah berikut di **PowerShell** dari root repo utama, lalu pertahankan terminal yang sama. Nilai `COMPOSE_PROJECT_NAME` harus tetap disetel setiap kali menjalankan `dev.ps1` di checkout demo; tanpa itu nama project default dalam `compose.yaml` dapat menyentuh volume repo utama. Image development memakai tag global yang sama, sehingga jangan jalankan build dua checkout dengan versi berbeda secara bersamaan.

```powershell
git clone . ..\kpi-berlianartha-demo
Set-Location ..\kpi-berlianartha-demo
$env:COMPOSE_PROJECT_NAME = 'kpi-berlianartha-demo'
docker info --format '{{.ServerVersion}}'
.\scripts\dev.ps1 bootstrap
.\scripts\dev.ps1 seed
.\scripts\dev.ps1 smoke
```

`bootstrap` membuat `.env` lokal yang di-ignore, secret acak, membangun stack dan menjalankan migrasi; `seed` menolak lingkungan selain `APP_ENV=local` dan memberi password akun fiktif baru **satu kali di terminal** bila `DEV_SEED_PASSWORD` kosong. Simpan password itu hanya secara pribadi; jangan kirim screenshot terminal, tempel ke chat, log, atau commit. Jika password sudah tidak diketahui pada seed ulang, jangan mereset database; gunakan alur reset melalui Mailpit lokal sesuai pengaturan email. Kegagalan command harus dihentikan dan dicatat, bukan dianggap lulus.

## Pemeriksaan manual bersama pemilik

| Yang dilihat | Akun fiktif | Harapan demo, bukan keputusan bisnis |
|---|---|---|
| Login dan menu administrasi | `dev.sysadmin@kpi.example.test` | Bisa mengelola akun/role, tidak mengubah nilai KPI atau approval |
| Organisasi dan kandidat KPI | `dev.hr@kpi.example.test` | Tiga kelompok dan 21 team terlihat; 252 kandidat tetap DRAFT |
| Lingkup satu department | `dev.head.accounting@kpi.example.test` | Tidak melihat/mengubah data di luar scope yang diizinkan |
| Lingkup team Sales, AR, Woven | `dev.tl.sales@kpi.example.test`, `dev.tl.ar@kpi.example.test`, `dev.tl.woven@kpi.example.test` | Akses team masing-masing, bukan team lain |
| Data sendiri | `dev.staff.sales@kpi.example.test`, `dev.staff.ar@kpi.example.test`, `dev.staff.woven@kpi.example.test` | Akses terbatas ke data milik sendiri |
| Audit/read-only | `dev.auditor@kpi.example.test` | Dapat membaca sesuai scope tanpa hak edit |

Buka `http://localhost:8088/login` di mesin yang menjalankan Docker (host ini sesuai `APP_URL` Compose). Mailpit lokal ada di `http://localhost:8025` (hanya bind localhost). Catat komentar pemilik tentang pilihan team, menu, bahasa, hak akses, dan data yang perlu ditambahkan; **jangan** menandai review demo sebagai UAT atau sign-off. Scorecard resmi, alur approval resmi, data nyata, integrasi runtime, dan performa pilot tidak dapat diperagakan secara sah sebelum keputusan bisnis terkait disetujui.

## Berhenti dan tindak lanjut

Saat selesai, di terminal checkout demo yang masih memiliki `COMPOSE_PROJECT_NAME`:

```powershell
.\scripts\dev.ps1 down
```

Perintah itu **mempertahankan volume demo**. Jangan menghapus checkout/volume tanpa meninjau apakah ada data yang ingin disimpan. Setelah owner mengirim daftar revisi (tanpa data pribadi/credential), perubahan seed atau UI dapat direncanakan. Step 20 tetap BLOCKED sampai ada staging terpisah, keputusan pilot dan KPI resmi, UAT nyata, serta persetujuan tertulis.
