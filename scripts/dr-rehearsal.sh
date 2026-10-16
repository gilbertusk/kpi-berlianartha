#!/usr/bin/env bash
# Restore rehearsal (Step 18): restores an encrypted backup (scripts/backup.sh)
# into an isolated PostgreSQL container on an internal Docker network with no
# route to the stack or the internet, then verifies it:
#   - integrity: SHA256SUMS of the encrypted files, and the GnuPG integrity
#     check while decrypting (a modified file does not decrypt)
#   - schema: migration count and latest migration
#   - row count of every table
#   - md5 of key tables (users, organization, employees, roles, KPI versions,
#     object metadata, audit log)
#   - object storage: every snapshot file against its manifest, and every
#     stored_object_metadata row of the restored database against the files
#   - application: the development app image (this checkout's code) boots on
#     the isolated network against the restored database and, when
#     DR_CANARY_EMAIL / DR_CANARY_PASSWORD_FILE are given, a login (password
#     check) succeeds
# and reports the measured restore time (RTO) and the data-loss window
# (RPO = failure time - backup snapshot time). Every run writes its own
# report next to the backup: restore-report-<UTC stamp>-<run>.txt.
#
# Usage:
#   BACKUP_ENCRYPTION_KEY_FILE=/secure/backup.key bash scripts/dr-rehearsal.sh <backup-dir> [failure-time-utc]

set -euo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail() {
    printf 'dr-rehearsal: %s\n' "$1" >&2
    [ -z "${REPORT:-}" ] || printf 'GAGAL: %s\n' "$1" >> "$REPORT"
    exit 1
}

# shellcheck source=scripts/dr/lib.sh
. scripts/dr/lib.sh

BACKUP="${1:?Direktori backup wajib diisi}"
FAILURE_AT="${2:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"
require_key_file "$KEY_FILE" "$ROOT_DIR"
command -v gpg >/dev/null || fail 'gpg tidak ditemukan.'

STAMP="$(basename "$BACKUP")"
RUN="dr$$"
NET="kpi-dr-net-$RUN"
PG="kpi-dr-pg-$RUN"
WORK="tmp/dr-rehearsal/$RUN"
OBJECTS="storage/app/dr-rehearsal/$RUN"
REPORT="$BACKUP/restore-report-$(date -u +%Y%m%dT%H%M%SZ)-$RUN.txt"
MOUNT="$(pwd -W 2>/dev/null || pwd)"

# Passed to docker by name only (`-e NAME`), never as a command-line value.
POSTGRES_PASSWORD="$(openssl rand -hex 24)"
DB_PASSWORD="$POSTGRES_PASSWORD"
DR_CANARY_EMAIL="${DR_CANARY_EMAIL:-}"
DR_CANARY_PASSWORD="$([ -z "${DR_CANARY_PASSWORD_FILE:-}" ] || head -n 1 "$DR_CANARY_PASSWORD_FILE" | tr -d '\r')"
export POSTGRES_PASSWORD DB_PASSWORD DR_CANARY_EMAIL DR_CANARY_PASSWORD

PASSED=0
cleanup() {
    [ "$PASSED" -eq 1 ] || printf '%s\n' '== Rehearsal GAGAL' >> "$REPORT"
    docker rm -f "$PG" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    rm -rf "$WORK" "$OBJECTS"
}
trap cleanup EXIT

say() { printf '%s\n' "$1" | tee -a "$REPORT"; }
: > "$REPORT"

STARTED="$(date +%s)"
say "== Rehearsal $RUN atas backup $STAMP (kegagalan disimulasikan pada $FAILURE_AT)"

(cd "$BACKUP" && sha256sum -c SHA256SUMS >/dev/null) || fail 'Checksum file backup terenkripsi tidak cocok.'
say 'OK checksum file terenkripsi'

mkdir -p "$WORK" "$OBJECTS"
make_gnupg_home "$WORK/.gnupg"
gpg_decrypt "$WORK/.gnupg" "$KEY_FILE" "$BACKUP/db.dump.gpg" "$WORK/db.dump" || fail 'Dekripsi database gagal (kunci salah atau file diubah).'
gpg_decrypt "$WORK/.gnupg" "$KEY_FILE" "$BACKUP/objects.tar.gpg" "$WORK/objects.tar" || fail 'Dekripsi objek gagal (kunci salah atau file diubah).'
say 'OK dekripsi dan pemeriksaan integritas'

