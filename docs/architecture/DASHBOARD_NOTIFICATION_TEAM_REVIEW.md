# Dashboard, Notifikasi, dan Corrective Action (Step 11)

Step ini menambahkan tiga modul: `Reporting` (dashboard per peran dan halaman team), `Notification` (notifikasi, preferensi, pengingat, eskalasi), dan `TeamReview` (daily team review, corrective action, coaching notes).

Mekanismenya lengkap dan teruji. Beberapa bagian sengaja kosong sampai keputusan bisnisnya ada: aturan pengingat/eskalasi (DEC-009), channel email (DEC-014), ambang KPI merah (DEC-004), dan akses coaching notes (DEC-011). Sistem tidak memakai nilai bawaan untuk hal-hal itu.

## 1. Dashboard per peran

Satu halaman (`dashboard`) menampilkan bagian sesuai role akun. Tidak ada angka contoh: bila tidak ada periode yang dapat dilihat akun, dashboard mengatakan demikian.

| Peran | Yang ditampilkan |
|---|---|
| Management | Ringkasan scorecard dalam scope, distribusi rating, skor scorecard unit periode ini dan sebelumnya, KPI di bawah ambang, kelengkapan aktual, scorecard menunggu keputusan, koreksi menunggu keputusan |
| HR Performance | Yang di atas, ditambah kesiapan periode menuju status berikutnya (checklist), scorecard menunggu pengajuan ulang |
| Department Head / Team Leader | Scorecard unit dalam scope, kelengkapan aktual, scorecard menunggu keputusan, corrective action (belum selesai / lewat tenggat / menunggu verifikasi), tautan halaman team |
| Karyawan | KPI miliknya, status aktual tiap KPI, umpan balik penolakan, tenggat periode, skor bila sudah dihitung lengkap, tindakan berikutnya |
| Auditor | Hitungan rekonsiliasi (perhitungan, hitung ulang, keputusan approval, kalibrasi dengan penyesuaian, koreksi, scorecard final) dan tautan baca saja |
| System Admin | Outbox menunggu/gagal, job gagal, email menunggu/gagal, status channel email, jumlah aturan scorecard dan aturan pengingat. Tidak ada nilai KPI |

Aturan:

- **Scope.** Setiap angka dihitung lewat query yang dibatasi scope akun (`ScoreAccess`, `ActualAccess`, `UnitScope`). Management, Department Head, dan Team Leader tidak melihat skor atau baris KPI pegawai lain; agregat mereka hanya memuat scorecard unit. Agregat tidak pernah memuat scorecard akun itu sendiri.
- **Dapat ditelusuri.** Setiap angka adalah hitungan record tersimpan dan didampingi tautan ke halaman yang mendaftar record tersebut (rekonsiliasi scorecard, aktual, inbox approval, antrean koreksi, team review).
- **KPI merah.** Baris KPI dengan skor di bawah `dashboard.red_kpi_score_below`. Nilainya `null` sampai ambang disetujui (DEC-004); selama itu dashboard menyatakan KPI merah belum dapat ditampilkan.
- **Distribusi rating** memakai rating hasil perhitungan terakhir (sebelum kalibrasi).
- **Tren** adalah skor scorecard unit periode ini dan periode sebelumnya dengan tipe dan scope yang sama, beserta selisihnya. Tidak ada rata-rata atau roll-up: aturannya belum ada.
- **Parameter `?periode=`** hanya memilih di antara periode yang boleh dilihat akun; nilai lain diabaikan.
- **Cache.** Tidak ada cache. Angka dihitung per permintaan, sehingga perubahan scope langsung berlaku dan tidak ada data lintas pengguna. Jumlah query per dashboard dijaga test regresi (§6).

### Halaman team (`team.index`)

Untuk pemegang `team_review.manage` (Team Leader, Department Head) atas team dalam scope-nya: anggota (home team hari ini), status aktual KPI wajib per anggota pada periode berjalan (belum diisi, draft, menunggu verifikasi, ditolak, terverifikasi), target versus aktual team dari baris perhitungan terakhir scorecard team, coaching notes, histori penempatan anggota (nama, jabatan, tanggal; alasan penempatan yang dicatat HR tidak ditampilkan), dan tautan.

- **Permintaan revisi data** adalah penolakan aktual dengan alasan pada halaman verifikasi aktual (Step 08); halaman team menautkannya dan menampilkan jumlah yang ditolak. Untuk aktual yang sudah terverifikasi, jalurnya koreksi (Step 10).
- **Delegasi penilaian** memakai delegasi Step 05; halaman team menautkannya.
- **Approval cuti** tidak dibangun. Bila `team_review.hris_leave_url` (https) dikonfigurasi, halaman menampilkan tautan ke sistem HR; bila tidak, halaman menyatakan belum ada sistem HR yang ditetapkan.
- **Perbandingan antarshift** tidak dibangun: definisi shift belum diputuskan (DEC-015).

## 2. Daily team review dan corrective action

