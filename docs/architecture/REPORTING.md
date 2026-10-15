# Laporan dan Ekspor (Step 12)

Modul `app/Modules/Reporting` menyediakan katalog laporan, pratinjau di layar, dan ekspor Excel/PDF yang dibuat di antrean. Satu definisi laporan dipakai untuk pratinjau **dan** ekspor, sehingga file tidak pernah memuat sesuatu yang tidak tampil di layar.

Mekanismenya lengkap dan teruji. Yang belum ada adalah keputusan bisnisnya: tanpa policy scorecard resmi (DEC-004) laporan skor resmi kosong, ambang KPI merah belum disetujui, dan kebijakan retensi serta klasifikasi data belum diputuskan (DEC-010).

## 1. Katalog laporan

| Kunci | Laporan | Sumber | Scope pembaca | Klasifikasi |
|---|---|---|---|---|
| `scorecards` | Scorecard per subjek (karyawan, team, department, perusahaan) | Perhitungan terakhir per subjek, kalibrasi, status approval | Scorecard | Terbatas |
| `kpi_lines` | Target versus aktual, tren, dan KPI merah | Baris perhitungan terakhir; skor KPI periode sebelumnya | Scorecard | Terbatas |
| `rating_distribution` | Distribusi rating | Rating perhitungan lengkap terakhir, per unit | Scorecard | Internal |
| `completeness` | Kelengkapan dan data yang belum masuk | Target wajib dan status entri aktualnya | `actual.view` | Terbatas |
| `approvals` | Status approval dan yang menunggu | Review scorecard, approver snapshot, gap | Scorecard | Terbatas |
| `approval_history` | Riwayat keputusan approval | Riwayat keputusan append-only | Scorecard | Terbatas |
| `corrections` | Koreksi aktual | Permintaan koreksi dan keputusannya | Koreksi + scorecard individu | Terbatas |
| `corrective_actions` | Corrective action dan yang melewati tenggat | Tindakan team review | `team_review.view` | Terbatas |
| `kpi_definitions` | Definisi dan versi KPI periode | Versi definisi yang dikunci target periode | `kpi_definition.view` dalam `target.view` | Internal |
| `calculations` | Rekonsiliasi perhitungan | Seluruh perhitungan tersimpan (semua urutan) | Scorecard | Terbatas |
| `design_revisions` | Revisi Design (Step 13) | Register operasional | Jenis catatan | Internal |
| `design_revision_attribution` | Atribusi revisi Design per team dan kategori (Step 13) | Register operasional | Jenis catatan | Internal |
| `costing_comparisons` | Costing vs aktual produksi (Step 13) | Register operasional | Jenis catatan (tanpa HR) | Terbatas |
| `ar_aging` | Aging piutang per invoice (Step 14) | Snapshot piutang | `accounting_data.view` (unit Accounting) | Terbatas |
| `ar_aging_summary` | Ringkasan aging dan rekonsiliasi piutang (Step 14) | Snapshot piutang | `accounting_data.view` | Terbatas |
| `tax_invoices` | Faktur pajak dan invoice tanpa faktur (Step 14) | Snapshot piutang | `accounting_data.view` | Terbatas |
| `production_actuals` | Aktual produksi, packing, dan pengiriman versi berlaku (Step 15) | Batch produksi | `production_data.view` | Internal |
| `warehouse_stock` | Stok warehouse per kategori lokasi (Step 15) | Batch warehouse | `production_data.view` | Internal |
| `ppic_work_orders` | Work order dan status board PPIC (Step 15) | Batch work order + riwayat board | `production_data.view` (unit Produksi/PPIC) | Internal |
| `audit_log` | Laporan audit | Audit log pada rentang tanggal (maks. 92 hari) | `audit_report.view` seluruh perusahaan | Terbatas |

"Scope scorecard" adalah scope yang sama dengan halaman scorecard: scorecard unit dalam scope `scorecard.view`, scorecard karyawan dalam scope `individual_scorecard.view` (HR dan Auditor), dan tidak pernah scorecard pembaca sendiri. Akibatnya Management, Department Head, dan Team Leader hanya mendapat baris scorecard unit.

Catatan isi:

