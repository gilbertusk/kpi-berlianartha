# Prompt Step 21 — Production Rollout, Hypercare, dan Handover

Anda bertindak sebagai Release Manager, SRE, dan Technical Lead. Lakukan production go-live hanya setelah seluruh approval tersedia, lalu rollout 21 team, hypercare, dan handover. Perubahan external state harus mengikuti change approval perusahaan.

## Prasyarat mutlak

- Step 20 menghasilkan keputusan `GO` tertulis.
- Production infrastructure, domain/TLS, secret, SSO, email, database, Redis, object storage, observability, backup, restore, support rota, dan rollback plan tersedia.
- Semua production credential diberikan melalui secret manager; jangan pernah meminta atau menaruh secret di chat/repository.
- Change window, approver, communication, maintenance notice, data migration freeze/cutoff, dan rollback owner disetujui.

Jika salah satu prasyarat tidak tersedia, berhenti sebelum deployment dan laporkan `BLOCKED`. Jangan menggunakan staging sebagai bukti production.

## Pekerjaan pre-go-live

1. Verifikasi artifact provenance: commit, tag, CI result, image digest/signature, dependency scan, migration plan, configuration diff, dan release notes.
2. Validasi capacity, health, alert route, log redaction, access list, DNS/TLS, backup terakhir, restore evidence, queue/scheduler, storage lifecycle, dan integration endpoint.
3. Jalankan final migration rehearsal dan reconciliation menggunakan snapshot aman yang paling representatif.
4. Ambil backup/pre-deploy recovery point sesuai runbook dan buktikan dapat ditemukan oleh operator.

## Pekerjaan deployment

5. Deploy immutable release dengan strategi yang disetujui. Jalankan migration sekali, secara terkontrol, dan pantau lock/duration/error.
6. Jalankan smoke test production menggunakan akun uji terotorisasi tanpa mencemari data bisnis.
7. Verifikasi login/SSO, authorization scope, web, worker, scheduler, database, Redis, storage, notification, export, integration freshness, audit, metric, log, trace, dan alert.
8. Jalankan migration/import cutover bila ada; rekonsiliasi counts, control totals, checksum/sample, rejected rows, dan source watermark sebelum membuka akses.
9. Catat keputusan lanjut/rollback berdasarkan threshold yang telah disetujui. Jangan melakukan improvisasi pada data production.

## Rollout dan hypercare

10. Rollout bertahap dari pilot menuju 21 team berdasarkan wave plan. Setiap wave membutuhkan access review, template/target validation, data reconciliation, training, dan owner acknowledgement.
11. Pantau adoption, login failure, missing data, queue age, integration error, score anomaly, report mismatch, support ticket, dan response/resolution SLA.
12. Kelola incident dengan severity dan runbook. Correction data harus melalui workflow resmi, bukan SQL manual tanpa approval/audit.
13. Jalankan hypercare selama periode yang disetujui, dengan daily review awal kemudian frekuensi menurun berdasarkan stabilitas.

## Handover dan penutupan

14. Serahkan architecture, ADR, schema, code ownership, deployment, rollback, backup/restore, DR, monitoring, alert, integration, data dictionary, admin/user guide, troubleshooting, dependency update, known issues, dan support matrix.
15. Latih operator/admin dan lakukan knowledge-transfer verification, bukan hanya presentasi.
16. Lakukan post-implementation review: hasil versus success metric, defect, performance, adoption, data quality, efektivitas KPI, risiko, technical debt, dan backlog tahap berikutnya.
17. Jadwalkan restore/DR test, access recertification, dependency patching, retention job review, dan KPI governance review berkala.
18. Buat `docs/release/PRODUCTION_RELEASE_REPORT.md`, `docs/operations/HANDOVER_CHECKLIST.md`, dan final append pada `PROJECT_PROGRESS.md`.

## Definition of done

- 21 team yang termasuk rollout mempunyai konfigurasi approved, user/scope benar, dan owner menerima.
- Production health dan integration terbukti dengan runtime evidence.
- Tidak ada defect Critical/High terbuka tanpa keputusan risiko formal.
- Backup dan restore, monitoring, alert, incident route, serta rollback dapat dijalankan operator.
- Business owner menyetujui hasil dan IT menerima operasional.
- Dokumentasi serta knowledge transfer selesai.

## Laporan akhir

Laporkan release/version, wave status untuk 21 team, migration/reconciliation, runtime validation, incident/defect, adoption, measured SLO, backup/restore status, sign-off, remaining risk, dan status akhir `COMPLETE/PARTIAL/BLOCKED`. Jangan menyatakan project selesai jika business acceptance, production proof, atau handover belum ada.
