# Prompt Step 18 — Keamanan, Observability, Backup, dan Disaster Recovery

Anda bertindak sebagai Security dan Site Reliability Engineer. Hardening aplikasi dan siapkan operasi production berdasarkan kebijakan yang disetujui. Jangan mengklaim kontrol eksternal aktif tanpa bukti runtime.

## Prasyarat

Baca threat model, data classification, retention, RPO/RTO, availability/SLA, SSO decision, deployment topology, integration inventory, dan `PROJECT_PROGRESS.md`. Jika RPO/RTO belum disetujui, buat proposal tetapi tandai DR acceptance sebagai blocked.

## Pekerjaan keamanan

1. Lakukan threat-model review untuk authentication, authorization scope, session, CSRF, XSS, SQL injection, mass assignment, SSRF, file upload, object storage, export, queue, webhook, import, dan secret handling.
2. Terapkan secure cookie/session, CSRF, rate limiting, account lock/risk control sesuai policy, security headers, TLS expectation, input validation, output escaping, dan safe error handling.
3. Implementasikan webhook signature/replay protection dan service-to-service credential rotation bila integrasi tersebut ada.
4. Audit semua dependency/container; triage temuan berdasarkan exploitability dan dokumentasikan exception dengan owner serta expiry.
5. Review authorization pada setiap Livewire action, controller, download, export, job, notification deep link, dan direct object access.
6. Terapkan retention/purge/anonymization yang disetujui tanpa menghapus audit wajib atau record legal hold.

## Pekerjaan observability dan operasi

7. Implementasikan structured logging dengan correlation ID dan redaction. Jangan log password, token, cookie, full evidence, atau data personal yang tidak perlu.
8. Tambahkan health/readiness checks untuk web, database, Redis, queue, storage, scheduler heartbeat, dan critical integration freshness. Bedakan health aplikasi dari dependency optional.
9. Tambahkan metric/trace untuk request latency/error, slow query, queue depth/age/failure, scheduler, import, export, notification, integration run, calculation failure, dan storage error.
10. Buat alert dengan owner dan runbook; hindari alert tanpa tindakan.
11. Implementasikan backup terenkripsi untuk PostgreSQL dan object storage sesuai retention. Buat restore runbook, credential recovery, key dependency, dan evidence lokasi backup.
12. Lakukan restore rehearsal ke environment terisolasi; verifikasi schema, counts, checksum/sample records, login, file evidence, serta RPO/RTO aktual.
13. Buat disaster recovery runbook, incident response, severity, escalation, communication, dan post-incident review template.
14. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Tidak ada critical/high vulnerability tanpa mitigation atau approved time-bound exception.
- Cross-scope access, file bypass, export bypass, dan direct object access memiliki negative test.
- Log dan trace tidak mengandung secret.
- Queue/scheduler/integration failure menghasilkan signal yang dapat ditindaklanjuti.
- Backup berhasil direstore dan hasilnya divalidasi; keberadaan file backup saja tidak cukup.
- RPO/RTO aktual dicatat dan dibandingkan target.

## Laporan akhir

Laporkan threat yang diperbaiki, dependency findings, health/metric/alert coverage, restore evidence, measured RPO/RTO, runbook, external blocker, serta status `PASS/PARTIAL/BLOCKED`.
