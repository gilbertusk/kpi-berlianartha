# Periode, Snapshot Penugasan, dan Target

**Status:** Baseline Step 07 — 13 Oktober 2026
**Referensi:** PRD §4.1, §6.1, §7.4, FR 003, FR 005; ADR-0006 (effective dating), ADR-0007 (timezone), ADR-0008 (numeric precision); `DOMAIN_BOUNDARIES.md` §3–4; DEC-002, DEC-004, DEC-009, DEC-015

Modul `app/Modules/PeriodAssignment` memiliki periode penilaian dan snapshot penugasan. Modul `app/Modules/Target` memiliki target KPI dan import-nya. `Target` bergantung pada `PeriodAssignment`, tidak sebaliknya.

## 1. Periode

### Schema

| Tabel | Isi |
|---|---|
| `assessment_periods` | Kode, nama, tipe, `starts_on`, `ends_on`, `timezone`, unit scope, tanggal acuan penugasan, status, dan `lock_version` |
| `assessment_period_deadlines` | Satu baris per tahap yang memiliki deadline |

- **Rentang tanggal.** `starts_on` dan `ends_on` keduanya **inklusif** dan berupa tanggal bisnis. Ini berbeda dari tanggal berlaku (`effective_from`/`effective_until`) yang setengah terbuka: periode adalah rentang kalender yang dibaca pengguna apa adanya.
- **Zona waktu.** Periode menyimpan zona waktunya sendiri (`APP_TIMEZONE` saat dibuat). Batas periode dan deadline dihitung dengan zona itu, sehingga tidak bergeser bila konfigurasi aplikasi berubah.
- **Tipe.** `monthly`, `quarterly`, `annual`, `custom`. Tipe hanya klasifikasi dan dasar aturan tumpang tindih. Kalender bisnis belum diputuskan, jadi tanggal tidak diturunkan dari tipe.
- **Scope.** Satu unit organisasi (company, department, atau team) beserta turunannya. Pilot beberapa team dijalankan sebagai beberapa periode dengan scope berbeda.
- **Tanggal acuan penugasan.** Tanggal di dalam rentang periode yang dipakai untuk membaca organisasi, jabatan, template, dan role. Default-nya tanggal mulai.

### Lifecycle

```text
DRAFT ──open──▶ OPEN ──startProgress──▶ IN_PROGRESS ──▶ REVIEW ──▶ FINALIZED ──▶ LOCKED
```

- Tidak ada transisi mundur. REVIEW, FINALIZED, dan LOCKED dicapai lewat `PeriodService::advance()` dengan precheck (`WORKFLOW.md` §1). Periode tidak dibuka kembali; perubahan setelah REVIEW hanya lewat koreksi aktual yang disetujui.
- **DRAFT → OPEN** menyimpan snapshot penugasan dan daftar target dalam satu transaksi dengan perubahan status. Periode ditolak dibuka bila tidak ada satu pun subjek yang tercakup template.
- **OPEN → IN_PROGRESS** hanya bila semua target wajib sudah final.
- **REVIEW, FINALIZED, LOCKED** sudah ada di lifecycle dan trigger, tetapi belum memiliki use case. Ketiganya membutuhkan precheck kelengkapan, kalibrasi, dan approval dari Step 08–10.
- Setelah dibuka, identitas periode (kode, tipe, tanggal, zona waktu, scope, tanggal acuan) tidak dapat diubah. Trigger database menolaknya, termasuk lewat SQL langsung.

### Duplikat dan tumpang tindih

Dua periode bertipe sama tidak boleh mencakup subjek yang sama pada tanggal yang sama:

- tipe, scope, dan rentang identik → `period_duplicate` (juga dijaga unique index);
- rentang beririsan dan salah satu scope berada di dalam scope lainnya → `period_overlap`;
- scope pada cabang organisasi yang berbeda, atau tipe berbeda, boleh beririsan.

Pemeriksaan dilakukan saat membuat dan mengubah draft, lalu diulang saat membuka di bawah row lock semua periode bertipe sama. Dua draft yang lolos pada saat bersamaan tidak dapat sama-sama dibuka.

### Deadline

Tahap: penetapan target, pengisian aktual, verifikasi, review, kalibrasi, approval final (PRD §6.1).