| Tabel | Isi |
|---|---|
| `team_review_issues` | Masalah, tanggal review, kode dan catatan penyebab, konteks (work order, mesin, shift, customer, lainnya) dengan referensi teks, pelapor |
| `team_review_issue_kpis` | KPI yang terdampak |
| `corrective_actions` | Tindakan, PIC, tenggat, prioritas, status, alasan menunggu, catatan dan bukti penyelesaian, verifikator, penutup, `lock_version` |
| `corrective_action_events` | Riwayat tiap langkah (append-only) |

Status (PRD §9.2): `OPEN → IN_PROGRESS ⇄ WAITING`, `IN_PROGRESS → VERIFIED → CLOSED`.

- **PIC dan tenggat wajib** saat tindakan dibuat. PIC harus anggota unit yang punya akun aktif, atau pengelola review unit itu. Tenggat tidak boleh sebelum hari ini.
- **PIC** memulai, menandai menunggu (alasan wajib), melanjutkan, dan melaporkan penyelesaian. Tindakan tetap `IN_PROGRESS` sampai diverifikasi.
- **Pengelola review** (`team_review.manage` atas unit) memverifikasi, mengembalikan penyelesaian, menutup, dan mengubah tenggat (alasan wajib). **Tidak ada yang memverifikasi atau mengubah tenggat tindakannya sendiri**: tindakan milik Team Leader ditangani Department Head. Penyelesaian dilaporkan sekali sampai diverifikasi atau dikembalikan.
- **Bukti penyelesaian** wajib sebelum verifikasi bila `team_review.completion_evidence_required` diaktifkan ("bila dikonfigurasi" pada PRD). Bukti berupa referensi teks.
- **Overdue** = tenggat sudah lewat dan status masih `OPEN`, `IN_PROGRESS`, atau `WAITING`.
- Setiap langkah memakai optimistic locking, ditulis ke riwayat tindakan dan audit log. Trigger database menolak transisi tidak sah, penghapusan, dan perubahan setelah `CLOSED`.

Kode penyebab berupa teks bebas terformat dan referensi konteks berupa teks, karena master reason code, work order, mesin, shift, dan customer belum punya pemilik (DEC-016). Prioritas (rendah/sedang/tinggi) hanya label pengurut; tidak ada tenggat atau eskalasi yang diturunkan darinya.

### Coaching notes

DEC-011 berstatus PROPOSED, belum disetujui. Yang dibangun adalah pembacaan **paling sempit** dari usulan itu:

- Ditulis oleh pemegang `coaching_note.manage` (Team Leader) atas home team pegawai; tidak tentang diri sendiri.
- Dibaca hanya oleh penulis dan pemegang `coaching_note.manage` atas unit tempat catatan ditulis. Pegawai yang dicatat, Department Head, HR, Management, dan Auditor **tidak** dapat membacanya sampai DEC-011 memutuskan.
- Capability ini tidak dapat didelegasikan.
- Catatan append-only (trigger). Audit mencatat bahwa catatan ditulis, tentang siapa, dan oleh siapa, tanpa isinya. Isi catatan tidak pernah masuk notifikasi.

## 3. Notifikasi

| Tabel | Isi |
|---|---|
| `user_notifications` | Satu baris per penerima dan `deduplication_key` (unik), kategori, judul, isi, nama route dan parameternya, waktu dibaca |
| `notification_deliveries` | Satu baris per notifikasi dan channel: status, jumlah percobaan, kode error terakhir, waktu |
| `notification_preferences` | Preferensi email per pengguna dan kategori |

- **Di aplikasi** selalu terkirim; ini catatan resmi tentang apa yang diberitahukan.
- **Email** adalah salinan opsional. `notifications.email.enabled` bernilai `false` sampai layanan email disetujui (DEC-014). Saat aktif, pengguna dapat mematikan email per kategori. Email dikirim lewat job antrean (`SendNotificationEmail`, 3 percobaan, backoff 60 dan 300 detik).
- **Tanpa duplikasi.** Indeks unik `(user_id, deduplication_key)` memutuskan; pemrosesan ulang event outbox atau pengingat tidak membuat notifikasi kedua. Job email yang sudah terkirim tidak mengirim lagi, dua salinan job untuk pengiriman yang sama tidak berjalan bersamaan, dan pengiriman yang masih menunggu lebih dari 15 menit diantrekan ulang oleh perintah terjadwal.
- **Akun nonaktif** tidak menerima notifikasi.
- **Catatan pengiriman** menyimpan jumlah percobaan dan nama kelas exception saja, tidak pernah isi pesan atau pesan error mentah.
- **Isi notifikasi** menyebut tugas dan record, tidak pernah skor, rating, atau isi coaching note.
- **Deep link** (`notifications.open`) hanya dapat dipakai penerimanya (akun lain mendapat 404), menandai notifikasi dibaca, lalu mengarahkan ke route tersimpan. Halaman tujuan menjalankan pemeriksaan aksesnya sendiri; penerima yang tidak lagi berwenang ditolak di sana.

