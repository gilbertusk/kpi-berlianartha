#!/bin/sh
# Local S3-compatible object storage (SeaweedFS "mini": master, volume, filer, S3).
# SeaweedFS registers an admin identity from AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY,
# which enables S3 authentication: anonymous requests are denied, so the bucket
# stays private. Credentials come from .env and are never stored in the repository.
set -eu

: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID wajib diisi di .env}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY wajib diisi di .env}"
: "${AWS_BUCKET:?AWS_BUCKET wajib diisi}"

exec /entrypoint.sh mini -bucket="$AWS_BUCKET" -admin.ui=false
