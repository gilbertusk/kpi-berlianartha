# Aktual, Evidence, dan Import Aktual

**Status:** Baseline Step 08 — 13 Oktober 2026
**Referensi:** PRD §6.1–6.4, §7.4, FR 006–008; ADR-0004 (private object storage), ADR-0008; `THREAT_MODEL.md` T-007/T-008; `DATA_CLASSIFICATION.md`; DEC-002, DEC-004, DEC-006, DEC-010, DEC-015, DEC-016

Modul `app/Modules/ActualEvidence` memiliki realisasi KPI, metadata bukti, dan import aktual. Modul ini bergantung pada `Target` dan `PeriodAssignment`.

## 1. Aktual

### Schema

| Tabel | Isi |
|---|---|
| `kpi_actuals` | Satu observasi sebuah target: slot target (periode, versi KPI, subjek), rentang observasi, dan referensi record sumber |
| `kpi_actual_versions` | Nilai yang dinyatakan: jenis entri, nilai `numeric(18,4)`, satuan, sumber, catatan, flag dan alasan terlambat, pelaku tiap tahap, `lock_version` |

Setiap aktual dapat ditelusuri ke target, versi KPI, subjek, periode, pelaku, dan sumbernya lewat `period_target_id`.

### Rentang observasi

- Tanggal mulai dan akhir inklusif, harus berada di dalam periode.
- KPI harian diisi per tanggal. Untuk frekuensi lain rentang diisi eksplisit, karena kalender bisnis belum diputuskan (DEC-015).
- Rentang observasi satu target tidak boleh tumpang tindih.
- Target, rentang, dan record sumber adalah identitas sebuah aktual. Trigger database menolak `UPDATE` dan `DELETE` pada `kpi_actuals`.

### Nol, N/A, dan missing

| Keadaan | Representasi |
|---|---|
| Nilai nol | Entri `value` dengan nilai `0`. Nol adalah nilai |
| Tidak berlaku (N/A) | Entri `not_applicable` tanpa nilai, wajib beralasan |
| Missing | Tidak ada entri sama sekali untuk target tersebut |

Ketiganya tidak pernah disamakan. Cara masing-masing dinilai mengikuti aturan missing-data dan N/A pada definisi KPI (DEC-004) dan dikerjakan pada Step 09. Constraint database (PostgreSQL) menjamin entri `value` selalu memiliki nilai dan `not_applicable` tidak.

### Lifecycle

```text
DRAFT ──submit──▶ SUBMITTED ──verify──▶ VERIFIED
   ▲                  │
   └── REJECTED ◀─────┘ (reject, wajib beralasan)
```

- Aktual diisi dan diverifikasi hanya selama periode berstatus IN_PROGRESS.
- **Satu simpan, semua atau tidak sama sekali**, dengan pesan per baris.
- Nilai divalidasi dengan aturan yang sama seperti target: satuan, jumlah desimal, dan rentang nilai dari definisi KPI.
- **Terlambat.** Setelah deadline pengisian aktual lewat (zona waktu periode), entri dan pengajuan wajib disertai alasan dan ditandai `is_late`. Tanda itu tidak hilang bila deadline digeser.
- **Bukti wajib.** Aktual untuk KPI berbukti wajib tidak dapat diajukan tanpa file bukti, dan tidak dapat diverifikasi bila buktinya tidak dapat dibuka.
- **Verifikator independen.** Verifikator bukan akun yang mengisi, terakhir mengubah, atau mengajukan entri, dan bukan pegawai pemilik aktual.
- **Verified final.** Versi verified menolak semua `UPDATE` dan `DELETE` di level database. Perubahan setelah itu adalah workflow koreksi Step 10. Status Reviewed, Calibrated, dan seterusnya (PRD §6.3) juga bagian Step 10.
- Halaman entri mengirim versi draft yang dilihat pengguna, sehingga simpan dari halaman basi ditolak. Nilai yang diketik untuk entri yang sementara itu sudah diajukan dilaporkan sebagai error, tidak diam-diam dilewati.
- **Ajukan milik sendiri.** Tombol ajukan hanya mengajukan draft yang terakhir diisi akun tersebut, sehingga draft orang lain yang belum selesai tidak ikut terkunci.
- Penolakan memeriksa scope dan independensi di service, bukan hanya di halaman.
- Pengajuan dan verifikasi mengunci baris aktual sebelum membaca bukti, sehingga bukti tidak dapat dilepas di antara pemeriksaan dan perubahan status.