- **Rekonsiliasi.** Skor pada `scorecards` adalah `final_score` perhitungan terakhir; "skor berlaku" adalah skor kalibrasi putaran berjalan bila ada. `completeness` memakai klasifikasi yang sama dengan ringkasan kelengkapan halaman aktual. Test membandingkan laporan dengan snapshot dan halaman scorecard.
- **Tren** adalah skor KPI subjek yang sama pada periode sebelumnya dengan tipe dan scope yang sama. Tidak ada rata-rata atau roll-up.
- **KPI merah** memakai `dashboard.red_kpi_score_below`. Selama ambang belum disetujui (DEC-004), kolomnya berisi "ambang belum disetujui" dan filter "hanya KPI merah" ditolak.
- **Approval yang menunggu** menampilkan umur tunggu dalam hari. Tidak ada label "overdue" untuk approval karena SLA belum disetujui (DEC-009). Overdue corrective action dihitung dari tenggat yang ditetapkan team.
- **Rentang laporan audit** maksimal 92 hari termasuk kedua tanggalnya.
- **Laporan audit** menampilkan nilai sebelum/sesudah sebagaimana tersimpan di audit log (sudah disanitasi saat ditulis).
- **Shift dan mesin** tidak menjadi filter laporan skor: KPI belum memakai data produksi (DEC-006, DEC-015). Laporan `production_actuals` (Step 15) menampilkan shift dan mesin sebagai kolom.

## 2. Filter

`ReportFilters` memvalidasi bentuk setiap nilai sebelum ada query: periode (ULID), kode unit, tingkat, NIK, kode KPI, status, sumber, jenis record, dan tanggal. Nilai yang tidak sesuai pola, kunci yang tidak dikenal, dan filter yang tidak ditawarkan laporan tersebut **ditolak**, bukan diabaikan.

Apakah sebuah nilai berada dalam scope diputuskan oleh laporan: filter unit diterjemahkan menjadi unit itu beserta unit di bawahnya dan harus berada dalam scope pembaca. Unit di luar scope dan unit yang tidak ada ditolak dengan cara yang sama (403).

## 3. Ekspor

```
permintaan ──▶ queued ──▶ running ──▶ completed ──▶ expired (file dihapus)
                  ▲           │
                  └─ retry ── failed
```

- **Asynchronous.** Semua ekspor dibuat oleh job `GenerateReportExport` (3 percobaan, backoff 30 dan 120 detik, timeout 75 detik — sengaja di bawah `retry_after` antrean 90 detik agar job yang masih berjalan tidak diberikan ke worker kedua). Halaman menampilkan status dan jumlah baris yang sudah ditulis, dan memuat ulang daftar tiap 5 detik selama ada ekspor berjalan.
- **Idempotent.** Permintaan identik (akun, laporan, format, filter) yang masih antre atau berjalan mengembalikan ekspor yang sama; indeks unik parsial menjaganya di database. Job hanya mengambil ekspor berstatus `queued`, dan dua salinan job untuk ekspor yang sama tidak berjalan bersamaan. Retry hanya berlaku untuk ekspor `failed`.
- **Akses dievaluasi ulang.** Job berjalan sebagai akun peminta dengan akses akun itu **saat job berjalan**. Bila akses sudah dicabut, ekspor gagal dengan alasan `not_authorized` dan tidak ada file yang dibuat.
- **Alasan gagal yang aman.** Pengguna hanya melihat salah satu pesan tetap (`ReportExport::FAILURES`). Log menyimpan nama kelas exception saja.
- **Worker mati.** Ekspor yang masih `running` lebih dari 10 menit dianggap ditinggal worker: perintah terjadwal menandainya gagal (dapat dicoba ulang pemiliknya) dan menghapus file sementara yang tertinggal.
- **Batas.** Maksimal 100.000 baris per ekspor, 500 baris untuk PDF, dan 3 ekspor berjalan per akun. Ini batas teknis; ekspor yang butuh lebih dari 75 detik memerlukan koneksi antrean dengan `retry_after` lebih panjang, yang diputuskan bersama pengukuran Step 19.

### Format

- **Excel (.xlsx)** ditulis baris demi baris dengan streaming (openspout): sheet `Info` berisi metadata dan sheet `Data` berisi baris laporan. Semua sel berupa teks sehingga desimal tersimpan persis.
- **PDF** (dompdf, A4 landscape) untuk laporan sampai 500 baris. PDF disusun di memori, sehingga laporan besar ditolak dengan saran memakai Excel.

### Metadata pada setiap ekspor

Nama dan kode laporan, periode, filter, scope organisasi, waktu pembuatan beserta timezone, pembuat, keterangan template/versi (tercantum per baris), klasifikasi, dan ID ekspor.

Klasifikasi `Internal` / `Terbatas` adalah pembeda kerja, bukan kebijakan: skema klasifikasi data belum disetujui (DEC-010).

### Penyimpanan dan unduhan

