# Production Readiness Checklist — Sebelum Step 21

**Tanggal:** Belum tersedia  
**Tim IT:** Belum ditentukan  
**Penyedia Hosting:** Belum ditentukan  

| # | Komponen | Kebutuhan | Status | Bukti |
|---|----------|-----------|--------|-------|
| 1 | Compute production | 2+ vCPU, 4+ GB RAM, SSD | | |
| 2 | Database production | PostgreSQL 18, terpisah staging | | |
| 3 | Cache/Queue production | Redis 8, terpisah staging | | |
| 4 | Object storage production | Private S3-compatible | | |
| 5 | Domain/TLS production | Domain dengan TLS valid | | |
| 6 | Secret manager | Tidak ada secret di repo/kode | | |
| 7 | Email production | SMTP production (bukan Mailpit) | | |
| 8 | Observability | Log, metrik, alert | | |
| 9 | Backup production | Harian + sebelum deploy, offsite | | |
| 10 | Restore production | Uji restore, RTO terukur | | |
| 11 | Support rota | Kontak on-call, escalation, runbook | | |
| 12 | WAF/keamanan | Perlindungan aplikasi web | | |
| 13 | SSO/MFA production | Sesuai DEC-013 | | |
| 14 | Kebijakan penguncian akun | 5 gagal = kunci, idle session | | |
| 15 | Change window | Disetujui Management | | |
| 16 | Rollback owner | Ditentukan sebelum deploy | | |
| 17 | Image immutable production | Digest dari CI | | |
| 18 | Migrasi production | Sekali, terkontrol | | |

**Keputusan:** Siap / Belum Siap  
**Tanda Tangan Tim IT:**  
**Tanggal:**  

---