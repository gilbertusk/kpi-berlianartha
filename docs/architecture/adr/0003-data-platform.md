# ADR-0003: PostgreSQL Source of Truth dan Redis sebagai Infrastruktur Pendukung

- Status: Proposed, menunggu konfirmasi environment IT
- Date: 2026-10-12

## Decision

Gunakan PostgreSQL 18 sebagai source of truth aplikasi. Gunakan Redis untuk cache, queue, rate limit, dan distributed coordination. Redis tidak menyimpan satu-satunya salinan business state.

## Consequences

- Constraint, transaction, numeric precision, dan relational audit dapat ditegakkan di database.
- Outbox di PostgreSQL memungkinkan event/notification dipulihkan ketika Redis bermasalah.
- Cache wajib memiliki invalidation dan scope yang benar.
- Versi final provider/service menunggu TECH-003 dan DEC-014.