### Record sumber

`source_system` dan `source_reference` diisi bersamaan. Satu record sumber hanya boleh menjadi satu aktual sebuah target (unique index dan validasi). Referensi sumber ditetapkan saat aktual dibuat; entri berikutnya untuk rentang yang sama dengan referensi berbeda ditolak. Kombinasi kunci duplikasi resmi belum disetujui (DEC-016); kombinasi ini mengikuti prompt dan dapat diperketat lewat keputusan.

## 2. Evidence

| Kontrol | Implementasi |
|---|---|
| Penyimpanan | Binary di disk private `evidence.disk` (S3-compatible). PostgreSQL hanya menyimpan metadata, ukuran, dan SHA-256 (`evidence_files`, `stored_object_metadata`) |
| Key objek | Dibuat sistem: `evidence/<periode>/<aktual>/<ulid>.<ext>`. Nama file dari klien tidak pernah menjadi key |
| Nama file | Hanya teks tampilan: path, karakter kontrol, dan karakter berbahaya dibuang |
| Jenis file | Allowlist `pdf`, `png`, `jpg`, `jpeg`, `xlsx`. Ekstensi, MIME hasil deteksi isi (`finfo`), dan signature byte awal harus cocok. MIME dari browser tidak dipakai. File `xlsx` juga harus berstruktur workbook dan tidak boleh memuat macro |
| Batas | Ukuran maksimal 5 MB, maksimal 5 file per aktual, file dengan checksum sama ditolak. Angka ini batas teknis, bukan kebijakan evidence bisnis (DEC-006, DEC-010) |
| Klasifikasi | `confidential` |
| Kapan boleh diubah | Hanya selama entri berstatus draft atau ditolak. Melepas bukti tidak menghapus binary; retention belum diputuskan (DEC-010) |
| Akses | Tautan bertanda tangan yang berlaku 5 menit dan terikat ke akun peminta. Endpoint unduhan memeriksa ulang hak akun atas aktual tersebut, lalu men-stream file. Bucket tidak pernah publik |
| Header unduhan | `attachment`, `X-Content-Type-Options: nosniff`, CSP sandbox, `no-store` |
| Audit | `evidence.attached`, `evidence.removed`, `evidence.downloaded` |

### Pemindaian malware

Belum ada produk antivirus yang dipilih atau diintegrasikan. **Integrasi antivirus adalah blocker untuk penggunaan evidence di staging dan production.**

- Port `MalwareScanner` siap menerima adapter.
- `EVIDENCE_SCANNER=none` (default): setiap file tetap `quarantined`, tidak dapat dibuka, dan tidak meloloskan verifikasi KPI berbukti wajib.
- `EVIDENCE_SCANNER=local-unscanned`: hanya berlaku pada `APP_ENV=local` atau `testing`. File dapat dipakai dan diberi label "belum dipindai", tidak pernah "bersih". Di environment lain, atau untuk nilai yang tidak dikenal, sistem jatuh ke karantina (fail closed) dan menulis peringatan ke log.
- File `infected` tidak pernah dilayani dan menahan pengajuan serta verifikasi.

## 3. Import aktual

Alur: upload → parse → validasi → pratinjau → commit atomik → rekonsiliasi.

