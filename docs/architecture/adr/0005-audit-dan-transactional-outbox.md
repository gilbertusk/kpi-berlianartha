# ADR-0005: Business Audit dan Transactional Outbox

- Status: Accepted for baseline
- Date: 2026-10-12

## Decision

Business audit disimpan append-only secara logis dan dibuat dalam transaction yang sama dengan perubahan sensitif. Application log dipisahkan dari audit. Side effect penting menggunakan transactional outbox yang diproses worker secara idempotent.

## Consequences

- Approval, calibration, correction, lock, export, dan perubahan configuration dapat ditelusuri.
- Kegagalan notification/integration tidak membuat transaksi utama berada pada status parsial.
- Outbox membutuhkan retry, dead-letter/manual recovery, observability, dan cleanup retention.
- Audit payload harus meredaksi secret dan meminimalkan data personal.

