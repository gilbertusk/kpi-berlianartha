# Staging Smoke Test Report — Template

**Tanggal:** Belum tersedia  
**Tim IT:** Belum ditentukan  
**Environment Staging:** Belum ditentukan  
**Image Digest App:** Belum tersedia  
**Image Digest HTTP:** Belum tersedia  
**Versi Migrasi:** Belum tersedia  

| # | Pemeriksaan | Hasil | Bukti | Catatan |
|---|-------------|-------|-------|---------|
| 1 | `GET /health` → `{"status":"ok"}` | | | |
| 2 | Semua 8 layanan healthy (app, mailpit, postgres, redis, scheduler, seaweedfs, worker, nginx) | | | |
| 3 | Readiness database OK | | | |
| 4 | Readiness cache Redis OK | | | |
| 5 | Readiness object storage OK | | | |
| 6 | Readiness queue OK | | | |
| 7 | Scheduler heartbeat OK | | | |
| 8 | Worker heartbeat OK | | | |
| 9 | Failed jobs OK | | | |
| 10 | Outbox dead letters OK | | | |
| 11 | Integration freshness OK | | | |
| 12 | Bucket object storage anonim → HTTP 403 | | | |
| 13 | Queue smoke → diproses tepat 1 kali | | | |
| 14 | Notification safe recipient | | | |
| 15 | Audit dan log berfungsi | | | |

**Keputusan:** Lulus / Tidak Lulus  
**Tanda Tangan Tim IT:**  
**Tanggal:**  

---