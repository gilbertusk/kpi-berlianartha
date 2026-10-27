# Production Infrastructure Readiness — Persyaratan Sebelum Step 21

**Tanggal:** 27 Oktober 2026  
**Pemilik:** Tim IT  
**Status:** Belum tersedia. Dokumen ini adalah daftar kebutuhan production yang harus dipenuhi sebelum Step 21 dapat dimulai.

## 1. Prasyarat Mutlak

Sesuai `docs/product/MANAGEMENT_HR_DECISIONS_STEP20.md` dan `docs/product/UAT_SIGNOFF_MATRIX.md`, Step 21 hanya boleh dimulai setelah:

- [ ] Step 20 menghasilkan keputusan `GO` tertulis
- [ ] Semua UAT-01 s.d. UAT-12 lulus dan direkonsiliasi
- [ ] Tidak ada defect Critical/High terbuka
- [ ] Sign-off tertulis dari Management, HR Performance, 3 wakil unit pilot, IT/Security, dan pemilik data

## 2. Komponen Infrastructure Production

| Komponen | Kebutuhan Minimum | Status |
|---|---|---|
| Compute | 2+ vCPU, 4+ GB RAM, SSD | Belum tersedia |
| Database | PostgreSQL 18, terpisah dari staging | Belum tersedia |
| Cache/Queue | Redis 8, terpisah dari staging | Belum tersedia |
| Object storage | Private S3-compatible, bucket production | Belum tersedia |
| Domain/TLS | Domain production dengan TLS valid | Belum tersedia |
| Secret manager | HashiCorp Vault / AWS Secrets Manager / setara | Belum tersedia |
| Email | SMTP production, tidak menggunakan Mailpit | Belum tersedia |
| Observability | Log, metrik, dan alert (Prometheus/Grafana atau setara) | Belum tersedia |
| Backup | Harian + sebelum deploy, lokasi offsite | Belum tersedia |
| Restore | Uji restore production, RTO terukur | Belum tersedia |
| Support rota | Kontak on-call, escalation, runbook | Belum tersedia |

## 3. Keamanan Production

| Item | Kebutuhan | Status |
|---|---|---|
| TLS | Semua trafik production via HTTPS | Belum tersedia |
| WAF | Perlindungan aplikasi web | Belum tersedia |
| Secret manager | Tidak ada secret di kode atau repo | Belum tersedia |
| SSO/MFA | Sesuai DEC-013 production | Belum disetujui |
| Kebijakan penguncian akun | 5 kali gagal = kunci, masa idle session | Belum disetujui |

## 4. Observability dan Alert

| Item | Kebutuhan | Status |
|---|---|---|
| Health check | `/health` dan readiness endpoint | Tersedia (lokal) |
| Log | JSON structured, redaction secret | Belum dikonfigurasi production |
| Metrik | Queue age, response time, error rate | Belum tersedia |
| Alert | Route ke on-call owner | Belum tersedia |

## 5. Backup dan Restore Production

| Item | Kebutuhan | Status |
|---|---|---|
| Frekuensi backup | Harian + sebelum deploy | Belum ditetapkan |
| Lokasi offsite | Terpisah dari production | Belum tersedia |
| Retensi | Sesuai DEC-010 | Belum disetujui production |
| Uji restore | Sebelum go-live | Belum dilakukan |
| RPO/RTO | Sesuai target DEC-010 | Belum diukur production |

## 6. Deployment Production

| Item | Kebutuhan | Status |
|---|---|---|
| Image immutable | Digest dari CI | Belum tersedia |
| Migrasi | Sekali, terkontrol, monitoring lock | Belum dilakukan |
| Rollback plan | Image sebelumnya atau forward-fix | Belum disetujui |
| Change window | Disetujui Management | Belum ditetapkan |
| Rollback owner | Ditentukan sebelum deploy | Belum ditentukan |

## 7. Catatan IT

Semua item di atas adalah **kebutuhan production** yang harus disediakan oleh IT/penyedia hosting sebelum Step 21. IT tidak dapat menyelesaikan item ini tanpa:
- Persetujuan budget dan penyedia hosting (DEC-014)
- Keputusan SSO/MFA production (DEC-013)
- Keputusan RPO/RTO production (DEC-010)
- Persetujuan pemilik data dan bisnis

**Production tetap BLOCKED** sampai seluruh item di atas tersedia dan Step 20 berstatus GO.