- Semua deadline opsional dan diisi HR per periode. Nilai SLA belum diputuskan (DEC-009), jadi sistem tidak memberi nilai bawaan.
- Deadline berlaku sampai akhir tanggal tersebut di zona waktu periode. Tahap berikutnya tidak boleh ber-deadline lebih awal dari tahap sebelumnya.
- **Tidak ada grace period otomatis.** Deadline periode yang sudah dibuka hanya berubah lewat `rescheduleDeadline` dengan alasan wajib dan audit.
- Step 07 baru menegakkan deadline penetapan target. Deadline lain disimpan untuk Step 08–11.
- Kalender kerja dan shift belum dimodelkan (DEC-015).

## 2. Snapshot penugasan

Saat periode dibuka, `AssignmentSnapshotBuilder` menghitung dan `PeriodService` menyimpan:

| Tabel | Isi |
|---|---|
| `period_assignments` | Satu baris per subjek: pegawai (home team dan jabatan pada tanggal acuan) atau unit. Menyalin NIK, nama, jabatan, kode dan nama unit, jalur hierarki, versi template, status cakupan, dan gap approver |
| `period_assignment_approvers` | Akun yang memegang role tiap tahap approval untuk unit subjek, beserta nama, scope, dan referensi role assignment |

Aturan:

- **Subjek.** Semua pegawai yang home team-nya berada dalam scope pada tanggal acuan, dan semua unit dalam scope. Penugasan sementara tidak membuat scorecard kedua.
- **Template.** Versi yang berlaku pada tanggal acuan untuk unit subjek. Template khusus jabatan didahulukan, lalu template unit untuk semua jabatan. Template unit induk tidak diwariskan ke team di bawahnya.
- **Cakupan.** `covered`, `no_template` (termasuk template tanpa item), atau `kpi_not_in_force` (template merujuk versi KPI yang tidak berlaku pada tanggal acuan). Subjek yang tidak tercakup tetap disimpan agar terlihat siapa yang tidak dinilai.
- **Approver.** Tahap dan role-nya mengikuti approval flow acuan: verifikasi (Team Leader), review (Department Head/Manager), kalibrasi (HR Performance), approval final (Management). Approver adalah pemegang role yang scope-nya mencakup unit subjek. Role dibaca pada tanggal acuan, atau pada hari periode dibuka bila tanggal acuan sudah lewat, sehingga pemegang role yang sudah berakhir tidak terekam dan penggantinya terekam.
- **Tanpa self-approval.** Pegawai yang dinilai tidak pernah menjadi approver dirinya sendiri.
- **Gap tidak diisi asumsi.** Tahap tanpa approver dicatat sebagai `not_configured` atau `self_only`. Jalur pengganti menunggu DEC-002. Delegasi tidak masuk snapshot; delegasi dievaluasi saat keputusan dibuat.
- **Immutable.** Trigger database menolak `UPDATE` dan `DELETE` pada tabel snapshot. Perpindahan pegawai, perubahan nama, template baru, atau pergantian role setelah periode dibuka tidak mengubah periode tersebut.

Halaman periode menampilkan hasil yang sama sebagai **pratinjau** selama periode masih draft, sehingga HR membuka persis apa yang dilihatnya.

**Batasan.** Snapshot tidak dapat ditambah setelah periode dibuka. Pegawai yang bergabung atau template yang dipublish setelah itu ditangani oleh proses koreksi Step 10. Kebijakan pegawai yang pindah di tengah periode belum diputuskan.

## 3. Target

### Schema

| Tabel | Isi |
|---|---|
| `period_targets` | Slot target: satu versi definisi KPI untuk satu subjek dalam satu periode. Dibuat saat periode dibuka, tidak pernah berubah |
| `period_target_versions` | Nilai target per versi, `numeric(18,4)`, dengan status, sumber, alasan, flag setelah cut-off, dan `lock_version` |
| `target_import_batches`, `target_import_rows` | Staging import beserta hasil validasi per baris |

### Slot

`TargetSlotBuilder` membuat slot dari snapshot, di dalam transaksi pembukaan periode. Untuk setiap item template milik subjek yang tercakup (dan aturan jabatannya cocok), slot dibuat pada level penilaian KPI (`evaluation_subject`):

- `employee` → pegawai tersebut;
- `team`, `department`, `company` → unit pada level itu di jalur hierarki snapshot pegawai.

KPI level team pada scorecard sepuluh anggota menghasilkan satu target team, bukan sepuluh. Slot wajib bila setidaknya satu item sumbernya wajib.

### Validasi nilai

`TargetValueRules` memeriksa nilai terhadap versi definisi KPI. Tidak ada aturan bawaan bisnis; aturan berlaku hanya bila definisi menyatakannya.

