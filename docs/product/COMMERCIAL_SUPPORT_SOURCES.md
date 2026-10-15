# Commercial dan Support — fitur, sumber data, dan pemilik (Step 13)

Dokumen ini mencatat apa yang dibangun untuk sembilan team Commercial dan Support, data apa yang benar-benar tersedia, dan apa yang ditunda karena keputusannya belum ada. Kandidat KPI pada lampiran PRD **tidak** diaktifkan.

Status keputusan yang menentukan (lihat `DECISION_REGISTER.md`): DEC-001 (Design A/B), DEC-005 (KPI rilis), DEC-006 (definisi KPI), DEC-008 (integrasi), DEC-010 (klasifikasi dan retensi), DEC-016 (system of record dan owner) — semuanya **OPEN**.

## 1. Ringkasan per team

| Team | Yang dibangun | Platform | Sumber data yang tersedia | Ditunda (alasan) |
|---|---|---|---|---|
| Sales | — | KPI generik (definisi, target, aktual manual/import, scorecard, laporan) | Input manual atau import aktual Step 08 | Dashboard Sales (target/realisasi per sales, pipeline quotation, customer pasif, order margin rendah, forecast vs aktual, complaint): butuh CRM/ERP sebagai sumber (DEC-008, DEC-016), klasifikasi data margin dan customer (DEC-010), dan KPI yang disetujui (DEC-005/006) |
| Customer Service | — | KPI generik | Input manual atau import aktual | Tracking request, handover order, complaint: prompt mensyaratkan hanya bila menjadi sumber KPI resmi; belum diputuskan, dan master order tidak boleh digandakan (DEC-016) |
| R&D Costing | Register **Costing vs aktual produksi** | Register operasional + laporan | Dicatat team atau lewat adapter sumber | Adapter ERP/produksi (DEC-008); formula KPI ketepatan estimasi (DEC-006) |
| Design A | Register **Revisi Design** dengan kategori penyebab | Register operasional + laporan | Dicatat team atau lewat adapter design tracker | Formula KPI revisi (DEC-006); perbedaan proses A/B (DEC-001) |
| Design B | Sama dengan Design A, terpisah per team | Register operasional + laporan | Sama | Sama |
| Admin PPIC | — | KPI generik | Input manual atau import aktual | Workflow handover administratif: belum ada sumber dan aturan yang disetujui; board PPIC adalah Step 15 |
| Purchasing | — | KPI generik | Input manual atau import aktual | Register operasional tambahan belum disetujui; tidak membangun ERP purchasing |
| HR | — | KPI generik | Input manual atau import aktual | Tidak membangun HRIS; data personal dan coaching notes dilindungi (Step 11, DEC-011) |
| GA | — | KPI generik | Input manual atau import aktual | Register operasional tambahan belum disetujui |

"KPI generik" adalah fondasi Step 06–12: definisi KPI berversi, template, periode, target, aktual dengan bukti dan import, scorecard, approval, dashboard, dan laporan. Tidak ada salinan fitur itu per team.

## 2. Register operasional

Satu tabel (`operational_records`) dan satu layar (`operations.index`) untuk semua jenis catatan. Setiap jenis mendefinisikan isian dan aturannya sendiri; penyimpanan, akses, audit, pembatalan, laporan, dan adapter dipakai bersama. Jenis baru hanya ditambahkan untuk kebutuhan team yang aturannya disetujui.

Aturan bersama:

- **Tidak diubah atau dihapus.** Kesalahan dibatalkan dengan alasan lalu dicatat ulang (trigger database). Atribusi setiap hitungan dapat diaudit.
- **Referensi sumber.** Setiap catatan menyimpan sistem sumber (`manual` atau kunci adapter) dan referensi yang diturunkan dari isinya. Satu team tidak dapat memiliki dua catatan berlaku dengan jenis dan referensi yang sama, baik diketik maupun diimpor; team lain tidak saling menghalangi.
- **KPI terkait.** Catatan dapat menunjuk definisi KPI yang ia dukung. Catatan **tidak** otomatis menjadi aktual KPI: bagaimana catatan menjadi nilai aktual adalah aturan definisi KPI (DEC-006).
- **Akses.** Mencatat dan membatalkan: `operational_record.manage` atas team (Team Leader, Department Head). Membaca: `operational_record.view` lewat role yang diterima jenis catatan, atas unit catatan.
- **Audit** mencatat jenis, unit, tanggal, dan referensi sumber, **bukan** isinya; angka biaya tidak sampai ke pembaca laporan audit.
- **Adapter sumber** (`OperationalRecordSource`): konektor mengirim catatan yang divalidasi, dideduplikasi, dan diaudit seperti input manual; catatan untuk team di luar scope pengimpor ditolak. Belum ada konektor nyata (DEC-008).

