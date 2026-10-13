# Approval, Kalibrasi, Period Lock, dan Koreksi (Step 10)

Modul `app/Modules/Workflow` membawa scorecard yang sudah dihitung melewati review, kalibrasi, dan approval final, mengunci periode, dan menyediakan satu-satunya jalur perubahan data setelah periode meninggalkan tahap pengisian: koreksi yang disetujui.

Mekanismenya lengkap dan teruji. **Pemakaian resminya tetap terblokir** oleh keputusan bisnis yang belum ada: tanpa policy scorecard bertanda tangan (DEC-004) tidak ada skor yang dapat direview, dan rantai approver per team (DEC-002) serta SLA (DEC-009) belum disetujui. Sistem tidak mengisi kekosongan itu dengan asumsi.

## 1. Siklus periode

`DRAFT → OPEN → IN_PROGRESS → REVIEW → FINALIZED → LOCKED`, hanya maju. `PeriodService::advance()` menjalankan tiga transisi terakhir. Setiap transisi dijaga oleh precheck modul yang bergantung pada periode (`PeriodLifecycleParticipant::blockersFor`), dan precheck dijalankan ulang di dalam transaksi.

| Transisi | Syarat (semua harus terpenuhi) |
|---|---|
| IN_PROGRESS → REVIEW | Tidak ada aktual draft, menunggu verifikasi, atau ditolak. Setiap scorecard yang tercakup template memiliki perhitungan terakhir berstatus lengkap |
| REVIEW → FINALIZED | Setiap scorecard tercakup berada dalam alur approval dan berstatus final disetujui, pada perhitungan terakhirnya. Tidak ada permintaan koreksi yang menunggu |
| FINALIZED → LOCKED | Syarat FINALIZED tetap berlaku, dan tidak ada event workflow periode ini yang gagal diproses di outbox |

Saat masuk REVIEW, sistem membuka satu `scorecard_reviews` per scorecard tercakup, menunjuk perhitungan terakhirnya. Saat LOCKED, event `PeriodLocked` ditulis ke outbox.

**Tidak ada transisi mundur dan tidak ada "reopen periode".** Aturan siapa yang boleh membuka kembali periode dan dalam kondisi apa belum disetujui, sehingga tidak dibangun. Yang tersedia adalah koreksi per aktual (§5), yang berlaku juga pada periode LOCKED tanpa mengubah status periode. Trigger database menolak perubahan status mundur, juga lewat SQL langsung.

## 2. State scorecard dalam review

```
IN_REVIEW ──review──▶ REVIEWED ──kalibrasi──▶ CALIBRATED ──approval final──▶ FINAL_APPROVED
    ▲                     │                        │
    │                     └────── dikembalikan ────┤
    │                                              ▼
    └────────── diajukan ulang (putaran +1) ◀── RETURNED
```

- Keputusan hanya dapat diambil selama periode berstatus REVIEW.
- Approver tahap yang sedang menunggu dapat mengembalikan scorecard dengan alasan wajib. HR Performance mengajukannya ulang sebagai **putaran baru** pada perhitungan lengkap terakhir. Putaran lama tetap ada di riwayat.
- Bila scorecard dihitung ulang setelah masuk review (misalnya versi policy berganti), approval ditolak dengan pesan "ada perhitungan lebih baru". HR mengajukannya ulang sebagai putaran baru dari state mana pun, karena persetujuan yang sudah ada diberikan untuk skor lama; periode tidak dapat difinalkan selama ada scorecard seperti itu.
- Transisi state dijaga trigger database (PostgreSQL dan SQLite). Baris review tidak dapat dihapus dan identitasnya tidak dapat diubah.

Tabel:

| Tabel | Isi | Sifat |
|---|---|---|
| `scorecard_reviews` | State, putaran, perhitungan yang sedang disetujui, `lock_version` | Transisi dijaga trigger |
| `scorecard_review_decisions` | Setiap keputusan: tahap, hasil, pelaku, role, delegasi (atas nama siapa), referensi grant, bypass, perhitungan, catatan, waktu | Append-only |
| `scorecard_calibrations` | Skor dan rating hasil perhitungan berdampingan dengan skor dan rating kalibrasi, alasan, referensi bukti | Append-only |
| `approval_stage_bypasses` | Izin melewati tahap review untuk satu team, referensi sign-off, tanggal berlaku | Isi tetap; hanya dapat dicabut |
| `correction_requests` | Permintaan koreksi, keputusan, versi aktual yang dihasilkan | Final setelah diputuskan |