| Aturan | Sumber |
|---|---|
| Satuan harus sama dengan satuan KPI | `unit` |
| Jumlah desimal maksimal | `decimal_places`; tipe integer, boolean, dan milestone selalu 0; tanpa aturan maksimal 4 |
| Rentang nilai (inklusif) | `min_valid_value`, `max_valid_value` |
| Boolean hanya 0 atau 1; milestone berupa nomor tahap | `value_type`, `score_parameters.stages` |

Format angka: titik sebagai pemisah desimal, tanpa pemisah ribuan, paling banyak 12 digit sebelum desimal. Nilai diproses sebagai bilangan bulat berskala 10⁴; float tidak dipakai.

### Lifecycle versi target

```text
DRAFT ──submit──▶ SUBMITTED ──approve──▶ APPROVED (final)
   ▲                  │
   └──────return──────┘
```

- **Satu simpan, semua atau tidak sama sekali.** Bila satu nilai dalam satu kali simpan ditolak, tidak ada nilai yang disimpan, dan setiap baris yang salah mendapat pesannya sendiri.
- **Target final tidak ditimpa.** Versi approved tidak dapat diubah atau dihapus (trigger database). Perubahan adalah **revisi**: versi baru dengan alasan wajib yang diajukan dan disetujui lagi. Versi lama tetap tersimpan.
- **Cut-off.** Setelah deadline penetapan target lewat, atau setelah periode masuk IN_PROGRESS, setiap draft baru wajib beralasan dan ditandai `after_cutoff`. Periode REVIEW ke atas menolak semua perubahan target.
- **Dua orang.** Penyetuju bukan akun yang membuat, terakhir mengubah, atau mengajukan versi tersebut, dan bukan pegawai pemilik target. Persetujuan massal melewati target semacam itu dan melaporkan jumlahnya.
- **Final sepenuhnya.** Versi approved menolak semua `UPDATE`, termasuk kolom pengaju dan penyetuju.
- **Scope.** Operasi massal menerima `UnitScope` aktor dan hanya menyentuh target di dalamnya.
- **Concurrency.** Slot dikunci selama transaksi; draft diubah lewat optimistic lock. Halaman matriks mengirim versi draft yang dilihat pengguna saat halaman dimuat (0 bila belum ada draft), sehingga simpan dari halaman basi ditolak. Periode dikunci bersama (shared) agar target tidak berubah saat status periode berganti.

**Jalur approval target.** PRD mewajibkan target disetujui (§6.1, FR 005), tetapi siapa penyetujunya per team belum diputuskan (DEC-002), dan semua hak "Menetapkan target" pada ROLE_APPROVAL_MATRIX masih bersyarat. Sampai diputuskan, hanya HR Performance yang memegang `target.manage` dan `target.approve`, dengan aturan dua orang di atas. Ini mengikuti pola publish Step 06 dan bukan penetapan jalur resmi.

### Import bertahap

1. **Stage.** File CSV diurai dan setiap baris divalidasi. Hasilnya disimpan di staging. Target tidak disentuh.
2. **Commit.** Hanya bila tidak ada baris error, dalam satu transaksi. Bila sebuah target pada baris `valid` atau `unchanged` berubah sejak divalidasi (`expected_state` tidak cocok), seluruh commit ditolak. Tidak ada commit sebagian.

| Aspek | Aturan |
|---|---|
| Format | CSV UTF-8 (BOM diterima), pemisah koma atau titik koma, maksimal 1 MB dan 5.000 baris |
| Kolom wajib | `kpi_code`, `subject_type` (`employee`/`unit`), `subject_code` (NIK atau kode unit), `unit`, `target_value`. `source_note` opsional; kolom lain diabaikan |
| Hasil per baris | `valid`, `unchanged`, `skipped` (nilai kosong), `error` beserta pesannya |
| Duplikat dalam file | Dua baris untuk target yang sama: keduanya error |
| Duplikat file | File dikenali dari SHA-256. File yang sudah di-commit ditolak; file yang sama di-stage ulang oleh akun yang sama menggantikan staging sebelumnya |
| Visibilitas batch | Hanya pengunggah, atau HR yang mengelola seluruh scope periode, yang dapat melihat, meng-commit, atau membatalkan sebuah batch |
| Batas | Jumlah baris dihitung saat file dibaca, sehingga file berisi sangat banyak baris kecil ditolak sebelum memakan memori. Periode di luar Open/In progress menolak staging |
| Idempotency | Commit memakai `IdempotencyService` dengan kunci batch. Commit ulang mengembalikan hasil pertama tanpa menerapkan ulang |
| Target final | Tidak pernah diubah oleh file. Revisi dilakukan per target dengan alasan |
| Hasil commit | Draft target bersumber `import`, yang tetap harus diajukan dan disetujui |
| Bukti | Baris mentah, hasil validasi, checksum, nama file, pengunggah, dan audit `target_import.staged/committed/discarded` |