- File ditulis ke file sementara lokal, diunggah ke disk private (`reports.disk`, default `s3`) dengan nama yang dibuat server (`report-exports/YYYY/MM/{ULID}.{ext}`), lalu file sementara dihapus, juga saat gagal. Tidak ada bagian permintaan yang masuk ke nama objek.
- Unduhan hanya lewat route bertanda tangan yang berlaku 5 menit dan terikat ke akun pemilik. Saat mengunduh, sistem memeriksa: tanda tangan dan masa berlakunya, akun = pemilik, ekspor selesai dan belum kedaluwarsa, dan **akun masih berhak atas laporan dengan filter tersebut**.
- Setiap permintaan, penyelesaian, kegagalan, unduhan, dan penghapusan ekspor dicatat di audit log, termasuk checksum SHA-256 file.
- **Retensi file:** 72 jam, lalu `reports:purge-expired-exports` (tiap jam) menghapus file dan menandai ekspor kedaluwarsa. Ekspor kedaluwarsa menjawab 410. Angka ini batas teknis penyimpanan file hasil ekspor, **bukan** kebijakan retensi data (DEC-010); record sumber tidak terpengaruh.

## 4. Pengamanan

| Risiko | Pengendalian |
|---|---|
| Formula injection | `CellSanitizer` memberi awalan apostrof pada teks yang diawali `=`, `+`, `-`, `@`, tab, atau carriage return (juga setelah spasi awal) dan membuang karakter kontrol serta UTF-8 tidak valid. Desimal biasa, termasuk negatif, dibiarkan. Sel dipotong pada 32.000 karakter |
| Path traversal | Filter divalidasi dengan pola ketat; nama objek dan file dibuat server |
| Ekspor tanpa hak | Otorisasi saat meminta, saat job berjalan, dan saat mengunduh |
| Akses objek langsung | Disk private (disk publik ditolak saat service dibuat); hanya route bertanda tangan, terikat pemilik, yang melayani file |
| Kebocoran lintas scope | Scope diterapkan di query setiap laporan; filter di luar scope ditolak |
| PDF | Semua teks di-escape; resource remote, PHP, dan JavaScript dimatikan |
| Tautan lama | Tanda tangan kedaluwarsa setelah 5 menit; ekspor kedaluwarsa setelah retensi |

## 5. Performa

- Laporan dihasilkan sebagai generator dengan `lazyById` dan pemuatan relasi per chunk (200–500 baris); jumlah query tidak bergantung pada jumlah baris di dalam satu chunk. Test regresi membatasi jumlah query lima laporan utama (≤ 25) pada data skenario.
- **Uji volume (sintetis).** Menulis 50.000 baris × 18 kolom ke .xlsx pada mesin pengembangan: sekitar 9 detik, tambahan memori puncak di bawah 1 MB, ukuran file 2,9 MB. Ini mengukur penulis ekspor dengan baris buatan, **bukan** dataset representatif: ukuran pilot belum diputuskan (DEC-007) dan target performa belum disetujui. Query laporan pada data besar belum diukur.
- Tidak ada materialized view: belum ada pengukuran yang membenarkannya.

## 6. Capability

| Capability | Role | Catatan |
|---|---|---|
| `report.view` | Management, Department Head, Team Leader, HR Performance, Auditor | Membuka katalog. Setiap laporan tetap menerapkan capability dan scope datanya sendiri. Mengekspor adalah bentuk lain dari membaca |
| `audit_report.view` | HR Performance, Auditor | Harus mencakup seluruh perusahaan. Tidak dapat didelegasikan |

Karyawan dan System Admin tidak memiliki laporan.

## 7. Keputusan yang belum ada dan batasan

- **DEC-004** skor resmi dan ambang KPI merah. **DEC-009** SLA approval. **DEC-010** retensi, klasifikasi, dan kontrol ekspor. **DEC-007** ukuran data pilot dan target performa. **DEC-015/016** shift dan mesin.
- Ekspor scorecard CSV sinkron dari Step 09 tetap ada.
- PDF hanya untuk laporan sampai 500 baris; pemakaian memorinya belum diukur.
- `corrections` menampilkan koreksi atas hasil pegawai kepada Management (yang memang memutuskannya di antrean koreksi), dan `completeness` menampilkan target per pegawai kepada pemegang `actual.view` (sama dengan halaman aktual). Keduanya mengikuti scope halaman yang sudah ada, bukan aturan scorecard individu; perlu dikonfirmasi bersama DEC-002.
- Pratinjau laporan tidak dicatat di audit; ekspor dan unduhan dicatat.
- Laporan belum diuji dengan data yang melampaui satu chunk (200–500 baris) selain uji volume penulis.
- Tidak ada penjadwalan laporan berkala atau pengiriman laporan lewat email.
- Storage S3-compatible hanya diuji dengan disk palsu pada test; uji terhadap object storage nyata bagian Step 18/20.
