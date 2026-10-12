# ADR-0004: Private Object Storage untuk Evidence dan Export

- Status: Proposed
- Date: 2026-10-12

## Context

Evidence dan hasil export dapat besar, sensitif, dan mempunyai retention berbeda dari row transaksi.

## Decision

Simpan binary pada S3-compatible private object storage. PostgreSQL menyimpan metadata, checksum, object key, size, detected content type, classification, scan status, owner, retention, dan linkage. Akses melalui short-lived signed URL setelah policy check.

## Consequences

- Database tidak membesar karena binary.
- Backup/retention database dan file perlu direkonsiliasi.
- Upload berada di quarantine sampai validation dan antivirus scan selesai.
- Bucket/object tidak boleh public.

