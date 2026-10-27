# Checklist Gate Step 20 — Staging, Pilot, UAT

**Status 27 Oktober 2026:** BLOCKED / NO-GO. Tim IT telah menyediakan dokumen staging (`STAGING_ENVIRONMENT.md` dan `STAGING_DEPLOYMENT_PLAN.md`), tetapi staging nyata belum di-provision. Semua kotak gate masih kosong. Jangan meneruskan ke production sebelum keputusan GO tertulis Step 20.

| Gate | Bukti wajib / owner yang menyetujui | Status |
|---|---|---|
| Scope pilot dan minimum 3 kelompok | DEC-007: team Commercial/Support, Accounting, Produksi; pengguna, volume, concurrent user, data owner, UAT approver, training owner, support channel, cutover; Management/HR/IT | [ ] OPEN |
| Aturan bisnis dan data | DEC-002/003/004/005/006; KPI/template published secara sah, golden examples signed, approval/substitute, sumber dan evidence policy; HR/Management/unit/data owner | [ ] OPEN |
| Keamanan dan identitas | DEC-010/013/014, daftar browser, MFA/auth, akses/role, klasifikasi data, scanner malware untuk evidence; IT/Security | [ ] OPEN |
| Staging terpisah | Database, Redis, private storage, domain/TLS, secret manager, safe-recipient email, observability, backup, sandbox integrasi bila in scope; IT/operator | [ ] NOT RUN — dokumen siap, staging belum di-provision |
| Artifact dan rencana migrasi | SHA dan CI final, digest immutable app/http, vulnerability exception disetujui, konfigurasi berversi tanpa secret, `migrate --pretend`/review, recovery point; Release Manager | [ ] NOT RUN — SHA `ecaa14d9` tercatat, digest belum tersedia |
| Deployment staging | Waktu/aktor, image digest, migration version, config version; migrasi sekali; web/worker/scheduler memakai versi sama; rollback owner tercatat | [ ] NOT RUN — belum ada staging |
| Smoke staging | Health/readiness, queue tepat sekali, scheduler, object private, notification safe recipient, sandbox/rekonsiliasi jika ada, audit/log/alert | [ ] NOT RUN — smoke lokal lulus (port 8088), bukan staging |
| Backup/restore staging | Snapshot dan restore ke target terisolasi pada volume representatif; checksum/baris/skema/objek, RPO/RTO dibanding target DEC-010 | [ ] NOT RUN — rehearsal hanya lokal (Step 18), bukan staging |
| Pilot dan training | Akun/scope, fixture/data sanitasi resmi, user guide dan sesi tujuh role, dukungan/escalation, akses owner ditinjau | [ ] NOT RUN |
| UAT dan kualitas | Semua UAT-01..12 di `PILOT_UAT_REPORT.md` dijalankan, negative path dan aksesibilitas manual, rekonsiliasi skor/report, performa beban DEC-007, tidak ada Critical/High terbuka | [ ] NOT RUN |
| Business/operational sign-off | Management, HR Performance, tiga perwakilan pilot, IT/Security, data owner; referensi dan tanggal tertulis; support/runbook diterima | [ ] OPEN |
| Keputusan release | GO hanya bila semua gate relevan dibuktikan dan disetujui; jika gagal NO-GO/BLOCKED, catat owner dan tindakan | [ ] BLOCKED |

Urutan pelaksanaan setelah approval: tinjau konfigurasi dan data → backup/recovery point → deploy immutable image + migration rehearsal/eksekusi sekali → smoke/restore → training → UAT dan rekonsiliasi → tinjau defect/akses → sign-off. Gunakan `docs/implementation/RELEASE_STRATEGY.md`, `docs/operations/MIGRATION_POLICY.md`, `docs/operations/BACKUP_RESTORE.md`, dan `docs/product/UAT_SIGNOFF_MATRIX.md`. Jangan gunakan `scripts/dev.sh reset-db` atau `migrate:rollback` sebagai rollback staging.

Seed demo `STAGING_SEED.md` tidak menandai gate apa pun selesai; akun dan team pilot nyata tetap memerlukan persetujuan DEC-007 dan access review.
