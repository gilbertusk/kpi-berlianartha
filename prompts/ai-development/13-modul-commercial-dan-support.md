# Prompt Step 13 — Modul Commercial dan Support

Anda bertindak sebagai Product Engineer untuk unit Commercial dan Support. Implementasikan kebutuhan khusus Sales, Customer Service, R&D Costing, Design A/B, Admin PPIC, Purchasing, HR, dan GA di atas fondasi KPI yang sudah ada.

## Prasyarat

KPI candidate pada PRD tidak otomatis aktif. Baca decision register, approved KPI definitions, source ownership, data classification, organization scope, dan `PROJECT_PROGRESS.md`. Jika perbedaan Design A/B belum disetujui, pertahankan sebagai dua team tetapi jangan mengarang workflow berbeda.

## Pekerjaan

1. Buat view/model operasional hanya untuk use case yang disetujui; jangan membuat tabel terpisah untuk setiap nama KPI bila generic KPI actual sudah cukup.
2. Implementasikan Sales dashboard yang disetujui: target/realisasi per sales, quotation pipeline, customer pasif, low-margin order, forecast-versus-actual, serta complaint per sales/customer. Lindungi margin dan data customer sesuai scope.
3. Implementasikan Customer Service tracking untuk request/order handover/complaint jika menjadi sumber KPI resmi. Simpan source reference dan hindari menggandakan master order.
4. Implementasikan Costing comparison antara estimated dan actual production dengan versioned assumptions, BOM/routing reference, serta reconciliation. Jangan menjadikan data costing hasil estimasi sebagai actual produksi.
5. Implementasikan Design revision categories: internal error, customer change, Sales change, dan production issue. KPI kesalahan internal tidak boleh memasukkan revisi eksternal.
6. Admin PPIC pada step ini hanya workflow administrative handover; PPIC production board dibuat pada Step 15.
7. Purchasing, HR, dan GA menggunakan generic KPI platform kecuali ada approved operational register tambahan. Lindungi data personal HR dan coaching notes secara lebih ketat.
8. Buat role-specific screens, filter, audit, import adapter boundary, serta links ke generic scorecard.
9. Tambahkan test per team, reason-category attribution, costing comparison, sensitive access, cross-team denial, dan reconciliation.
10. Perbarui dokumentasi data owner/source dan `PROJECT_PROGRESS.md`.

## Guardrail

- Jangan membangun CRM, ERP purchasing, atau HRIS lengkap di dalam sistem KPI.
- Gunakan reference/read model atau integration adapter untuk sistem sumber.
- Jangan mengaktifkan seluruh kandidat KPI hanya karena tercantum pada lampiran PRD.
- Hindari penyimpanan data customer/personal yang tidak dibutuhkan.

## Acceptance criteria

- Kebutuhan khusus yang disetujui terhubung ke KPI definition dan source record.
- Attribution revisi Design dapat diaudit.
- Costing-versus-actual dapat direkonsiliasi tanpa mencampur versi.
- Data Sales/HR tidak bocor ke role lain.
- Fitur generik tidak diduplikasi menjadi sembilan implementasi terpisah.

## Laporan akhir

Laporkan fitur per team, yang menggunakan generic platform, data source yang benar-benar tersedia, deferred item, test evidence, dan status `PASS/PARTIAL/BLOCKED`.
