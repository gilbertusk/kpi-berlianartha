# Local Environment dan Container Runtime

**Status:** Baseline Step 03 — 12 Oktober 2026
**Referensi:** ADR-0001 (satu deployable, beberapa process), ADR-0003 (PostgreSQL/Redis), ADR-0004 (private object storage), `RELEASE_STRATEGY.md`

## 1. Ringkasan

| Komponen | File |
|---|---|
| Image aplikasi (multi-stage) | `Dockerfile` — target `development`, `production`, `http` |
| Lingkungan development | `compose.yaml` |
| Konfigurasi PHP/FPM/nginx/PostgreSQL/SeaweedFS | `docker/` |
| Perintah developer | `scripts/dev.sh` (Linux/macOS/Git Bash), `scripts/dev.ps1` (PowerShell) |
| CI | `.github/workflows/ci.yml`, lihat `CI_PIPELINE.md` |

Image `production` adalah satu artifact untuk tiga role (ADR-0001):

| Role | Command |
|---|---|
| web | `php-fpm` (default) |
| worker | `php artisan queue:work redis --queue=default --sleep=3 --tries=3 --backoff=10 --max-time=3600` |
| scheduler | `php artisan schedule:work` |

Target `http` adalah nginx berisi `public/` dari build yang sama dan meneruskan PHP ke `PHP_FPM_UPSTREAM` (default `app:9000`).

Laravel Horizon belum dipasang. Worker memakai `queue:work` bawaan; Horizon (dan dashboard-nya yang membutuhkan authorization) dievaluasi setelah role/scope tersedia (Step 05) dan observability (Step 18).

## 2. Service development

| Service | Image | Port host | Volume | Healthcheck |
|---|---|---|---|---|
| `app` (php-fpm) | build `development` | — | bind `.`, `vendor`, `view-cache` | FastCGI ping `/fpm-ping` (`fpm-healthcheck`) |
| `worker` | build `development` | — | sama dengan app | `ops:heartbeat worker --check --max-age=120` |
| `scheduler` | build `development` | — | sama dengan app | `ops:heartbeat scheduler --check --max-age=150` |
| `nginx` | `nginx:1.30.5-alpine` | `127.0.0.1:8088` (`APP_PORT`) | bind `public/` (read-only) | `GET /health` berisi `"status":"ok"` |
| `postgres` | `postgres:18.6-alpine` | **tidak dipublish** | `postgres-data` | `pg_isready` |
| `redis` | `redis:8.2.10-alpine` (AOF aktif) | **tidak dipublish** | `redis-data` | `redis-cli ping` |
| `seaweedfs` (S3) | `chrislusf/seaweedfs:4.48` | `127.0.0.1:8333` (`S3_PORT`) | `seaweedfs-data` | `GET /healthz` |
| `mailpit` | `axllent/mailpit:v1.31.3` | `127.0.0.1:8025` (`MAILPIT_PORT`) | — | `mailpit readyz` |
| `assets` (one-shot) | `node:24.15.0-trixie-slim`, UID developer | — | bind file build (ro) + `public/`, `vendor` (ro), `node_modules` di tmpfs | exit 0 |

Catatan:

- Port hanya di-bind ke `127.0.0.1`. PostgreSQL dan Redis hanya dapat diakses dari jaringan Compose (`docker compose exec postgres psql ...`).
- `vendor/` dan compiled views berada di named volume agar I/O tidak melewati bind mount (bind mount Docker Desktop di Windows sangat lambat: sebelum perubahan ini halaman login mencapai timeout 60 detik; sesudahnya ±0,3 detik).
- SeaweedFS membuat bucket `AWS_BUCKET` saat start dan mendaftarkan identitas admin dari `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`; akses anonim ditolak (HTTP 403). MinIO tidak dipakai karena vendor tidak lagi menerbitkan image komunitas secara berkala; pilihan ini hanya untuk development dan tidak mengunci provider production (DEC-014).
- `kpi_test` dibuat otomatis oleh `docker/postgres/initdb` saat volume PostgreSQL pertama kali diinisialisasi.
- Worker menulis heartbeat pada setiap loop (`Illuminate\Queue\Events\Looping`); scheduler menulis heartbeat tiap menit (`routes/console.php`). Heartbeat disimpan per hostname di cache Redis. Kegagalan menulis heartbeat hanya dilaporkan ke log dan tidak menghentikan worker.
- Heartbeat worker berhenti diperbarui saat aplikasi dalam maintenance mode (`php artisan down`) atau saat satu job berjalan lebih lama dari batas cek. Batas cek (`--max-age=120`) harus selalu lebih besar dari timeout job terpanjang (default 60 detik).