## 3. Siapa boleh memutuskan

`ApprovalAuthority` adalah satu-satunya tempat keputusan ini dibuat. Sebuah akun boleh memutuskan satu tahap sebuah scorecard bila **semua** ini benar pada saat aksi:

1. Akun memegang `approval.decide` lewat grant yang role-nya adalah role tahap itu dan scope-nya mencakup unit subjek.

   | Tahap | Role (ROLE_APPROVAL_MATRIX "Approval flow acuan") |
   |---|---|
   | Review | Department Head |
   | Kalibrasi | HR Performance |
   | Approval final | Management |

2. Akun tercatat sebagai approver tahap itu pada snapshot periode (Step 07), **atau** bertindak lewat delegasi aktif dari approver tersebut. Delegasi yang kedaluwarsa atau dicabut tidak menghasilkan grant sama sekali. Keputusan delegasi mencatat "atas nama" siapa.
3. Akun bukan pegawai yang dinilai dan tidak bertindak atas nama pegawai yang dinilai (PRD §4.1).

Aturan tambahan:

- **Satu orang paling banyak satu tahap per putaran.** Akun yang memegang beberapa role tidak dapat mengkalibrasi lalu memberi approval final atas scorecard yang sama. Delegasi tidak membuka celah: bila delegate memutuskan atas nama seorang approver, approver itu dihitung sudah memutuskan, dan sebaliknya.
- **Klik ganda dan approver bersamaan.** Setiap keputusan mengunci baris review dan memakai `lock_version` yang dilihat approver di halaman. Keputusan kedua ditolak; indeks unik `(review, putaran, tahap)` menjamin satu keputusan per tahap di level database.
- **Persetujuan massal** (inbox) berlaku untuk review dan approval final, maksimal 200 scorecard per aksi, tiap scorecard dalam transaksinya sendiri dengan hasil per scorecard. Kalibrasi dan pengembalian dilakukan satu per satu.

Approver tahap dapat membaca skor scorecard yang ia putuskan lewat halaman approval, walaupun capability `individual_scorecard.view` hanya dimiliki HR dan Auditor. Tanpa itu approval tidak bermakna. Pegawai yang dinilai tidak dapat membuka halaman approval scorecardnya sendiri.

## 4. Tahap tanpa approver dan bypass

Snapshot periode mencatat gap bila sebuah tahap tidak memiliki approver untuk suatu team. Scorecard seperti itu **menunggu** dan gap-nya ditampilkan di board dan halaman scorecard.

Tahap review dapat dilewati hanya bila keduanya benar:

- snapshot scorecard menunjukkan gap pada tahap review, dan
- ada `approval_stage_bypasses` yang berlaku hari ini untuk unit team itu persis (tidak dicabut, dalam rentang tanggal).

Bypass dicatat HR Performance (`approval_route.manage`) dengan referensi sign-off dan alasan wajib, hanya untuk unit bertipe team, tidak boleh berlaku surut, dan tidak boleh tumpang tindih untuk team yang sama. Pencatatnya satu orang dan referensi sign-off berupa teks: sistem mencatat klaim persetujuan itu, tidak memverifikasinya. Apakah pencatatan bypass perlu orang kedua adalah bagian DEC-002. Saat HR mengkalibrasi scorecard tersebut, sistem lebih dulu mencatat keputusan `bypassed` yang merujuk bypass itu. Bypass untuk team yang memiliki reviewer tidak berpengaruh. Tahap kalibrasi dan approval final tidak dapat di-bypass.

## 5. Kalibrasi

