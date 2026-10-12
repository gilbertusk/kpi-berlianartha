# ADR-0007: Strategi Waktu dan Asia/Jakarta

- Status: Accepted for baseline
- Date: 2026-10-12

## Decision

Simpan timestamp secara konsisten dalam UTC dan tampilkan/interpretasikan deadline bisnis pada timezone `Asia/Jakarta`. Simpan local business date atau operational date secara eksplisit ketika shift/period tidak dapat diturunkan aman dari timestamp.

## Consequences

- API, queue, audit, dan integrasi memakai timestamp ber-offset/UTC yang tidak ambigu.
- Deadline, period boundary, dan overnight shift membutuhkan test khusus.
- Jangan bergantung pada timezone default OS/container/database.

