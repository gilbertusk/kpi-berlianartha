# Observability

**Status:** Step 18 — 16 Oktober 2026. Sinyal di bawah dibangun dan diuji di aplikasi. Backend log/metrik/alert (agregator log, Prometheus/Grafana atau layanan terkelola, pager) **belum ada** karena hosting belum diputuskan (DEC-014); sampai itu, sinyal dibaca lewat log container dan command `ops:*`.

## 1. Log terstruktur

| Aspek | Implementasi |
|---|---|
| Format | Channel `json` (Monolog `JsonFormatter`, satu baris per entri ke stderr). Production/staging: `LOG_STACK=json`, `LOG_LEVEL=info` (diperiksa `ops:security-check`) |
| Correlation ID | `RequestContext` mengambil `X-Request-Id` dari proxy bila formatnya `[A-Za-z0-9._-]{8,64}`, kalau tidak membuat ULID; disimpan di Laravel Context sehingga ikut ke **setiap entri log** request itu dan ke **job yang di-dispatch** (field `context.request_id`); dikembalikan di header respons |
| Entri request | `http.request`: method, nama route, status, `duration_ms`, `user` (public_id pseudonim). Tidak pernah query string, body, header, atau cookie. Matikan dengan `OBSERVABILITY_REQUEST_LOG=false` |
| Redaksi | Processor `RedactSensitiveData` di setiap channel yang menulis log (`single`, `daily`, `monthly`, `stderr`, `json`, `slack`, `papertrail`, `syslog`, `errorlog`; diuji per channel): nilai di bawah kunci (dinormalkan tanpa `_`/`-`, tidak peka huruf besar) yang mengandung `password`, `secret`, `token`, `cookie`, `authorization`, `api_key`, `hmac`, `signature`, `session`, `credential`, `private_key`, `two_factor`, `recovery_code`, `otp` (rekursif) menjadi `[REDACTED]`; di teks: `kunci=nilai`, `"kunci": "nilai"`, token `Bearer`/`Basic`/`Token`, baris header `Authorization`/`Cookie`, dan kredensial `user:pass@` di URL. Exception diganti ringkasan (kelas, pesan tersaring, lokasi, 15 frame tanpa argumen); `QueryException` hanya SQLSTATE dan SQL dengan placeholder, tanpa nilai binding |
| Data personal | Log memakai `public_id`, bukan nama/email; pesan exception teknis integrasi dan outbox disimpan sebagai nama kelas/kode aman (Step 17) |

## 2. Event operasional

| Event | Level | Isi |
|---|---|---|
| `http.request` | info | lihat di atas |
| `db.slow_query` | warning | koneksi, `duration_ms`, SQL (maks. 500 karakter) **tanpa binding**; ambang `OBSERVABILITY_SLOW_QUERY_MS` (500) |
| `queue.job_failed` | error | nama job, queue, jumlah percobaan, kelas exception (tanpa pesan) |
| Run integrasi, outbox, ekspor, notifikasi | — | tercatat di tabel masing-masing (`integration_runs`, `outbox_messages`, `report_exports`, `notification_deliveries`) dan diringkas di metrik |

## 3. Health dan readiness

| Sinyal | Cara | Kelas |
|---|---|---|
| Liveness web | `GET /health` → `{"status":"ok"}` (tanpa session/DB; healthcheck nginx) | — |
| Liveness php-fpm | `fpm-healthcheck` (healthcheck container `app`) | — |
| Liveness worker/scheduler | `ops:heartbeat worker|scheduler --check` (healthcheck container) | — |
| Readiness | `php artisan ops:readiness [--disk=s3] [--strict] [--json]` | lihat tabel berikut |

`ops:readiness` membedakan dependency **kritis** (gagal → exit 1) dari sinyal **opsional** (WARN; exit 1 hanya dengan `--strict`):

