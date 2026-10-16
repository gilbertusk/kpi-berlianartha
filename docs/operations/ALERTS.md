# Alert dan Runbook

**Status:** USULAN — Step 18, 16 Oktober 2026. Sinyal sumber sudah ada dan diuji (`OBSERVABILITY.md`), tetapi **belum ada alert yang aktif**: sistem alert/pager, kanal on-call, dan nama pemilik bergantung pada hosting (DEC-014). Ambang di bawah adalah usulan teknis; ambang yang menyangkut layanan (availability, RPO/RTO) mengikuti DEC-010.

**Aturan:** setiap alert punya pemilik (role), severity (`INCIDENT_RESPONSE.md`), dan langkah pertama yang dapat dikerjakan. Sinyal tanpa tindakan tidak dijadikan alert; cukup tampil di dashboard.

Pemilik (role, nama ditetapkan saat DEC-014): **Ops** = IT Operations/on-call aplikasi; **Data** = pemilik data sumber integrasi (DEC-016) bersama Ops; **Sec** = IT Security.

| ID | Kondisi (usulan) | Sumber | Sev | Pemilik | Langkah pertama (runbook) |
|---|---|---|---|---|---|
| A-01 | `/health` gagal 3× berturut-turut (interval 30 detik) | probe eksternal / healthcheck nginx | 1 | Ops | Cek status container `http`/`app`; `docker compose logs app nginx`; bila host mati → `DR_RUNBOOK.md` |
| A-02 | `ops:readiness` check **kritis** FAIL (database, cache, storage, queue) 2× berturut-turut | cron `ops:readiness --json` tiap menit | 1 | Ops | Lihat kolom Keterangan; pulihkan dependency yang gagal; database rusak/hilang → `DR_RUNBOOK.md` |
| A-03 | Heartbeat scheduler > 5 menit | `kpi_scheduler_heartbeat_age_seconds` | 2 | Ops | Restart container `scheduler`; cek log `schedule:work`; job terjadwal bersifat idempotent, tidak perlu dijalankan ulang manual |
| A-04 | Heartbeat worker > 5 menit, **atau** `kpi_queue_size` > 500 selama 15 menit | metrik | 2 | Ops | Restart/scale worker; cek job yang macet di log `queue.job_failed` |
| A-05 | `kpi_failed_jobs_total` bertambah | metrik / `queue.job_failed` | 3 | Ops | `php artisan queue:failed`; perbaiki penyebab; `php artisan queue:retry <id>`; ekspor laporan yang gagal dapat diulang pemiliknya |
| A-06 | `kpi_outbox_dead_letter` > 0 | metrik | 3 | Ops | Baca `last_error` aman di `outbox_messages`; perbaiki; `php artisan ops:outbox-requeue <id> --reason="..."` (teraudit) |
| A-07 | `kpi_outbox_oldest_pending_age_seconds` > 900 | metrik | 2 | Ops | Pastikan scheduler (`ops:outbox-dispatch`) dan worker hidup (A-03/A-04) |
| A-08 | Koneksi integrasi aktif melewati `max_age_hours`, run dead-letter, atau file dikarantina | `ops:readiness` (`integration_freshness`), `kpi_integration_*` | 3 | Data | Halaman Integrasi → exception register; perbaiki sumber; `php artisan integration:retry <run>`; data terlambat diputuskan pemilik data |
| A-09 | `kpi_notification_deliveries_failed_24h` > 10 | metrik | 3 | Ops | Cek SMTP (DEC-014); pengiriman diulang otomatis sampai batas, lalu `failed` dan tetap terlihat di aplikasi |
| A-10 | Respons 5xx > 2% dari `http.request` selama 5 menit (minimal 50 request) | agregasi log | 2 | Ops | Cari `request_id` respons 5xx di log; rollback rilis terakhir bila mulai setelah deploy (`RELEASE_STRATEGY.md`) |
| A-11 | p95 `duration_ms` > 2000 selama 10 menit | agregasi log | 3 | Ops | Cek `db.slow_query` dan antrean; laporan berat dipindah ke ekspor async |
| A-12 | `db.slow_query` > 20 dalam 10 menit | log | 4 | Ops | Tiket: analisis query (SQL tanpa binding tersedia di log), tambah indeks/batasi filter |
| A-13 | Lonjakan 429 pada `login.store`/`password.email` (> 50 dalam 10 menit) | agregasi log | 3 | Sec | Cek IP sumber (perlu `TRUSTED_PROXIES` benar); blokir di edge/WAF (DEC-014); tinjau akun yang ditarget |
| A-14 | Tidak ada backup `COMPLETE` dalam 26 jam, atau backup berakhir `INCOMPLETE` | job backup (`backup.json`, penanda `INCOMPLETE`) | 2 | Ops | Jalankan `scripts/backup.sh` manual; periksa ruang disk dan kunci; `BACKUP_RESTORE.md` |
| A-15 | Restore rehearsal terakhir lebih tua dari jadwal (usulan: per kuartal dan setelah perubahan skema besar) | catatan rehearsal | 4 | Ops | Jadwalkan `scripts/dr-rehearsal.sh` (`BACKUP_RESTORE.md` §4) |
| A-16 | `ops:security-check` gagal saat deploy | pipeline deploy | 2 | Sec | Deploy dihentikan; perbaiki konfigurasi yang disebut (nilai tidak pernah dicetak) |

## Tidak dijadikan alert

- Batch import `staged` (`kpi_import_batches_staged`): pekerjaan normal pengguna; tampil di halaman modul.
- Ekspor laporan gagal sesekali: pemilik melihat status dan dapat mengulang; hanya A-05 bila job gagal berulang.
- WARN `failed_jobs`/`outbox_dead_letters` di readiness: sudah tercakup A-05/A-06.

## Prasyarat aktivasi (blocked)

1. Hosting, agregator log, penyimpanan metrik, dan pager (DEC-014).
2. Nama pemilik on-call dan jam dukungan (DEC-014, DEC-010 availability/maintenance window).
3. Uji tiap alert di staging dengan memicu kondisinya (Step 20).
