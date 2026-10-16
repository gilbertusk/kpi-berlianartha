#!/usr/bin/env bash
# Encrypted backup of PostgreSQL and object storage (Step 18).
#
# Usage:
#   BACKUP_ENCRYPTION_KEY_FILE=/secure/backup.key bash scripts/backup.sh [output-root] [database]
#
# Runs against the Docker Compose stack (postgres and app services); needs
# gpg on the host. The key file holds one line of at least 32 base64/hex
# characters (`openssl rand -base64 48`) and must live outside the
# repository (secret store / operator vault). Plaintext dump and object
# copies exist only until they are encrypted, and are then removed.
#
# Row counts, key-table checksums, and the dump come from one database
# snapshot (pg_export_snapshot), so a restore can be compared exactly while
# the application keeps writing.
#
# Output: <output-root>/<UTC stamp>/
#   db.dump.gpg        pg_dump custom format, GnuPG AES-256 with integrity check
#   objects.tar.gpg    every object of the s3 disk with MANIFEST.json (SHA-256)
#   table-counts.txt   row count per table in the snapshot
#   checksums.txt      md5 of key tables in the snapshot
#   migration.txt      migration count and latest migration
#   SHA256SUMS         checksums of the encrypted files
#   backup.json        snapshot time, sizes, source, git revision

set -euo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail() { printf 'backup: %s\n' "$1" >&2; exit 1; }

# shellcheck source=scripts/dr/lib.sh
. scripts/dr/lib.sh

KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"
require_key_file "$KEY_FILE" "$ROOT_DIR"
command -v gpg >/dev/null || fail 'gpg tidak ditemukan.'

OUT_ROOT="${1:-${BACKUP_DIR:-tmp/backups}}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$OUT_ROOT/$STAMP"
STAGING="storage/app/backup-staging/$STAMP"
GNUPG="$OUT/.gnupg"
mkdir -p "$OUT"
chmod 700 "$OUT"

# Plaintext never outlives the run, also when a step fails.
COMPLETE=0
SNAP_PID=''
cleanup() {
    [ -z "$SNAP_PID" ] || kill "$SNAP_PID" >/dev/null 2>&1 || true
    rm -f "$OUT/db.dump" "$OUT/objects.tar"
    rm -rf "$STAGING" "$GNUPG"

    if [ "$COMPLETE" -ne 1 ]; then
        printf 'backup: gagal; backup tidak lengkap di %s tidak boleh dipakai.\n' "$OUT" >&2
        touch "$OUT/INCOMPLETE"
    fi
}
trap cleanup EXIT

pg() { MSYS_NO_PATHCONV=1 docker compose exec -T postgres "$@"; }
app() { MSYS_NO_PATHCONV=1 docker compose exec -T app "$@"; }

DB_USER="$(pg sh -c 'printf %s "$POSTGRES_USER"')"
DB_NAME="${2:-$(pg sh -c 'printf %s "$POSTGRES_DB"')}"
STARTED="$(date +%s)"

# One read-only REPEATABLE READ transaction holds the snapshot open while
# the verification data is read and pg_dump runs with the same snapshot.
coproc SNAP { pg psql -U "$DB_USER" -d "$DB_NAME" -qAt -v ON_ERROR_STOP=1 2>&1; }
snap() {
    local line ended=0
    printf '%s\n' "$1" '\echo __END__' >&"${SNAP[1]}"
    while IFS= read -r line <&"${SNAP[0]}"; do
        line="${line%$'\r'}"
        if [ "$line" = '__END__' ]; then ended=1; break; fi
        printf '%s\n' "$line"
    done
    [ "$ended" -eq 1 ] || fail 'Query snapshot database gagal.'
}

SNAPSHOT_ID="$(snap 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SELECT pg_export_snapshot();')"
SNAPSHOT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
[ -n "$SNAPSHOT_ID" ] || fail 'Snapshot database tidak diperoleh.'

snap "select table_name || '=' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name;" > "$OUT/table-counts.txt"
snap "$(cat scripts/dr/checksums.sql);" > "$OUT/checksums.txt"
snap "select count(*) || ' ' || max(migration) from migrations;" > "$OUT/migration.txt"

pg pg_dump -U "$DB_USER" -d "$DB_NAME" -Fc --snapshot="$SNAPSHOT_ID" > "$OUT/db.dump"
[ -s "$OUT/db.dump" ] || fail 'pg_dump menghasilkan file kosong.'

snap 'COMMIT;' > /dev/null
exec {SNAP[1]}>&-
wait "$SNAP_PID" || true
SNAP_PID=''

# Objects after the database: every metadata row in the dump has its file.
rm -rf "$STAGING"
app php artisan ops:storage-snapshot "$STAGING" --disk=s3 >/dev/null
tar --force-local -C "storage/app/backup-staging" -cf "$OUT/objects.tar" "$STAMP"

make_gnupg_home "$GNUPG"
for file in db.dump objects.tar; do
    gpg_encrypt "$GNUPG" "$KEY_FILE" "$OUT/$file" "$OUT/$file.gpg"
    rm -f "$OUT/$file"
done

(cd "$OUT" && sha256sum db.dump.gpg objects.tar.gpg > SHA256SUMS)
FINISHED="$(date +%s)"

cat > "$OUT/backup.json" <<JSON
{
  "snapshot_at": "$SNAPSHOT_AT",
  "database": "$DB_NAME",
  "git_revision": "$(git rev-parse HEAD 2>/dev/null || echo unknown)",
  "duration_seconds": $((FINISHED - STARTED)),
  "db_dump_bytes": $(wc -c < "$OUT/db.dump.gpg"),
  "objects_bytes": $(wc -c < "$OUT/objects.tar.gpg"),
  "tables": $(wc -l < "$OUT/table-counts.txt"),
  "encryption": "gnupg symmetric aes256 mdc s2k-sha512"
}
JSON

COMPLETE=1
printf 'backup: %s (%s detik)\n' "$OUT" "$((FINISHED - STARTED))"
