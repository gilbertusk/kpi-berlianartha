# Produksi, PPIC, dan Warehouse — read model dan kontrak impor (Step 15)

Sistem KPI **bukan** MES, WMS, atau ERP. Ia menyimpan batch data yang dilaporkan sistem sumber per team dan per tanggal, menghitung jumlah dan rekonsiliasi, dan menyimpan status board PPIC miliknya sendiri. Tidak ada yang ditulis balik ke sistem sumber: jadwal ERP tidak pernah diubah.

Belum ada koneksi ke MES/WMS/ERP (DEC-008, DEC-016 OPEN). Data masuk sebagai file sesuai kontrak di bawah; **integrasi runtime adalah blocker eksternal** (Step 17).

## 1. Yang aktif dan yang belum

| Kebutuhan (prompt Step 15) | Status | Dasar |
|---|---|---|
| Read model work order, produk, proses, mesin, shift, operator/team, rencana, aktual good/reject/rework, downtime, waste, reason code, referensi sumber | Aktif (dari batch file) | PRD §11.1, Lampiran B |
| Board PPIC: 8 status PRD, jadwal, kesiapan material, progres, overdue, emergency, perubahan jadwal, freshness, filter customer/order/WO/tanggal kebutuhan/mesin/status | Aktif | PRD §10.1 |
| Riwayat perpindahan status (waktu, pengguna, alasan terlambat, referensi WO) | Aktif, append-only | PRD §10.1 |
| Impor aktual per tanggal, shift, mesin, operator/team, WO, produk, defect, downtime, reason code | Aktif | PRD §6.2 |
| Kategori lokasi Warehouse (Raw Material, WIP, Finished Goods, Reject/Quarantine, Return, Scrap) | Aktif sebagai snapshot referensi, bukan ledger | PRD Lampiran B |
| Pemisahan Packing Logistic dan Logistic | Aktif (jenis data per team) | PRD Lampiran B |
| Late-arriving data dan koreksi sumber sebagai versi | Aktif | Prompt |
| Monitoring impor, freshness, reason code kosong, rekonsiliasi, tautan scorecard | Aktif | Prompt |
| **Kalender shift** (shift, cut-off, tanggal operasional shift lewat tengah malam) | **BLOCKED** (DEC-015) | Konfigurasi kosong; aktual produksi dan packing tidak dapat diimpor sampai disetujui |
| Output per jam, yield, FPY, reject/rework rate, utilization, OEE, downtime rate, setup/changeover, schedule attainment, produktivitas | **BLOCKED** (DEC-006) | Formula, denominator, planned downtime, konversi satuan belum disetujui |
| Konversi satuan | **BLOCKED** | Jumlah dijaga per satuan; tidak pernah dijumlahkan lintas satuan |
| Penilaian operator individual | **Tidak dibangun** | Kebijakan belum menetapkan; `operator_code` hanya disimpan sebagai referensi sumber |
| Dictionary reason/defect/downtime code | **BLOCKED** (DEC-016) | Kode diterima apa adanya; baris yang wajar memerlukan kode tetapi kosong dihitung sebagai "reason code kosong" |
| Write-back jadwal ke ERP | **Tidak dibangun** | Butuh persetujuan integrasi terpisah |
| Quality Control detail (NCR/CAPA) | Step 16 | — |

## 2. Kalender shift (DEC-015)

`config/production.php` → `shift_calendars` berisi versi kalender bertanggal berlaku. Default **kosong**. Setiap versi wajib menyebut semua hal berikut; tidak ada nilai bawaan:

```php
[
    'version' => 'dec-015-v1',
    'effective_from' => 'YYYY-MM-DD',
    'label' => 'Kalender produksi disetujui ...',
    'overnight_operational_date' => 'start', // atau 'end'
    'shifts' => [
        ['code' => 'S1', 'start' => '07:00', 'end' => '15:00'],
        // shift dengan end < start melewati tengah malam
    ],
]
```

