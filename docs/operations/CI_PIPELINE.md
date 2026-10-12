# CI Pipeline

**Workflow:** `.github/workflows/ci.yml` — berjalan pada push ke `main`, pull request, dan manual dispatch.
**Permissions:** `contents: read` saja; checkout memakai `persist-credentials: false`. Semua action dipin ke commit SHA.

| Job | Gate nyata |
|---|---|
| `php-quality` | `composer validate --strict`, `composer install`, `composer audit`, `pint --test`, Larastan level 7 |
| `tests` | Service PostgreSQL 18.6 + Redis 8.2; `migrate` → `migrate:rollback` → `migrate` pada database kosong; `php artisan test` dengan `DB_CONNECTION=pgsql` dan `CACHE_STORE=redis`; `ops:readiness`; worker `queue:work redis` + `ops:queue-smoke` (tepat satu kali) |
| `frontend` | `npm ci`, `npm audit --audit-level=high`, `npm run build`, manifest wajib ada |
| `docker-image` | Build target `production` dan `http`; hygiene (user non-root, tanpa `.env`/tests/dev dependency, kode tidak writable); runtime smoke nginx → php-fpm (`/health`, `/login`, asset, `.env` ditolak); Trivy gagal pada CVE fixable CRITICAL/HIGH; SBOM CycloneDX diunggah |
| `compose-smoke` | `bash scripts/dev.sh bootstrap` dari checkout bersih (build, `up --wait`, migrate, smoke HTTP/readiness/storage privat/queue/heartbeat), `test-pgsql`, persistensi data setelah container dibuat ulang |

## Cache

- Composer: cache direktori download Composer, key = hash `composer.lock` (bukan `vendor/`).
- npm: cache bawaan `actions/setup-node` berbasis `package-lock.json`.
- Cache hanya berisi paket publik; tidak ada secret, `.env`, atau artifact build yang dipromosikan dari cache.

## Secret

Pipeline tidak memerlukan secret repository. Credential database/S3 untuk job dibuat acak per run (`scripts/dev.sh bootstrap`) atau memakai service CI efemeral (`POSTGRES_HOST_AUTH_METHOD=trust` hanya pada service job `tests`).

## Menjalankan gate yang sama secara lokal

```bash
composer validate --strict && composer audit && vendor/bin/pint --test && vendor/bin/phpstan analyse
php artisan test
npm audit --audit-level=high && npm run build
bash scripts/dev.sh bootstrap && bash scripts/dev.sh test-pgsql
```