| Check | Kelas | Keterangan |
|---|---|---|
| `database` | kritis | `select 1` |
| `cache` | kritis | tulis-baca-hapus (Redis) |
| `storage` | kritis | tulis-baca-hapus objek pada disk yang diminta (S3 privat) |
| `queue` | kritis | ukuran antrean dapat dibaca (Redis) |
| `scheduler_heartbeat` | opsional | heartbeat dari host mana pun ≤150 detik |
| `worker_heartbeat` | opsional | idem |
| `failed_jobs` | opsional | ada job gagal yang belum ditangani |
| `outbox_dead_letters` | opsional | ada pesan outbox dead-letter |
| `integration_freshness` | opsional | koneksi aktif yang sukses terakhirnya lebih tua dari `max_age_hours` (bawaan 26 jam); tidak ada koneksi aktif = OK |

## 4. Metrik

`php artisan ops:metrics [--format=json|prometheus]` — snapshot hitungan dan umur saja (tidak ada isi record). Metrik yang query-nya gagal dilaporkan `null`/`unavailable` dan exception-nya masuk log, sehingga kegagalan storage/database terlihat sebagai metrik hilang. Untuk Prometheus, jalankan berkala (mis. textfile collector) setelah hosting dipilih.

| Metrik | Arti |
|---|---|
| `kpi_queue_size` | job menunggu di antrean default |
| `kpi_failed_jobs_total` | job gagal di `failed_jobs` |
| `kpi_outbox_pending`, `kpi_outbox_dead_letter`, `kpi_outbox_oldest_pending_age_seconds` | kedalaman, dead-letter, dan umur outbox |
| `kpi_scheduler_heartbeat_age_seconds`, `kpi_worker_heartbeat_age_seconds` | umur heartbeat terbaru; bila belum ada heartbeat: `null` (JSON) atau baris `# … unavailable` (Prometheus) |
| `kpi_report_exports_failed_24h`, `kpi_report_exports_in_flight` | ekspor gagal 24 jam, ekspor sedang diproses |
| `kpi_notification_deliveries_failed_24h` | email notifikasi gagal 24 jam |
| `kpi_integration_runs_failed`, `kpi_integration_runs_dead_letter` (run berstatus gagal-menunggu-retry dan dead-letter), `kpi_integration_runs_quarantined_24h`, `kpi_integration_exceptions_open` | kesehatan pipeline integrasi |
| `kpi_import_batches_staged` | batch import yang menunggu commit/tolak |

Latensi dan error request diambil dari entri `http.request` (status, `duration_ms`) di agregator log; perhitungan skor yang gagal muncul sebagai exception aman di UI dan `queue.job_failed` bila lewat job. Tracing terdistribusi belum dipasang: correlation ID adalah penghubung antara request, log, dan job.

## 5. Proxy dan host

- PHP melihat peer nginx sebagai alamat klien. Bila TLS diterminasi load balancer di depan nginx, isi `TRUSTED_PROXIES` dengan IP/CIDR load balancer itu; hanya peer tersebut yang boleh menentukan `X-Forwarded-For`/`-Proto`. Header host/prefix tidak pernah dipercaya dan dibuang nginx. Jangan pakai `*` kecuali nginx hanya dapat dijangkau dari load balancer (`ops:security-check` memberi peringatan).
- Di luar local/test hanya host `APP_URL` yang dijawab (host lain 400), kecuali `/health`.

## 6. Gerbang konfigurasi keamanan

`php artisan ops:security-check [--strict] [--json]` memeriksa konfigurasi tanpa mencetak nilai: APP_DEBUG mati, APP_KEY ada, APP_URL https, cookie session `Secure`/`HttpOnly`/`SameSite`, koneksi integrasi aktif lengkap (kritis); enkripsi session, log JSON, level log, `TRUSTED_PROXIES` terisi dan bukan `*`, tidak ada kunci integrasi lama yang masih diterima, alamat pengirim email (peringatan). Gagal hanya di `APP_ENV=production` atau dengan `--strict`; wajib dijalankan saat deploy (Step 20/21).

## 7. Belum tersedia (blocker)

- Agregator log, penyimpanan metrik, dashboard, dan pager: DEC-014.
- Retensi log: DEC-010.
- Alert: daftar usulan di `ALERTS.md`; belum ada alert yang aktif.
