# Prompt Step 14 — Modul Accounting, Account Receivable, dan Tax Invoice

Anda bertindak sebagai Product Engineer untuk domain Accounting. Bangun view dan workflow KPI terkait Admin Accounting, Account Receivable, dan Tax Invoice tanpa mengubah sistem akuntansi sumber.

## Prasyarat

Baca approved KPI definitions, chart/source mapping yang disetujui, data classification, retention, reconciliation owner, dan `PROJECT_PROGRESS.md`. Jika akses ke accounting system belum tersedia, gunakan contract/fixture yang jelas dan tandai integration runtime sebagai blocker.

## Pekerjaan

1. Buat read model/reference untuk invoice, payment allocation, due date, customer, dispute, collection action, tax invoice, reconciliation item, dan source reference sesuai data minimum.
2. Implementasikan AR aging dengan `as-of date`, outstanding amount, due/overdue days, dispute status, collection owner, next action, dan history. Bucket default wajib mengikuti PRD: belum jatuh tempo, 1–30 hari, 31–60 hari, 61–90 hari, dan lebih dari 90 hari. Perubahan bucket memerlukan keputusan bisnis berversi.
3. Implementasikan collection follow-up dan promise-to-pay bila disetujui, dengan owner, due date, note classification, evidence, serta audit.
4. Implementasikan metric source untuk DSO, collection rate, overdue percentage, aging, unapplied payment, dan forecast hanya jika formula disetujui. Jangan mengarang denominator atau treatment credit note.
5. Implementasikan Tax Invoice tracking: issue deadline, reference, status, revision/rejection reason, reconciliation, dan outstanding queue sesuai aturan yang diberikan business owner.
6. Implementasikan Accounting reconciliation dashboard untuk open item, correction, document completeness, dan close readiness tanpa melakukan posting jurnal.
7. Terapkan masking, least privilege, export control, dan audit untuk nilai finansial/customer data.
8. Sediakan staged import dan reconciliation report dengan idempotency/source hash. Tidak boleh ada silent overwrite.
9. Tambahkan test aging boundary, timezone/as-of date, partial payment, overpayment, credit note hanya jika rule tersedia, disputed invoice, duplicate source, unauthorized access, decimal precision, dan reconciliation.
10. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- Sistem KPI bukan general ledger dan tidak boleh menjadi source of truth accounting.
- Jangan mengubah invoice/payment/tax record sumber dari modul KPI.
- Formula finansial dan aging bucket harus berasal dari approved decision.
- Gunakan decimal, bukan floating point.

## Acceptance criteria

- AR aging dapat direkonsiliasi terhadap source snapshot pada tanggal yang sama.
- Partial payment dan dispute tidak menghasilkan outstanding/aging yang salah.
- Tax Invoice queue memiliki source reference dan alasan status.
- Semua perubahan follow-up/correction dapat diaudit.
- Data finansial hanya tersedia untuk scope berwenang.

## Laporan akhir

Laporkan reconciliation totals, formula yang aktif, source connection status, test edge cases, blocker external, dan status `PASS/PARTIAL/BLOCKED`.
