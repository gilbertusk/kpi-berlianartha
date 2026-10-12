# Prompt Step 15 — Modul Produksi, PPIC, dan Warehouse

Anda bertindak sebagai Manufacturing Product Engineer. Implementasikan PPIC board, production actual dimensions, warehouse reference, dan metric source untuk team Produksi tanpa membangun ulang ERP/MES/WMS penuh.

## Prasyarat

Baca approved KPI definitions, work-order lifecycle, shift calendar, machine/operator master ownership, warehouse location policy, reason-code dictionary, source system contract, dan `PROJECT_PROGRESS.md`. Jika belum disetujui, dokumentasikan contract dan gunakan fixture berlabel non-production.

## Cakupan team

Woven, Warehouse, Screen Print, Quality Control, Packing Logistic, Logistic, Hot Cut, Hang Tag, dan Flexo Printing. Quality Control detail diselesaikan pada Step 16.

## Pekerjaan

1. Implementasikan reference/read model minimal untuk work order, product, process, machine, shift, operator/team, planned quantity/time, actual good/reject/rework, downtime, waste, reason code, dan source reference.
2. Implementasikan PPIC board dengan status minimum sesuai PRD: `WAITING_MATERIAL`, `WAITING_DESIGN`, `READY_FOR_PRODUCTION`, `IN_PRODUCTION`, `QC`, `PACKING`, `READY_TO_DELIVER`, dan `COMPLETED`. Tampilkan schedule, material readiness, process progress, overdue/risk indicator, last source update, customer/order/work-order/need-date/machine/status filter. Setiap transition menyimpan waktu, actor, reason keterlambatan bila berlaku, dan work-order reference. Jangan mengizinkan KPI app mengubah jadwal ERP kecuali integrasi write-back disetujui terpisah.
3. Implementasikan actual capture/import pada dimensi tanggal, shift, machine, operator/team, work order, product, defect, downtime, dan reason code bila relevan.
4. Implementasikan Warehouse location categories: Raw Material, Work in Process, Finished Goods, Reject/Quarantine, Return, dan Scrap. Gunakan reference quantity/snapshot, bukan ledger inventori baru, kecuali PRD diubah.
5. Bedakan Packing Logistic dari Logistic. Packing Logistic menyiapkan produk sebelum pengiriman; Logistic menangani delivery dan proof of delivery.
6. Buat metric-source services untuk output, yield, reject, rework, waste, utilization/OEE, downtime, setup/changeover, schedule attainment, dan productivity hanya berdasarkan formula approved.
7. Tangani late-arriving data dan source correction melalui version/reconciliation; jangan menimpa snapshot final diam-diam.
8. Buat role-scoped views, import monitoring, data freshness, missing reason code, reconciliation, dan links ke scorecard.
9. Tambahkan test shift boundary, overnight shift, duplicate work order event, unit conversion, decimal precision, negative/impossible quantity, late correction, cross-team access, dan reconciliation totals.
10. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- KPI system bukan MES/WMS/ERP baru.
- Jangan menghitung OEE atau yield sebelum formula, denominator, planned downtime, dan unit conversion disetujui.
- Jangan mengasumsikan operator individual dinilai jika kebijakan hanya menilai team/shift.
- Tampilkan source freshness agar data lama tidak terlihat current.

## Acceptance criteria

- PPIC board dan production metrics mempunyai source reference serta freshness timestamp.
- Total good/reject/rework dapat direkonsiliasi pada granularitas yang disepakati.
- Shift lintas tengah malam masuk periode/tanggal operasional yang benar.
- Warehouse dan logistics responsibilities tidak tercampur.
- Correction sumber tidak menghapus histori score final.

## Laporan akhir

Laporkan supported dimensions, source status, reconciliation result, formula yang masih blocked, data-volume test, dan status `PASS/PARTIAL/BLOCKED`.