| Aspek | Aturan |
|---|---|
| Format | CSV UTF-8 (koma atau titik koma) atau XLSX (sheet pertama), maksimal 1 MB dan 5.000 baris. Baris dihitung saat dibaca. Arsip XLSX yang mengembang melebihi 20 MB atau berisi lebih dari 100 entri ditolak sebelum dibuka |
| Kolom wajib | `kpi_code`, `subject_type`, `subject_code`, `window_start`, `window_end`, `unit`, `actual_value` |
| Kolom opsional | `source_system`, `source_reference`, `note` |
| Nilai | `0` adalah nol; `N/A` berarti tidak berlaku dan wajib punya `note`; sel kosong berarti baris dilewati |
| Hasil per baris | `valid`, `unchanged`, `skipped`, `error` beserta pesannya |
| Duplikat | Rentang observasi sama atau tumpang tindih dalam file; record sumber sama dalam file; record sumber yang sudah diimpor sebagai aktual lain |
| Duplikat file | SHA-256. File yang sudah di-commit ditolak |
| Commit | Hanya bila tidak ada baris error, dalam satu transaksi. Bila aktual pada baris `valid` atau `unchanged` berubah sejak validasi, seluruh commit ditolak |
| Idempotency | `IdempotencyService` dengan kunci batch; commit ulang mengembalikan hasil pertama |
| Rekonsiliasi | Jumlah baris diterapkan dan total nilai file dibandingkan dengan yang tertulis; selisih membatalkan commit. Hasilnya disimpan di batch dan audit |
| Unduhan error | Baris error beserta pesannya sebagai CSV, dengan formula dinetralkan |
| Visibilitas batch | Pengunggah, atau HR pengelola seluruh periode |
| Hasil | Draft aktual bersumber `import`; pengajuan, bukti, dan verifikasi mengikuti alur biasa |

Sel angka XLSX dibaca apa adanya dari sheet; sel teks adalah cara paling aman mengirim nilai berdesimal. Dependency `openspout/openspout` dipakai untuk membaca XLSX secara streaming.

## 4. Akses

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `actual.view` | A | A (scope) | A (scope) | A (diri sendiri) | A | A | — |
| `actual.enter` | — | — | — | A (diri sendiri) | A (scope) | — | — |
| `actual.verify` | — | — | A (scope, bukan miliknya) | — | — | — | — |

- Dasarnya ROLE_APPROVAL_MATRIX: "Input actual" Employee `A untuk tugasnya`; "Verify actual" Team Leader `A selain miliknya`.
- Aktual untuk target unit (team, department, company) dan import massal dilakukan HR Performance. Hak "bila data owner" untuk Manager dan Team Leader masih bersyarat dan belum diaktifkan (DEC-002, DEC-016).
- `actual.enter` dan `actual.verify` tidak dapat didelegasikan.
- Bukti dapat dibuka oleh akun yang berhak melihat aktualnya.

## 5. Event

`ActualSubmitted` v1 dan `ActualVerified` v1 per aktual; `ImportCommitted` v1 dengan `kind = actual`.

## 6. Halaman

| Halaman | Isi |
|---|---|
| `/actuals` | Periode yang memuat aktual dalam scope akun, termasuk milik pegawai sendiri |
| `/periods/{period}/actuals` | Entri per rentang observasi (personal atau massal sesuai scope), kelengkapan, alasan terlambat, ajukan, verifikasi, tolak, bukti dan riwayat per target |
| `/periods/{period}/actuals/import` | Unggah, hasil validasi, rekonsiliasi, commit atau batal, unduhan error, riwayat import |

## 7. Batasan yang diketahui

- Antivirus belum terintegrasi (lihat §2).
- Jumlah rentang observasi yang seharusnya ada per target tidak dihitung, karena kalender kerja belum diputuskan (DEC-015). Kelengkapan dihitung per target.
- Review Manager, kalibrasi, approval final, dan koreksi aktual verified menunggu Step 10.
- Upload memakai unggahan sementara Livewire lalu dipindahkan server ke disk evidence; belum ada upload langsung dari browser ke object storage.
- Binary bukti yang dilepas dan binary dari upload yang gagal di tengah jalan belum memiliki job pembersihan; retention menunggu DEC-010.
- Pemeriksaan independensi verifikator melihat pembuat, pengubah terakhir, dan pengaju; pengubah di tengah yang kemudian ditimpa tidak terdeteksi.
- Pengajuan ditolak seluruhnya bila ada satu draft milik akun tersebut yang belum memiliki bukti wajib.
- Pegawai dapat melihat riwayat audit aktualnya sendiri, termasuk siapa yang mengunduh buktinya.
- Kelengkapan dan validasi import memuat seluruh target dalam scope; cukup untuk skala saat ini, perlu agregasi SQL bila volume membesar (diuji pada Step 19).
