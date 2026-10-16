# Quality Control, NCR, dan CAPA (Step 16)

Modul `Quality` mencatat nonconformance (NCR) dan tindakan korektifnya (CAPA) sesuai PRD §10.3. Inspeksi dari sistem sumber masuk sebagai batch Step 15 jenis `inspection`. Sistem KPI tidak menetapkan standar mutu, disposition, atau approval baru.

## 1. Yang aktif dan yang belum

| Kebutuhan (prompt Step 16) | Status | Dasar |
|---|---|---|
| Referensi inspeksi incoming, in-process, final | Aktif sebagai batch sumber `inspection` milik team QC | Kontrak file di `PRODUCTION_SOURCES.md` |
| Defect record: kategori, severity, kuantitas/satuan, proses, WO/produk/lot, mesin/shift, waktu ditemukan, sumber, pemilik, bukti | Aktif pada NCR | PRD §10.3 |
| Lifecycle `TEMUAN → CONTAINMENT → ROOT_CAUSE → CORRECTIVE_ACTION → VERIFICATION → CLOSED` | Aktif | PRD §10.3 |
| PIC, tenggat, isi, dan bukti per tahap | Aktif; tenggat diisi pengguna | PRD §10.3 |
| Verification gagal kembali ke Corrective Action; reopen dengan siklus baru; koreksi data NCR dengan nilai sebelum/sesudah | Aktif | Prompt |
| Complaint, return, repeat defect ditautkan ke NCR tanpa menyalin record sumber | Aktif (rujukan); tautan repeat **manual** | Aturan pencocokan otomatis belum ada |
| Dashboard corrective action: overdue, severity, pemilik, KPI terkait (versi), repeat issue, status verifikasi | Aktif | Prompt |
| Bukti terbatas | Aktif | Prompt |
| Skala severity, taxonomy defect, metode root cause | **BLOCKED** (DEC-006, DEC-016) | Konfigurasi kosong: NCR tanpa severity; kategori dan metode diterima sebagai kode |
| Approver per tahap, SLA per tahap | **BLOCKED** (DEC-002, DEC-009) | Tenggat per tahap ditetapkan pengguna; tidak ada SLA otomatis |
| Disposition (use-as-is, rework, scrap, return to supplier) | **Tidak dibangun** | Belum disetujui |
| Preventive action, effectiveness review | **Nonaktif** sampai disetujui | `quality.preventive_action_enabled`, `quality.effectiveness_review_required` |
| Formula rejection rate, first pass yield, complaint rate, return rate, repeat defect, inspection lead time, closure SLA | **BLOCKED** (DEC-006, DEC-009) | Hanya jumlah dan hari lewat tenggat |
| Integrasi QMS, CRM complaint, dan return | **BLOCKED** (DEC-008) | Rujukan teks; inspeksi lewat file |

## 2. State model

| Dari | Ke | Siapa | Syarat |
|---|---|---|---|
| — | TEMUAN | Manager QC (`quality_record.manage` atas team QC) | Sumber, team penanggung jawab, kategori, uraian, waktu ditemukan, pemilik, PIC dan tenggat tahap |
| TEMUAN | CONTAINMENT | Manager QC | PIC dan tenggat tahap berikutnya |
| CONTAINMENT | ROOT_CAUSE | Manager QC, manager team penanggung jawab, atau PIC tahap | Tindakan containment |
| ROOT_CAUSE | CORRECTIVE_ACTION | Sama | Akar masalah; metode hanya dari daftar yang disetujui |
| CORRECTIVE_ACTION | VERIFICATION | Sama | Correction dan corrective action; preventive action hanya bila disetujui |
| VERIFICATION | CLOSED | Manager QC | Hasil verifikasi **dan referensi bukti**; bila effectiveness review diwajibkan, hasilnya harus `effective` |
| VERIFICATION | CORRECTIVE_ACTION | Manager QC | Alasan verifikasi gagal; tahap Verification tercatat `returned` |
| CLOSED | CONTAINMENT / ROOT_CAUSE / CORRECTIVE_ACTION | Manager QC | Alasan; siklus bertambah, siklus lama tetap tersimpan |
| (koreksi data) | tahap tetap | Manager QC | Alasan; nilai sebelum dan sesudah tersimpan |

Tahap lain di luar daftar ini ditolak. Pembagian peran di atas adalah default yang paling sempit selama DEC-002 belum menetapkan approver per tahap.