docker network create --internal "$NET" >/dev/null
docker run -d --name "$PG" --network "$NET" -e POSTGRES_USER=kpi_restore -e POSTGRES_DB=kpi_restore -e POSTGRES_PASSWORD postgres:18.6-alpine >/dev/null
for _ in $(seq 1 60); do
    MSYS_NO_PATHCONV=1 docker exec "$PG" pg_isready -U kpi_restore -d kpi_restore >/dev/null 2>&1 && break
    sleep 1
done

MSYS_NO_PATHCONV=1 docker cp "$WORK/db.dump" "$PG:/tmp/db.dump"
MSYS_NO_PATHCONV=1 docker exec "$PG" pg_restore --no-owner --no-privileges -U kpi_restore -d kpi_restore /tmp/db.dump
MSYS_NO_PATHCONV=1 docker exec "$PG" rm -f /tmp/db.dump
DB_RESTORED="$(date +%s)"
say "OK pg_restore ($((DB_RESTORED - STARTED)) detik sejak mulai)"

psql_q() { MSYS_NO_PATHCONV=1 docker exec "$PG" psql -U kpi_restore -d kpi_restore -At -v ON_ERROR_STOP=1 -c "$1"; }

psql_q "select count(*) || ' ' || max(migration) from migrations" > "$WORK/migration.txt"
diff -q "$BACKUP/migration.txt" "$WORK/migration.txt" >/dev/null || fail 'Skema berbeda dari backup.'
say "OK skema: $(cat "$WORK/migration.txt")"

psql_q "select table_name || '=' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name" > "$WORK/table-counts.txt"
diff -u "$BACKUP/table-counts.txt" "$WORK/table-counts.txt" >> "$REPORT" || fail 'Jumlah baris berbeda dari backup.'
say "OK jumlah baris $(wc -l < "$WORK/table-counts.txt") tabel sama dengan sumber"

psql_q "$(cat scripts/dr/checksums.sql)" > "$WORK/checksums.txt"
diff -u "$BACKUP/checksums.txt" "$WORK/checksums.txt" >> "$REPORT" || fail 'Checksum tabel kunci berbeda.'
say 'OK checksum tabel kunci (users, organisasi, pegawai, role, versi KPI, metadata objek, audit log)'

tar --force-local -C "$OBJECTS" -xf "$WORK/objects.tar"

# The development app image (code of this checkout), on the isolated network
# only, against the restored database.
app_run() {
    MSYS_NO_PATHCONV=1 docker run --rm --network "$NET" \
        -v "$MOUNT:/var/www/html" -v kpi-berlianartha_vendor:/var/www/html/vendor \
        -e APP_ENV=local -e DB_CONNECTION=pgsql -e DB_HOST="$PG" -e DB_PORT=5432 -e DB_DATABASE=kpi_restore -e DB_USERNAME=kpi_restore -e DB_PASSWORD \
        -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr -e OBSERVABILITY_REQUEST_LOG=false \
        -e DR_CANARY_EMAIL -e DR_CANARY_PASSWORD \
        --entrypoint php kpi-berlianartha-app:dev "$@"
}

app_run artisan ops:storage-verify "$OBJECTS/$STAMP" | tee -a "$REPORT"
say 'OK file bukti: snapshot cocok dengan manifest dan metadata database'

app_run scripts/dr/app-check.php | tee -a "$REPORT"

FINISHED="$(date +%s)"
SNAPSHOT_AT="$(sed -n 's/.*"snapshot_at": "\(.*\)".*/\1/p' "$BACKUP/backup.json")"
RPO_SECONDS=$(( $(date -u -d "$FAILURE_AT" +%s) - $(date -u -d "$SNAPSHOT_AT" +%s) ))
say "RTO terukur (dekripsi sampai verifikasi aplikasi): $((FINISHED - STARTED)) detik"
say "Jendela kehilangan data (RPO) untuk kegagalan pada $FAILURE_AT: $RPO_SECONDS detik sejak snapshot $SNAPSHOT_AT"
PASSED=1
say '== Rehearsal lulus'