- Shift dalam satu versi tidak boleh tumpang tindih; celah diperbolehkan (waktu di celah ditolak saat impor).
- Waktu pada file adalah waktu bisnis (`APP_TIMEZONE`, default Asia/Jakarta) dan disimpan dalam UTC.
- Shift lewat tengah malam memakai versi kalender yang berlaku pada tanggal shift **dimulai**. Bila dua versi mengklaim menit yang sama, baris ditolak.
- Tanggal operasional shift lewat tengah malam adalah tanggal mulai (`start`) atau tanggal selesai (`end`) sesuai keputusan.
- Test memakai kalender **NON-PRODUCTION FIXTURE** (3 shift 07–15, 15–23, 23–07).

## 3. Jenis data dan tanggung jawab team

| Jenis | Team (default `production.responsibilities`) | Tanggal batch | Butuh kalender shift |
|---|---|---|---|
| `production` (output, downtime, waste) | Woven, Screen Print, Hot Cut, Hang Tag, Flexo Printing | Tanggal operasional | Ya |
| `packing` | Packing Logistic | Tanggal operasional | Ya |
| `delivery` (pengiriman dan proof of delivery) | Logistic | Tanggal kirim | Tidak |
| `warehouse` (stok per lokasi) | Warehouse | Tanggal as-of | Tidak |
| `work_orders` (jadwal, material) | Admin PPIC | Tanggal as-of | Tidak |

Packing Logistic menyiapkan barang sebelum dikirim; Logistic mengirim dan mencatat proof of delivery. Team tidak dapat memuat jenis data team lain.

## 4. Kontrak file

CSV (UTF-8, koma atau titik koma) atau XLSX dengan baris judul. Kuantitas positif atau nol, titik desimal, maks. 3 desimal. Kode tanpa spasi. Satuan berupa kode huruf kecil (`pcs`, `m`, `kg`).

| Jenis | Kolom (wajib **tebal**) | Aturan utama |
|---|---|---|
| production | **record_type**, **event_id**, work_order, product_code, process_code, machine_code, operator_code, shift_code, **started_at**, ended_at, unit, good_qty, reject_qty, rework_qty, waste_qty, defect_code, reason_code | `output`: work_order, unit, salah satu kuantitas > 0. `downtime`: machine_code, ended_at > started_at, ≤ 24 jam. `waste`: unit, waste_qty > 0. Shift dan tanggal operasional dari kalender; shift_code yang diisi harus cocok; baris tanggal operasional lain ditolak |
| packing | **event_id**, **work_order**, product_code, **packed_at**, shift_code, **unit**, **packed_qty**, repacked_qty, reason_code | packed_qty > 0 |
| delivery | **delivery_number**, **work_order**, customer_code, **shipped_date**, **unit**, **shipped_qty**, delivered_date, pod_reference | shipped_date = tanggal batch; delivered_date ≥ shipped_date dan tidak di masa depan; pod_reference memerlukan delivered_date |
| warehouse | **location_code**, **location_category**, **item_code**, lot, **unit**, **quantity** | Kategori: `raw_material`, `work_in_process`, `finished_goods`, `reject_quarantine`, `return`, `scrap` |
| work_orders | **work_order**, order_number, customer_code, product_code, process_code, machine_code, **planned_qty**, **unit**, planned_start, planned_end, **need_date**, **material_status**, design_status, priority | material_status `ready`/`partial`/`not_ready`; design_status `approved`/`pending`/`not_required`; priority `normal`/`emergency` |

Waktu ekstraksi dari sistem sumber wajib diisi saat impor dan ditampilkan sebagai freshness.

## 5. Siklus batch, versi, dan rekonsiliasi