Kategori dan pemicunya (lewat outbox, consumer `workflow-notifications`):

| Kejadian | Penerima |
|---|---|
| Tahap approval siap (masuk review, review selesai, kalibrasi selesai, pengajuan ulang) | Approver tahap itu pada snapshot periode. Saat periode masuk review: satu notifikasi per approver untuk seluruh periode. Sesudahnya: satu per scorecard, tahap, dan putaran |
| Scorecard dikembalikan | Pemegang `scorecard.calculate` atas unit (HR) |
| Koreksi diminta | Pemegang `correction.approve` atas unit, kecuali peminta |
| Koreksi disetujui / ditolak | Peminta |
| Aktual ditolak | Yang mengajukan aktual |
| Corrective action ditugaskan | PIC |
| Penyelesaian corrective action dilaporkan | Pembuat tindakan dan pelapor masalah |

## 4. Pengingat dan eskalasi

`notifications:send-reminders` dijadwalkan tiap jam. Ia hanya menjalankan aturan pada `notifications.reminder_rules`. **Daftar itu kosong sampai DEC-009 disetujui**, sehingga tidak ada pengingat atau eskalasi yang dikirim; perintah melaporkan hal itu. Konfigurasi yang tidak valid menghentikan perintah dengan error.

| Tipe aturan | Arti |
|---|---|
| `period_deadline` | `offset_days` sebelum (negatif) atau sesudah (positif) tenggat sebuah tahap periode, kepada pemegang `audience_role` di dalam scope periode (untuk `employee`: pegawai yang dinilai) |
| `corrective_action_due` | `offset_days` ≤ 0 terhadap tenggat tindakan, kepada PIC |
| `corrective_action_overdue` | `offset_days` ≥ 1 setelah tenggat, kepada PIC; dengan `escalate_to_role` juga kepada pemegang role itu atas unit tindakan |

Setiap aturan wajib membawa `signoff_reference`. Kunci deduplikasi memuat id aturan, subjek, dan tanggal tenggat, sehingga menjalankan ulang pada hari yang sama tidak mengirim apa pun. Aturan cocok pada tanggal persisnya: bila scheduler mati sepanjang satu hari bisnis, pengingat hari itu tidak dikirim susulan. Perintah memuat seluruh tindakan yang cocok tanpa batching; ini perlu diukur sebelum aturan diaktifkan pada data besar.

Ringkasan periodik (PRD §12) tidak dibangun: frekuensi dan isinya belum diputuskan.

## 5. Capability

| Capability | Role |
|---|---|
| `team_review.view` | Management, Department Head, Team Leader, HR Performance, Auditor |
| `team_review.manage` | Department Head, Team Leader |
| `coaching_note.manage` | Team Leader |

PIC selalu dapat membuka tindakannya sendiri tanpa capability. Menu Team dan Team Review hanya tampil untuk akun yang berperan; menu Notifikasi tampil untuk semua akun.

## 6. Performa dan aksesibilitas

- **Query.** Daftar memakai pagination (20 per halaman) atau batas tetap; hitungan memakai agregat SQL; relasi di-eager-load. Test regresi membatasi jumlah query dashboard per role (Management, HR, Department Head ≤ 110; Team Leader, Auditor ≤ 90; Karyawan ≤ 60; System Admin ≤ 40) pada data skenario, dan memastikan jumlah itu tidak bertambah dengan 30 notifikasi tambahan.
- **Target performa** yang disetujui belum ada (DEC-007, DEC-010), sehingga tidak ada klaim terhadap data representatif; pengukurannya bagian Step 19.
- **Aksesibilitas.** Halaman memakai komponen UI yang sama dengan step sebelumnya: label pada setiap input, judul bagian berurutan, caption tabel, sel baris sebagai `th scope="row"`, status memakai teks dan simbol selain warna, empty state, tautan dengan teks pembeda untuk pembaca layar, dan layout satu kolom pada layar sempit. Pemeriksaan dengan alat bantu (axe, pembaca layar) belum dijalankan.
- Komponen `ui.table-shell` kini memakai `hasActualContent()`; sebelumnya empty state tidak pernah muncul di halaman Livewire karena penanda komentar Livewire membuat slot dianggap berisi.

## 7. Keputusan yang belum ada

- **DEC-009** aturan pengingat, eskalasi, dan SLA corrective action. Tanpa itu tidak ada pengingat yang dikirim.
- **DEC-014** layanan email. Channel email nonaktif; hanya diuji dengan mailer palsu.
- **DEC-004** ambang KPI merah dan skor resmi.
- **DEC-011** akses coaching notes (dibangun dengan akses paling sempit).
- **DEC-015** shift, **DEC-016** master reason code dan referensi operasional.
- Penerima notifikasi tugas approval adalah approver snapshot; delegate belum diberi notifikasi.
- Apakah bukti penyelesaian corrective action wajib, dan apakah harus berupa file.