### Revisi Design

| Isian | Aturan |
|---|---|
| Referensi pekerjaan/artwork | Wajib, teks dari sistem sumber |
| Revisi ke- | Bilangan bulat ≥ 1 |
| Kategori penyebab | Kesalahan internal, Perubahan customer, Perubahan Sales, Masalah produksi (PRD lampiran) |
| Keterangan | Wajib |

Hanya **kesalahan internal** yang dibebankan ke team Design. Laporan "Atribusi revisi Design" menampilkan jumlah per kategori dan kolom "dibebankan ke team" yang hanya berisi kesalahan internal; setiap angka sama dengan jumlah baris laporan "Revisi Design" dengan filter yang sama. Design A dan Design B memakai jenis yang sama dan dipisahkan oleh team; tidak ada workflow berbeda yang diasumsikan.

### Costing vs aktual produksi

| Isian | Aturan |
|---|---|
| Referensi costing, versi asumsi, referensi produksi | Wajib, kode tanpa spasi |
| Referensi BOM, routing | Opsional |
| Komponen | Material, Waktu mesin, Tenaga kerja (PRD lampiran) |
| Satuan | Wajib, sama untuk estimasi dan aktual |
| Estimasi, aktual produksi | Desimal tidak negatif, maks. 4 desimal |

- Satu catatan = satu komponen dari satu costing, satu versi asumsi, dan satu produksi. Laporan mengurutkan per costing dan versi sehingga versi tidak bercampur.
- Selisih = aktual − estimasi; selisih relatif = selisih ÷ estimasi × 100 (4 desimal, half-up); tidak ada untuk estimasi nol. Ini aritmetika perbandingan, bukan skor KPI.
- Estimasi tidak pernah dicatat sebagai aktual produksi.
- Angka biaya terbatas: dibaca Management, Department Head, Team Leader dalam scope, dan Auditor. **HR Performance tidak membacanya.**

## 3. Pemilik data dan sumber (usulan, belum disetujui)

| Data | Pemilik yang diusulkan | System of record | Status |
|---|---|---|---|
| Revisi Design | Team Leader Design A / Design B | Register operasional (sementara), atau design tracker bila ada | DEC-016 OPEN |
| Costing dan aktual produksi | Team Leader R&D Costing; aktual dari produksi/PPIC | ERP/produksi | DEC-008, DEC-016 OPEN |
| Order, quotation, customer, margin | Sales / Customer Service | CRM/ERP | DEC-008, DEC-016 OPEN — tidak disimpan di sistem KPI |
| Data karyawan | HR | HRIS | DEC-016 OPEN — sistem KPI hanya menyimpan yang diperlukan (Step 05) |

## 4. Catatan kebijakan yang perlu dikonfirmasi

- Siapa pun yang mengelola team (Team Leader, Department Head) dapat mencatat jenis apa pun untuk team-nya, dan team Design mengkategorikan revisinya sendiri. Apakah kategori perlu diverifikasi pihak lain belum diputuskan.
- `operational_record.view` dapat didelegasikan seperti capability baca lain; delegate tetap terbatas pada role dan unit pemberi delegasi.
- Catatan boleh bertanggal mundur (hanya tanggal masa depan yang ditolak); batas terhadap periode yang sudah dihitung atau dikunci menyusul saat catatan dipakai sebagai nilai aktual.
- Audit mencatat referensi sumber (misalnya kode costing dan work order), tanpa angka.

## 5. Yang belum terbukti

- Belum ada KPI Commercial/Support yang disetujui atau dipublish, sehingga tidak ada nilai resmi yang terhubung ke catatan.
- Belum ada adapter ke sistem sumber mana pun.
- Register belum diuji dengan volume nyata.
