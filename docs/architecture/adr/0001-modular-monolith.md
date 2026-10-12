# ADR-0001: Modular Monolith

- Status: Accepted for implementation baseline
- Date: 2026-10-12

## Context

Sistem mempunyai banyak workflow yang saling terkait, tetapi volume dan kebutuhan scaling belum terukur. Tim membutuhkan transaksi konsisten, deployment sederhana, dan audit lintas modul.

## Decision

Gunakan satu Laravel modular monolith. Module boundaries ditetapkan pada `DOMAIN_BOUNDARIES.md`. Web, worker, dan scheduler memakai source/image yang sama dengan command runtime berbeda.

## Consequences

- Transaksi lintas fungsi inti tetap sederhana dan kuat.
- Satu repository, pipeline, dan version deployment.
- Boundary harus dijaga melalui namespace, service contract, test, dan review; bukan melalui network.
- Ekstraksi service hanya dilakukan bila measurement, ownership, isolation, atau scaling membuktikan kebutuhan.

## Rejected

- Microservices awal: overhead operasi dan distributed consistency tidak sebanding dengan kebutuhan yang diketahui.
- Single unstructured Laravel application: deployment mudah tetapi domain coupling tidak terkendali.