File isian dapat diunduh per periode. Isinya hanya target dalam scope pengunduh yang masih boleh diisi lewat file. Teks yang diawali `=`, `+`, `-`, atau `@` dinetralkan untuk mencegah formula injection.

## 4. Event dan audit

| Outbox event | Dipublikasikan saat |
|---|---|
| `PeriodOpened` v1 | Periode dibuka |
| `TargetApproved` v1 | Satu versi target disetujui |
| `ImportCommitted` v1 | Import target di-commit |

Audit memakai entity `assessment_period`, `period_target`, dan `target_import_batch`. Payload tidak memuat nilai sensitif di luar nilai target itu sendiri.

## 5. Akses

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `period.view`, `target.view` | A | A (scope) | A (scope) | — | A | A | — |
| `period.manage` | — | — | — | — | A (scope) | — | — |
| `target.manage`, `target.approve` | — | — | — | — | A (scope) | — | — |

- Periode terlihat oleh akun yang scope-nya beririsan dengan scope periode. Isi yang tampil tetap dibatasi scope akun tersebut.
- `period.manage` mensyaratkan seluruh scope periode berada dalam scope pengelola. HR department tidak dapat menjalankan periode company.
- `target.manage` dan `target.approve` tidak dapat didelegasikan.
- Employee belum dapat melihat target pribadinya di sini; tampilan itu bagian dashboard Step 11.
- System Admin dan Auditor tidak pernah memegang capability yang mengubah periode atau target (invariant `RoleCapabilityMatrix`).

## 6. Halaman

| Halaman | Isi |
|---|---|
| `/periods` | Daftar periode dalam scope, filter status, form pembuatan |
| `/periods/{period}` | Setup draft, deadline, pratinjau atau snapshot penugasan dengan filter, tindakan lifecycle, penjadwalan ulang deadline, kelengkapan target, audit |
| `/periods/{period}/targets` | Matriks target dengan filter, indikator kelengkapan per unit, simpan draft, ajukan, setujui, kembalikan, revisi, riwayat versi dan audit per target |
| `/periods/{period}/targets/import` | Unggah file, hasil validasi per baris, ringkasan sebelum commit, commit atau batal, riwayat import |

## 7. Menambah modul yang ikut dalam transisi periode

Modul berikutnya (aktual, scoring, workflow) mengimplementasikan `PeriodLifecycleParticipant` dan men-tag dirinya dengan `PeriodAssignmentServiceProvider::LIFECYCLE_PARTICIPANTS`:

- `periodOpened()` berjalan di dalam transaksi pembukaan, setelah snapshot tersimpan;
- `blockersFor()` mengembalikan alasan periode belum boleh pindah ke status berikutnya.

Dengan cara ini modul periode tidak perlu mengenal modul yang bergantung padanya.

## 8. Batasan yang diketahui

- Approver per team dan jalur pengganti belum diisi (DEC-002). Snapshot merekam konfigurasi role apa adanya.
- Transisi REVIEW, FINALIZED, dan LOCKED belum dapat dijalankan.
- Persetujuan massal menyetujui versi yang berstatus menunggu saat tombol ditekan. Bila target dikembalikan, diubah, lalu diajukan lagi di antara waktu penyetuju melihat dan menekan tombol, nilai barulah yang disetujui. Penyetuju tetap bukan penulis nilai tersebut.
- Submit dan approve tidak memeriksa cut-off; hanya penulisan nilai yang memeriksanya.
- Koreksi snapshot assignment dan pembukaan kembali periode tidak dibangun pada Step 10 karena aturannya belum disetujui (DEC-002); Step 10 menyediakan koreksi nilai aktual saja.
- Target level di atas scope periode (misalnya KPI company pada periode satu team) dicatat per periode.
- Bila dua template dalam satu periode merujuk versi berbeda dari KPI yang sama untuk subjek yang sama, terbentuk dua slot dan import menolak baris tersebut sebagai ambigu.
