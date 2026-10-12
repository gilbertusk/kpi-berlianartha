# Prompt Step 00 — Validasi Keputusan Bisnis

Anda bertindak sebagai Product Analyst, Business Analyst, dan Solution Architect untuk Sistem KPI PT Berlian Artha Label. Kerjakan hanya tahap validasi kebutuhan dan governance. **Jangan membuat aplikasi atau menulis business rule yang belum disetujui.**

## Konteks wajib

Baca seluruh dokumen berikut:

- `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx`;
- jika DOCX tidak dapat dibaca, gunakan konten pada `scripts/build_prd.py`;
- file keputusan atau catatan bisnis lain yang sudah ada di repository.

Struktur organisasi target adalah Company → Group/Department → Team → Employee, dengan 21 team. Workflow umum adalah target → actual → evidence → verifikasi Team Leader → review Manager → kalibrasi HR → final approval Management → period lock → controlled correction.

## Tujuan

Mengubah semua keputusan terbuka di PRD menjadi decision register yang eksplisit, dapat ditandatangani, serta dapat digunakan developer tanpa menebak aturan HR, KPI, payroll/bonus, approval, atau sumber data.

## Pekerjaan

1. Inventaris seluruh kebutuhan, asumsi, konflik, dan keputusan terbuka dari PRD.
2. Buat `docs/product/DECISION_REGISTER.md` dengan ID stabil, pemilik keputusan, pilihan, rekomendasi, dampak, status, tanggal keputusan, dan bukti persetujuan.
3. Minimal masukkan keputusan berikut:
   - perbedaan Design A dan Design B;
   - approver dan pengganti untuk setiap team;
   - bobot per jabatan/team;
   - rating band, score cap, toleransi, pembulatan, aturan N/A, dan missing data;
   - KPI wajib rilis inti versus kandidat;
   - target, formula, unit, arah pencapaian, frekuensi, sumber, owner, dan evidence setiap KPI aktif;
   - team pilot serta jumlah pengguna dan volume data;
   - sistem sumber dan metode integrasi;
   - SLA input, approval, correction, escalation, dan corrective action;
   - retention, RPO, RTO, availability, maintenance window, browser, serta klasifikasi data;
   - akses coaching notes dan evidence sensitif;
   - hubungan skor KPI dengan bonus atau keputusan kompensasi.
4. Buat `docs/product/KPI_DEFINITION_TEMPLATE.md` untuk workshop KPI. Field minimal: kode, nama, tujuan, objek penilaian, owner, unit, tipe data, arah, target, formula, contoh hitung, frekuensi, bobot, toleransi, cap, sumber, metode input, evidence, approver, effective date, exception, dan reconciliation owner.
5. Buat `docs/product/ROLE_APPROVAL_MATRIX.md` yang memetakan peran, scope organisasi, aksi, approver, delegation, serta larangan self-approval.
6. Buat `docs/product/UAT_SIGNOFF_MATRIX.md` yang menyebut pihak pemberi persetujuan untuk setiap domain.
7. Bedakan keputusan `APPROVED`, `PROPOSED`, `OPEN`, dan `DEFERRED`. Jangan mengubah `PROPOSED` menjadi `APPROVED` tanpa bukti.
8. Buat `PROJECT_PROGRESS.md` bila belum ada dan catat hasil step ini secara append-only.

## Aturan keras

- Jangan mengarang KPI, angka target, bobot, rating, atau SLA.
- Jangan memasukkan formula draft ke kode produksi.
- Jangan menyamakan keputusan teknis dengan persetujuan bisnis.
- Jika pengguna belum memberi keputusan, tulis sebagai `OPEN` beserta dampak dan pihak yang harus menjawab.
- Area yang belum diputus boleh memiliki placeholder konfigurasi, tetapi tidak boleh dianggap siap UAT.

## Acceptance criteria

- Seluruh keputusan terbuka PRD mempunyai ID dan owner.
- KPI yang diusulkan dapat dibedakan dari KPI yang resmi aktif.
- Approval chain setiap team dapat divalidasi atau jelas berstatus terbuka.
- Tidak ada aturan kompensasi yang diasumsikan.
- Dokumen dapat digunakan dalam workshop dan ditandatangani Management, HR, unit owner, serta IT.

## Laporan akhir

Berikan ringkasan file yang dibuat, jumlah keputusan per status, daftar blocker, keputusan yang paling berisiko, dan rekomendasi urutan workshop. Tandai step `PASS` hanya jika seluruh keputusan yang dibutuhkan untuk fondasi teknis tersedia; jika belum, tandai `PARTIAL` dan jelaskan bagian coding mana yang tetap aman dimulai.