### Volume

| Volume | Isi | Dampak bila dihapus |
|---|---|---|
| `postgres-data` | Database development | Seluruh data lokal hilang |
| `redis-data` | Cache/queue (AOF) | Cache dan job tertunda hilang |
| `seaweedfs-data` | Object storage lokal | Object lokal hilang |
| `vendor`, `view-cache` | Dependency Composer / compiled views | Dibangun ulang otomatis |

`docker compose down` mempertahankan volume. `docker compose down --volumes` menghapus semuanya — gunakan hanya bila memang ingin mengulang dari nol.

## 3. Development di Windows

Prasyarat: Docker Desktop (backend WSL2), Git for Windows (Git Bash), PowerShell.

```powershell
git clone https://github.com/gilbertusk/kpi-berlianartha.git
cd kpi-berlianartha
.\scripts\dev.ps1 bootstrap      # atau: bash scripts/dev.sh bootstrap dari Git Bash
```

`bootstrap` melakukan:

1. membuat `.env` dari `.env.example` bila belum ada;
2. mengisi `APP_KEY`, `DB_PASSWORD`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` secara acak **hanya jika kosong** (nilai yang ada tidak ditimpa);
3. `docker compose build` dan `docker compose up -d --wait` (Composer install di dalam container, build Vite bila `public/build` belum ada);
4. `php artisan migrate`;
5. `smoke` (lihat §5).

Tips Windows:

- `scripts/dev.ps1` sengaja memakai `bash.exe` milik Git for Windows, bukan `bash.exe` WSL di System32.
- Jika port bentrok (contoh: 8080 sering dipakai aplikasi lain), ubah `APP_PORT`, `S3_PORT`, atau `MAILPIT_PORT` di `.env`.
- Performa terbaik diperoleh bila repository di-clone di filesystem WSL2 (`\\wsl$\...`).
- Jalankan perintah PHP di container (`bash scripts/dev.sh test`, `docker compose exec app php artisan ...`) agar tidak bergantung pada PHP Windows; tooling host (PHP 8.4) tetap dapat dipakai untuk IDE/test cepat dengan SQLite.

## 4. Linux deployment (gambaran, provider masih DEC-014)

1. Build sekali di CI: `docker build --target production -t <registry>/kpi-app:<git-sha> .` dan `--target http` untuk nginx.
2. Jalankan role web/worker/scheduler dari image yang **sama**; scheduler hanya satu instance.
3. Konfigurasi dan secret diinjeksikan melalui environment/secret manager — image tidak berisi `.env`.
4. `APP_OPTIMIZE=true` (default image production) menjalankan `php artisan optimize` saat container start.
5. Migration **tidak** dijalankan otomatis oleh container; jalankan sekali sesuai `MIGRATION_POLICY.md`.
6. Port php-fpm 9000 hanya boleh dapat dijangkau dari container nginx/jaringan privat; jangan pernah dipublish. Hanya nginx (target `http`) yang menerima trafik.
7. Topologi HA, TLS, secret manager, backup, dan monitoring adalah scope Step 18/DEC-014.

## 5. Bukti service sehat

```bash
bash scripts/dev.sh smoke
```

Menjalankan:

- status service (`docker compose ps`, semua `healthy`);
- `GET http://127.0.0.1:8088/health` melalui nginx → `{"status":"ok"}`;
- `php artisan ops:readiness --disk=s3` → database, cache Redis, dan round-trip object storage `OK`;
- akses anonim ke bucket → HTTP 403;
- `php artisan ops:queue-smoke` → job diproses **tepat satu kali** oleh worker;
- heartbeat worker dan scheduler.

