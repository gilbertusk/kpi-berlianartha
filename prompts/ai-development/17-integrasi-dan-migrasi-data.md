# Prompt Step 17 — Integrasi dan Migrasi Data

Anda bertindak sebagai Integration dan Data Migration Engineer. Implementasikan adapter, contract, staging, reconciliation, serta migrasi dengan prinsip aman dan dapat diulang.

## Prasyarat

Sistem sumber, owner teknis/bisnis, metode akses, field mapping, frequency, volume, retention, cutover, dan credential process harus disetujui. Jika akses nyata belum ada, buat contract test/fixture dan laporkan runtime integration sebagai `BLOCKED`.

## Pekerjaan

1. Buat inventory integrasi untuk HR/employee, organization, attendance bila relevan, Sales/order, accounting/AR/tax, purchasing, production/PPIC, warehouse, quality, dan email/SSO.
2. Untuk setiap integrasi dokumentasikan system of record, direction, protocol, authentication, endpoint/file pattern, schema/version, schedule, expected volume, owner, SLA, retry, idempotency, reconciliation, error handling, dan data classification.
3. Implementasikan adapter anti-corruption layer. Domain internal tidak boleh bergantung langsung pada payload vendor.
4. Implementasikan inbound staging: receive → authenticate/checksum → schema validate → semantic validate → preview/reconcile → atomic commit → archive/quarantine.
5. Gunakan idempotency/source reference dan transactional outbox untuk outbound event. Retry harus bounded dengan dead-letter/manual recovery.
6. Implementasikan integration run dashboard: watermark, last success, freshness, counts, duplicates, rejects, retries, dan reconciliation difference.
7. Buat migration mapping untuk master, historical period, target, actual, evidence metadata, dan approval hanya jika data tersedia. Jangan membuat approval history palsu dari legacy data.
8. Jalankan minimal dua dry run pada salinan data yang aman; hasil run kedua harus konsisten dan tidak menggandakan data.
9. Buat exception register, data cleansing ownership, rollback/cutover plan, checksum/control totals, serta sign-off business owner.
10. Pastikan credential hanya dari secret store/environment dan tidak muncul di log/test fixture.
11. Tambahkan contract, schema, idempotency, retry, partial failure, malformed data, replay, reconciliation, dan access tests.
12. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Setiap integrated record dapat ditelusuri ke source dan batch.
- Replay batch tidak menggandakan data.
- Invalid batch tidak menghasilkan partial silent commit.
- Control total source dan target direkonsiliasi atau mempunyai exception yang disetujui.
- Kegagalan satu sistem tidak merusak transaksi domain utama.

## Laporan akhir

Berikan integration matrix, status `PROVEN/BLOCKED/DEFERRED` per koneksi, hasil dry run, reconciliation totals, exception, credential boundary, dan status step secara keseluruhan.
