# Prompt Step 20 — Staging, Pilot, dan UAT

Anda bertindak sebagai Release Manager dan UAT Coordinator. Deploy release candidate ke staging, jalankan pilot lintas fungsi, dan dapatkan sign-off bisnis. Jangan deploy production pada step ini.

## Prasyarat

Core scope Step 05–12 serta core readiness Step 18–19 harus lulus. Fitur khusus Step 13–17 tidak menjadi prasyarat pilot fondasi kecuali secara eksplisit dipilih sebagai scope pilot. Team pilot, user list, data owner, UAT approver, training owner, cutover approach, dan support channel harus disetujui. Pilot minimal mewakili satu team Commercial/Support, satu Accounting, dan satu Produksi sesuai PRD.

## Pekerjaan staging

1. Provision staging yang terpisah dari production: database, Redis, object storage, secrets, domain, email policy, dan observability.
2. Deploy menggunakan immutable image dari CI. Catat commit SHA, image digest, migration version, configuration version, dan deployment time.
3. Jalankan migration rehearsal, smoke test, health/readiness, worker, scheduler, storage, notification safe recipient, integration sandbox, backup, dan restore check.
4. Seed hanya master/fixture resmi atau data pilot yang telah disanitasi. Jangan menyalin data produksi tanpa approval dan perlindungan.

## Pekerjaan pilot dan UAT

5. Buat UAT plan per role dan unit yang menguji end-to-end cycle, exception, rejection, correction, export, audit, serta role scope.
6. Siapkan akun, role, organization assignment, template, period, target, source data, dan evidence policy untuk pilot berdasarkan keputusan approved.
7. Berikan training singkat dan user guide untuk Employee, Team Leader, Manager, HR, Management, Auditor, dan System Admin.
8. Catat setiap feedback/defect dengan severity, reproducibility, owner, fix version, dan retest evidence.
9. Lakukan business reconciliation score dan laporan terhadap sample manual yang disetujui.
10. Jalankan operational acceptance: monitoring, alert, backup/restore, runbook, support/escalation, audit review, dan access review.
11. Dapatkan sign-off eksplisit dari Management, HR Performance, perwakilan unit pilot, IT/security, dan data owner. Jangan menandai sign-off hanya karena tidak ada komentar.
12. Buat `docs/release/PILOT_UAT_REPORT.md`, `docs/release/GO_LIVE_CHECKLIST.md`, release notes, known issues, dan rollback plan.
13. Perbarui `PROJECT_PROGRESS.md`.

## Exit gate

- Tidak ada defect kritis/high yang terbuka.
- Seluruh UAT journey wajib lulus dan direkonsiliasi.
- Hak akses ditinjau oleh owner.
- Backup/restore staging terbukti.
- Runbook dan support owner siap.
- Sign-off tertulis lengkap atau production tetap `BLOCKED`.

## Laporan akhir

Laporkan environment/version, pilot team, UAT cases, pass/fail, reconciliation, defect, sign-off, known issue, readiness percentage, dan keputusan `GO/NO-GO/BLOCKED`.