- `staged` → `committed` atau `rejected`. Semua masalah per baris disimpan (maks. 200 ditampilkan); file bermasalah tidak dapat di-commit. Maks. 20.000 baris per file.
- File yang sama (hash SHA-256) untuk sistem sumber yang sama mengembalikan batch yang sudah ada; untuk team, jenis, atau tanggal lain ditolak; file dari batch yang ditolak boleh diimpor ulang.
- File baru untuk team, jenis, sumber, dan tanggal yang sama menjadi **versi berikutnya**. Versi committed tertinggi adalah versi berlaku; versi lama tetap ada dan audit commit mencatat versi yang digantikan. Batch final beserta barisnya tidak dapat diubah, dihapus, atau ditambah (trigger PostgreSQL dan SQLite).
- Event (output, downtime, waste, packing, pengiriman) dihitung sekali: event yang sudah ada di batch berlaku tanggal lain dari team dan sumber yang sama ditolak.
- Total kontrol dari sumber (kuantitas utama; reject dan rework untuk produksi) harus sama persis; bila file berisi beberapa satuan, total kontrol tidak dapat dibandingkan dan commit ditolak.
- Rekonsiliasi batch produksi: rincian per shift × mesin × satuan dijumlahkan kembali dan harus sama dengan total batch per satuan.
- Nilai KPI dan skor final tidak dihitung dari batch ini selama formula belum disetujui, sehingga koreksi sumber tidak menyentuh histori skor.

## 6. Board PPIC

- Work order, jadwal, dan material dibaca dari batch `work_orders` berlaku terbaru milik Admin PPIC. Perubahan jadwal ditandai dengan membandingkan batch tanggal sebelumnya (mulai, selesai, kebutuhan, mesin).
- Status board: `WAITING_MATERIAL`, `WAITING_DESIGN`, `READY_FOR_PRODUCTION`, `IN_PRODUCTION`, `QC`, `PACKING`, `READY_TO_DELIVER`, `COMPLETED`. PRD tidak menetapkan transisi yang diizinkan; semua perpindahan dicatat. **Alasan wajib** bila work order sudah melewati tanggal kebutuhan atau dipindah ke status sebelumnya (kontrol audit, bukan aturan skor).
- Overdue = tanggal kebutuhan sebelum hari ini dan status belum `COMPLETED`.
- Progres = output good berlaku untuk WO pada satuan yang sama dengan rencana; output satuan lain disebut tanpa dikonversi.
- Konkurensi: perpindahan menyebut status yang dilihat pengguna; perubahan oleh orang lain di antaranya ditolak.

## 7. Akses

| Capability | Role | Catatan |
|---|---|---|
| `production_data.view` | Management, Department Head, Team Leader, HR Performance, Auditor | Dibatasi ke subtree department Produksi (`PRODUCTION_DEPARTMENT_CODE`, default `PROD`) dan team PPIC (`PPIC_UNIT_CODE`, default `COMSUP-ADMIN-PPIC`) dalam scope role |
| `production_data.manage` | Department Head, Team Leader | Impor, commit, tolak batch untuk team dalam scope dan hanya jenis data tanggung jawab team |
| `ppic_board.manage` | Department Head, Team Leader | Hanya atas team PPIC |

Board dapat dibaca siapa pun yang membaca data produksi atau PPIC. Team di luar Produksi dan PPIC (mis. Sales, Accounting) tidak memiliki akses. Laporan: `production_actuals`, `warehouse_stock`, `ppic_work_orders` (klasifikasi Internal).

## 8. Keputusan terbuka

- DEC-015: kalender shift, cut-off, tanggal operasional.
- DEC-006: formula metrik produksi, denominator, planned downtime, konversi satuan.
- DEC-008/DEC-016: sistem sumber dan pemilik master (mesin, produk, reason/defect/downtime code, lokasi).
- Apakah staf Admin PPIC dan Admin Produksi (role Karyawan) boleh memindahkan status board atau mengimpor batch; saat ini hanya Team Leader dan Department Head.
- Granularitas rekonsiliasi yang disepakati (saat ini per batch dan per shift × mesin × satuan).
- Kebijakan penilaian operator individual (saat ini tidak dinilai).