- Mengosongkan skor kalibrasi berarti mengonfirmasi skor hasil perhitungan.
- Skor yang berbeda wajib beralasan, tidak boleh memiliki desimal lebih banyak dari aturan pembulatan skor akhir, dan harus berada dalam rating band **policy yang tersimpan pada perhitungan itu**. Rating skor kalibrasi diturunkan dari policy tersebut.
- Snapshot perhitungan tidak disentuh. `scorecard_calibrations` menyimpan skor/rating hasil perhitungan dan skor/rating kalibrasi berdampingan.
- Skor yang berlaku untuk sebuah scorecard adalah skor kalibrasi putaran berjalan yang dibuat untuk perhitungan berjalan; bila tidak ada, skor hasil perhitungan.

Belum ada aturan batas penyesuaian kalibrasi (misalnya selisih maksimum atau distribusi rating), sehingga tidak ada batas selain rating band.

## 6. Koreksi

Koreksi adalah satu-satunya cara mengubah aktual terverifikasi setelah periode berstatus REVIEW, FINALIZED, atau LOCKED. Pada IN_PROGRESS aktual diperbaiki lewat alur biasa.

1. **Permintaan.** Pemegang `correction.request` atas unit KPI memilih aktual terverifikasi, nilai yang benar (atau N/A dengan catatan), alasan wajib, dan referensi bukti. Nilai divalidasi dengan aturan yang sama seperti input aktual. Satu aktual paling banyak memiliki satu permintaan yang menunggu.
2. **Keputusan.** Pemegang `correction.approve` atas unit KPI menyetujui atau menolak. Aturan dua orang: penyetuju bukan peminta, dan keduanya bukan pegawai yang dinilai. Peminta dapat membatalkan permintaannya.
3. **Persetujuan**, dalam satu transaksi:
   - aktual mendapat versi baru berstatus verified (`source = correction`, `previous_version_id` menunjuk versi lama); versi lama tidak berubah;
   - seluruh scorecard periode dihitung ulang; scorecard yang inputnya tidak berubah mempertahankan perhitungannya, yang berubah mendapat perhitungan baru yang menunjuk perhitungan sebelumnya;
   - review scorecard yang berubah diarahkan ke perhitungan baru dan riwayatnya mencatat koreksi itu.

   Bila koreksi membuat sebuah scorecard tidak lagi dapat dihitung lengkap, seluruh koreksi ditolak dan tidak ada yang berubah. Bila aktual sudah berubah sejak diminta, permintaan harus ditolak dan diajukan ulang.

| Status periode | Akibat pada review scorecard yang skornya berubah |
|---|---|
| REVIEW | Kembali ke IN_REVIEW sebagai putaran baru; approval diulang. Ini berlaku juga untuk scorecard yang sedang dikembalikan: koreksi yang disetujui menggantikan pengajuan ulang oleh HR |
| FINALIZED / LOCKED | Tetap FINAL_APPROVED dan periode tetap pada statusnya. Persetujuan koreksi itu sendiri adalah otorisasinya, dan event `FinalScoreCorrected` membawa skor lama dan baru. **Pengecualian:** bila skor scorecard itu disesuaikan pada kalibrasi, koreksi ditolak seluruhnya (`correction_needs_recalibration`), karena belum ada aturan tentang nasib skor kalibrasi ketika dasarnya berubah setelah final |

`correction.request` (HR Performance) dan `correction.approve` (Management) adalah **default sementara**. ROLE_APPROVAL_MATRIX menandai "Approve correction/reopen" sebagai C (kondisional) untuk Management, Manager, dan HR, dan kondisinya belum diputuskan (DEC-002).

## 7. Event outbox

| Event | Kapan | Deduplication key |
|---|---|---|
| `CalibrationCompleted` | Kalibrasi disimpan | `calibration:{review}:{putaran}` |
| `FinalApprovalCompleted` | Approval final diberikan | `final-approval:{review}:{putaran}` |
| `PeriodLocked` | Periode dikunci | `period-locked:{periode}` |
| `CorrectionRequested` | Koreksi diminta | `correction-requested:{permintaan}` |
| `CorrectionApproved` | Koreksi disetujui | `correction-approved:{permintaan}` |
| `FinalScoreCorrected` | Koreksi mengubah skor scorecard pada periode FINALIZED atau LOCKED | `final-score-corrected:{review}:{perhitungan}` |