Integritas:
- Setiap NCR punya paling banyak satu tahap terbuka (indeks unik parsial). Tahap yang sudah selesai tidak dapat diubah, dan NCR, tahap, serta riwayat tidak dapat dihapus (trigger PostgreSQL dan SQLite).
- Semua aksi memakai `lock_version`; perubahan bersamaan oleh orang lain ditolak.
- Audit log mencatat nomor NCR, tahap, dan field yang berubah; isi tahap dan referensi bukti hanya ada di riwayat NCR.

## 3. Ketertelusuran

- Sumber `inspection`: merujuk baris inspeksi pada batch inspeksi QC yang berlaku (`production_record_id`). Work order, produk, lot, satuan, dan kuantitas reject diisi dari inspeksi bila kosong; waktu ditemukan = waktu inspeksi bila kosong.
- Sumber `complaint`, `return`, `internal`: nomor rujukan dari sistemnya; record sumber tidak disalin.
- Satu NCR per kategori defect dari satu sumber (unik `source_type + source_reference + category_code`).
- Repeat defect: tautan manual ke NCR sebelumnya; dashboard dan laporan menampilkan "ulangan dari" dan jumlah ulangan.
- KPI terkait: kode KPI dipetakan ke **versi definisi yang dipublikasikan** saat ditautkan, sehingga NCR menyebut versi yang jelas.

Contoh: inspeksi `INS-1` (in-process, WO-100, lot L-7, reject 20 pcs, defect DF-WEAVE) pada batch QC 2026-10-14 v1 → NCR-2026-0001 → Containment oleh Woven → ... → Closed dengan bukti `QC-REC-2026-118`; complaint `CMP-2026-09` dicatat sebagai NCR-2026-0002 "ulangan dari NCR-2026-0001".

## 4. Overdue dan rekonsiliasi

- Overdue = tenggat tahap terbuka sebelum hari ini (zona waktu bisnis). Hari lewat tenggat = selisih hari kalender.
- Timeline NCR menampilkan setiap tahap dengan tenggat, tanggal selesai, dan hari terlambat, sehingga jumlah overdue di dashboard dapat dicocokkan dengan riwayat.
- Closure SLA belum ada (DEC-009); laporan `quality_ncrs` menampilkan tanggal ditemukan dan tanggal closed.

## 5. Akses dan bukti

| Capability | Role | Catatan |
|---|---|---|
| `quality_record.view` | Management, Department Head, Team Leader, HR Performance, Auditor | NCR terbaca bila scope mencakup team QC atau team penanggung jawab |
| `quality_record.manage` | Department Head, Team Leader | Atas team QC: semua aksi. Atas team penanggung jawab: tahap investigasi |

- PIC suatu tahap (role apa pun) dapat membaca NCR tersebut dan menyelesaikan tahap investigasinya.
- Menu NCR tampil bagi pembaca team QC, team yang punya NCR dalam scope, dan PIC.
- Bukti bertanda **terbatas** hanya terlihat oleh manager QC, manager team penanggung jawab, pencatatnya, dan pemegang `audit_report.view` (Auditor, HR Performance). Pembaca lain melihat "Bukti terbatas".
- Bukti berupa referensi (nomor dokumen, rekaman QC, lokasi arsip), bukan unggahan file, sejalan dengan corrective action Step 11.

## 6. Pengaturan (`config/quality.php`)

| Kunci | Default | Arti |
|---|---|---|
| `qc_unit_code` | `PROD-QC` (`QC_UNIT_CODE`) | Team pemilik workflow |
| `severities` | kosong | Skala severity yang disetujui |
| `defect_categories` | kosong | Taxonomy defect; kosong = kode dari sumber |
| `root_cause_methods` | kosong | Metode root cause; kosong = tuliskan pada uraian |
| `preventive_action_enabled` | `false` | Preventive action sebagai bagian CAPA |
| `effectiveness_review_required` | `false` | Effectiveness review wajib sebelum close |
| `evidence_required_stages` | `['VERIFICATION']` | Tahap yang wajib bukti; Verification selalu wajib |

## 7. Keputusan terbuka

- Skala severity, taxonomy defect, dan metode root cause (DEC-006, DEC-016).
- Approver dan SLA per tahap (DEC-002, DEC-009), termasuk **pemisahan tugas**: saat ini Department Head Produksi (scope mencakup team QC dan team produksi) dapat menyelesaikan Corrective Action sekaligus memverifikasi, dan Team Leader QC dapat menjadi PIC Corrective Action sekaligus verifikator NCR yang sama.
- Disposition, preventive action, effectiveness review, dan aturan pencocokan repeat defect.
- Formula metrik QC dan closure SLA.
- Sistem sumber complaint, return, dan QMS (DEC-008).