`ops:readiness` hanya tersedia lewat CLI. Output perintah hanya menyebut check yang gagal dan tipe exception; detail exception dicatat ke log aplikasi (dapat memuat host/username, tidak memuat password).

## 6. Perintah

| Perintah | Fungsi |
|---|---|
| `bash scripts/dev.sh up` / `down` / `ps` / `logs [service]` | Siklus hidup lingkungan |
| `bash scripts/dev.sh migrate` | Migration |
| `bash scripts/dev.sh seed` | Seeder: struktur referensi 21 team, ditambah akun fiktif `[DEV]` (hanya `APP_ENV=local`; password dari `DEV_SEED_PASSWORD` atau dicetak sekali) |
| `bash scripts/dev.sh test` | Test di container dengan SQLite in-memory |
| `bash scripts/dev.sh test-pgsql` | Test terhadap PostgreSQL `kpi_test` + Redis (DB Redis 13/14) |
| `bash scripts/dev.sh lint` / `analyse` | Pint check / Larastan |
| `bash scripts/dev.sh build` | `npm ci` + `npm run build` di container |
| `bash scripts/dev.sh queue-check` / `readiness` / `smoke` | Bukti queue, dependency, dan seluruh lingkungan |
| `bash scripts/dev.sh reset-db` | Hapus semua tabel database **lokal** lalu migrate ulang |

`reset-db` menolak berjalan kecuali `APP_ENV=local` dan `DB_HOST=postgres` (database Compose), lalu meminta pengguna mengetik nama database. Untuk otomatisasi, set `KPI_CONFIRM_RESET=<nama database>`.

Test dilindungi `Tests\Support\TestDatabaseGuard`: suite menolak berjalan kecuali database adalah SQLite `:memory:` atau bernama `*_test`, sehingga `RefreshDatabase` tidak dapat menghapus database development.

## 7. Troubleshooting

| Gejala | Penyebab/tindakan |
|---|---|
| `required variable DB_PASSWORD is missing a value` | `.env` belum dibuat; jalankan `bootstrap` |
| `ports are not available ... 127.0.0.1:8088` | Port dipakai proses lain; ubah `APP_PORT` di `.env` |
| `app` lama `starting` pada start pertama | Composer install ke volume `vendor` (start period 300 detik); lihat `docker compose logs -f app` |
| Halaman error "Vite manifest not found" | Jalankan `bash scripts/dev.sh build` |
| Permission denied pada `storage/` (Linux) | Set `DOCKER_UID`/`DOCKER_GID` = `id -u`/`id -g` di `.env`, lalu `docker compose build app` |
| `password authentication failed` setelah `.env` dihapus/dibuat ulang | `bootstrap` membuat `DB_PASSWORD` baru, tetapi volume `postgres-data` menyimpan password lama. Kembalikan nilai lama di `.env`, atau ubah: `docker compose exec postgres psql -U kpi_app -d kpi_berlianartha -c "ALTER USER kpi_app PASSWORD '<nilai .env>'"`, atau mulai dari nol dengan `docker compose down --volumes` (menghapus data lokal) |
| `kpi_test` tidak ada | Volume PostgreSQL dibuat sebelum init script; buat manual: `docker compose exec postgres createdb -U kpi_app kpi_test` |
| Worker `unhealthy` | `docker compose logs worker`; pastikan Redis sehat; heartbeat dicek per container |
| Perubahan dependency Composer tidak terlihat | Volume `vendor` dipakai container: `docker compose exec app composer install` |