Event ditulis dalam transaksi yang sama dengan perubahannya. Sejak Step 11 ditambah `ApprovalStageReady`, `ScorecardReturned`, dan `CorrectionRejected`, dan consumer `workflow-notifications` mengubahnya menjadi notifikasi (`DASHBOARD_NOTIFICATION_TEAM_REVIEW.md`).

## 8. Halaman

| Route | Isi |
|---|---|
| `approvals.index` | Inbox: scorecard yang menunggu keputusan akun ini, persetujuan massal, scorecard dikembalikan yang menunggu pengajuan ulang, tautan antrean koreksi, daftar periode |
| `approvals.period` | Board periode: checklist menuju status berikutnya, tombol lanjutkan/kunci (hanya pengelola periode), status tiap scorecard, gap approver |
| `approvals.review` | Scorecard: skor hitung dan skor kalibrasi berdampingan, KPI, rantai approver, aksi tahap, kalibrasi, pengajuan ulang, permintaan koreksi, riwayat keputusan dan kalibrasi |
| `approvals.corrections` | Antrean koreksi: setujui, tolak, batalkan |
| `approvals.bypasses` | Catat dan cabut bypass tahap review |

Setiap aksi Livewire memeriksa ulang otorisasi di server. ID dan `lock_version` disimpan pada properti `#[Locked]`.

## 9. Capability

| Capability | Role | Catatan |
|---|---|---|
| `approval.decide` | Management, Department Head, Team Leader, HR Performance | Tahap ditentukan role dan snapshot approver (§3). Dapat didelegasikan |
| `approval_route.manage` | HR Performance | Mencatat dan mencabut bypass. Termasuk konfigurasi KPI: tidak pernah untuk System Admin atau Auditor |
| `correction.request` | HR Performance | Default sementara (DEC-002) |
| `correction.approve` | Management | Default sementara (DEC-002) |

`correction.*` mengubah nilai KPI, sehingga invariant segregation of duties melarangnya untuk System Admin dan Auditor. Auditor dapat membaca board dan riwayat approval, tanpa aksi.

## 10. Keputusan yang belum ada dan batasan

- **DEC-004 (OPEN).** Tanpa policy scorecard bertanda tangan tidak ada perhitungan lengkap, sehingga tidak ada periode yang dapat masuk REVIEW pada konfigurasi resmi. Seluruh test memakai policy TEST-ONLY.
- **DEC-002 (OPEN).** Siapa approver tiap team, approver pengganti, jalur saat posisi kosong, serta siapa yang meminta dan menyetujui koreksi. Mapping tahap → role mengikuti "Approval flow acuan"; penempatan orangnya adalah konfigurasi role.
- **DEC-009 (OPEN).** SLA review, kalibrasi, approval final, dan koreksi. Tidak ada tenggat, reminder, atau eskalasi yang dibangun.
- **Pergantian approver di tengah periode.** Approver baru yang tidak ada di snapshot tidak dapat memutuskan; jalurnya saat ini hanya delegasi dari approver snapshot. Bila approver snapshot sudah tidak aktif, tahap itu tertahan. Aturan penggantinya bagian dari DEC-002.
- **Reopen periode** tidak dibangun (§1).
- **Koreksi target, snapshot assignment, atau bukti** belum ada; koreksi hanya untuk nilai aktual. Referensi bukti koreksi berupa teks, bukan unggahan file.
- **Kalibrasi massal dan batas penyesuaian kalibrasi** belum ada aturannya.
- **Koreksi atas scorecard final yang dikalibrasi** ditolak sampai aturannya disetujui (§6).
- Antrean koreksi tidak pernah menampilkan permintaan tentang hasil akun itu sendiri, dan scope unit diterapkan di query sebelum batas 200 baris. Checklist periode menampilkan jumlah penahan untuk seluruh periode kepada siapa pun yang boleh membuka board; rinciannya tetap mengikuti scope.
- Aturan satu-keputusan dan penguncian baris diuji secara berurutan (versi basi) dan dijaga indeks unik; uji dua koneksi bersamaan pada PostgreSQL belum ada.
- Inbox dan board memfilter di aplikasi setelah memuat review periode; cukup untuk skala saat ini dan diukur pada Step 19.
- CHECK constraint nilai status hanya ada di PostgreSQL; di SQLite dijaga trigger transisi dan aplikasi.
