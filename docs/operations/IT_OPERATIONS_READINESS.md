# IT Operations Readiness — Step 20 Staging dan UAT

**Tanggal:** 27 Oktober 2026  
**Pemilik:** Tim IT  
**Status:** Siap untuk provision staging. UAT dan sign-off bisnis masih menjadi tanggung jawab pemilik bisnis dan unit pilot.

## 1. Cakupan Tanggung Jawab IT

| Area | Tanggung Jawab IT | Bukan Tanggung Jawab IT |
|---|---|---|
| Infrastruktur staging | Provision database, Redis, private object storage, domain/TLS, secret manager | Keputusan bisnis tentang apa yang diuji |
| Deployment | Build image immutable, migrasi sekali, restart web/worker/scheduler | Persetujuan keputusan GO/NO-GO |
| Smoke test | Health, readiness, queue, scheduler, object private, notification safe recipient | UAT skenario bisnis per role |
| Restore rehearsal | Snapshot dan restore ke target terisolasi | Rekonsiliasi skor bisnis |
| Access review | Membantu penyiapan akun dan scope | Persetujuan hak akses oleh pemilik bisnis |
| Runbook dan support | Dokumentasi operasi, kanal dukungan, escalation | Keputusan training owner dan jadwal training |

## 2. Pemeriksaan Teknis Lokal (Sudah Terbukti)

Stack utama di port 8088 masih aktif dengan UI baru:

| Pemeriksaan | Hasil |
|---|---|
| Semua 8 layanan (app, mailpit, postgres, redis, scheduler, seaweedfs, worker, nginx) | `healthy` |
| Readiness database | OK |
| Readiness cache Redis | OK |
| Readiness object storage | OK |
| Readiness queue | OK |
| Scheduler heartbeat | OK |
| Worker heartbeat | OK |
| Failed jobs | OK |
| Outbox dead letters | OK |
| Integration freshness | OK |
| Bucket object storage anonim | HTTP 403 (ditolak) |
| Queue smoke | Diproses tepat 1 kali |
| `/health` | `200 {"status":"ok"}` |
| `/login` | `200` |
| CSS/UI baru | Aktif (`assets/app-ZcoV9-M1.css`) |

**Catatan:** Ini adalah bukti teknis lokal, bukan bukti staging atau production.

## 3. Checklist Provision Staging (Untuk Dijalankan IT)

Sebelum UAT dapat dimulai, IT harus menyelesaikan berikut:

### 3.1 Provision Lingkungan Staging

- [ ] Siapkan server/VPS terpisah dari production
- [ ] Instal PostgreSQL 18, database `kpi_staging`
- [ ] Instal Redis 8, terpisah dari production
- [ ] Siapkan private object storage (S3-compatible), bucket `kpi-staging-*`
- [ ] Siapkan domain staging dengan TLS valid
- [ ] Siapkan secret manager untuk `APP_KEY`, DB password, S3 credential, dll
- [ ] Siapkan email test / Mailpit, tidak mengirim ke pelanggan asli
- [ ] Konfigurasi monitoring dan alert dasar

### 3.2 Deployment Staging

- [ ] Build image immutable (app dan http) dari CI
- [ ] Catat image digest app dan http
- [ ] Jalankan vulnerability scan (Trivy), pastikan tidak ada fixable CRITICAL/HIGH
- [ ] Deploy image immutable ke staging
- [ ] Jalankan `php artisan migrate --force` sekali saja
- [ ] Restart web, worker, dan scheduler dengan versi yang sama
- [ ] Catat waktu deploy, aktor, SHA, dan versi konfigurasi

### 3.3 Smoke Test Staging

- [ ] `GET /health` melalui nginx → `{"status":"ok"}`
- [ ] Readiness: database, cache, storage, queue, scheduler heartbeat, worker heartbeat
- [ ] Queue smoke job diproses tepat sekali
- [ ] Object storage anonim ditolak (HTTP 403)
- [ ] Notification safe recipient (tidak mengirim ke pelanggan asli)
- [ ] Audit dan log berfungsi

### 3.4 Restore Rehearsal Staging

- [ ] Buat snapshot database staging
- [ ] Restore ke target terisolasi
- [ ] Verifikasi jumlah baris per tabel
- [ ] Verifikasi checksum tabel kunci
- [ ] Verifikasi file bukti cocok dengan MANIFEST
- [ ] Verifikasi login akun uji
- [ ] Catat RPO dan RTO terukur
- [ ] Bandingkan dengan target DEC-010

### 3.5 Akses dan Akun Pilot

- [ ] Buat akun untuk 22 pengguna pilot (tanpa kredensial pribadi di repo)
- [ ] Konfigurasi role dan scope sesuai DEC-002
- [ ] Verifikasi larangan self-approval
- [ ] Verifikasi System Admin tidak mengubah nilai KPI

## 4. Checklist Keluaran Staging (Gate Sebelum UAT)

| Gate | Bukti Wajib | Status |
|---|---|---|
| Staging terisolasi dari production | Konfigurasi tanpa secret di repo, bukti pemisahan | Belum dijalankan |
| Image immutable dengan digest | Digest app/http dari CI | Belum tersedia |
| Migrasi sekali | `migrate --status`, durasi migrasi | Belum dijalankan |
| Smoke test lulus | Output semua pemeriksaan di §3.3 | Belum dijalankan |
| Restore rehearsal lulus | Laporan restore, RPO/RTO terukur | Belum dijalankan |
| Akses pilot tersedia | Daftar akun 22 pengguna, role dan scope | Belum dijalankan |

## 5. Support Selama UAT

IT menyediakan:
- Kanal dukungan teknis selama UAT
- Pantauan health, readiness, queue, dan scheduler
- Penanganan incident teknis (bukan bisnis)
- Backup harian staging
- Log dan audit untuk investigasi defect

IT **tidak** menyediakan:
- Persetujuan skenario UAT bisnis
- Persetujuan skor atau rekonsiliasi
- Sign-off bisnis untuk GO/NO-GO

## 6. Bukti yang Harus Disimpan IT

| Bukti | Lokasi |
|---|---|
| Image digest app dan http | Docker registry / CI log |
| Hasil vulnerability scan | Hasil Trivy / scan |
| Output migrasi | `php artisan migrate --status` |
| Hasil smoke test staging | Log / rekaman uji |
| Laporan restore rehearsal | RPO/RTO terukur |
| Daftar akun dan scope pilot | Tanpa kredensial pribadi |
| Catatan deploy (waktu, aktor, versi) | Catatan operasi IT |

---

**Catatan:** Setelah semua di atas selesai dan UAT dijalankan oleh unit pilot, IT akan membantu kumpulkan bukti teknis untuk sign-off. Step 21 (produksi) masih memerlukan keputusan GO tertulis dari pemilik bisnis setelah UAT lulus.