# Tinjauan Otorisasi Entry Point

**Status:** Step 18 — 16 Oktober 2026. Ditinjau terhadap kode pada akhir Step 18.
**Aturan dasar:** setiap entry point memeriksa capability dan scope unit bertanggal di server (`AccessControl`, `RoleCapabilityMatrix`, policy per modul). Menyembunyikan tombol bukan kontrol. Record di luar scope dijawab 404/403 tanpa membocorkan isinya. Auditor dan System Admin tidak pernah memegang capability yang mengubah nilai KPI.

Test penjaga lintas aplikasi: `tests/Feature/Security/HardeningTest.php` memastikan **setiap rute** memakai `auth` kecuali daftar publik yang ditinjau (login, reset password, 2FA/passkey challenge, `/health`, aset Flux/Livewire, endpoint update/upload Livewire yang memvalidasi session/tanda tangan sendiri), rute unduhan memakai `signed` + `throttle:downloads`, dan tidak ada rute `storage/` publik.

## 1. Controller HTTP

| Entry point | Pengecekan | Test negatif |
|---|---|---|
| `GET evidence/{evidence}/{viewer}` (unduh bukti) | `auth`, tanda tangan URL berumur pendek, `viewer` = `public_id` akun yang login (`hash_equals`), `ActualPeriodAccess::allowsTarget(ActualView)` dicek ulang saat unduh, file karantina tidak dapat dibuka, throttle 60/menit | `EvidenceTest`: tautan untuk akun lain, tanpa tanda tangan, kedaluwarsa, di luar scope; `ScorecardPagesTest`: bukti hanya untuk entri baris dan akun yang boleh melihat aktual; `HardeningTest`: throttle |
| `GET reports/exports/{export}/{viewer}` (unduh ekspor) | `auth`, tanda tangan, pemilik ekspor = akun login, akses laporan dicek ulang saat unduh, 410 bila kedaluwarsa, throttle | `ReportExportTest`: di luar pemilik dan aksesnya, kedaluwarsa, akses dievaluasi ulang di worker dan saat unduh |
| `GET periods/{period}/scorecards/export.csv` | `Gate viewScorecards`, scope baca dibekukan per akun, audit | `ScorecardPagesTest`: ekspor rekonsiliasi mengikuti scope dan diaudit |
| `GET periods/{period}/actuals/import/{batch}/errors.csv` | `Gate enterActuals`, batch milik periode, hanya pengunggah atau pengelola periode (lainnya 404) | `ActualPagesTest`: batch hanya terlihat oleh pengunggah atau HR |
| `GET periods/{period}/targets/template.csv` | `Gate manageTargets`, isi dibatasi scope `target.manage` | `PeriodPagesTest`: file isian hanya berisi target dalam scope pengunduh |
| `GET notifications/{notification}/open` (deep link) | Notifikasi milik akun login (lainnya 404); hanya menandai dibaca lalu mengarahkan, otorisasi tetap di halaman tujuan | `NotificationTest`: deep link milik penerimanya dan otorisasi diserahkan ke tujuan |

## 2. Halaman dan action Livewire

Setiap halaman memeriksa akses di `mount()` dan setiap action memeriksa ulang capability/scope di service domain (bukan hanya di view). Properti identitas dikunci (`#[Locked]`) dan record dicari ulang berdasarkan `public_id` dalam scope.

| Area | Halaman | Test negatif (forged action / direct URL / lintas scope) |
|---|---|---|
| Administrasi | `administration.*` | `AdministrationAccessTest` (direct URL pegawai di luar scope, forged action, HR departemen tidak memindah ke departemen lain, admin tidak memberi role ke diri sendiri, TL tidak mendelegasikan role orang lain), `RoleAssignmentTest`, `DelegationTest` |
| Scope umum | semua | `AuthorizationScopeTest` (scope per role, departemen, penugasan kedaluwarsa/masa depan, perpindahan pegawai, System Admin/Auditor tidak dapat mengubah atau menyetujui nilai KPI, list scope = keputusan per record) |
| KPI | `kpi.*` | `KpiGovernanceAccessTest` (detail mengikuti unit pemilik, kamus/usage tidak membocorkan di luar scope, forged create/edit/publish/retire, pengaju tidak mem-publish versinya), `VersionTimelineTest` |
| Periode & target | `periods.*` | `PeriodPagesTest` (periode di luar scope tidak dapat dibuka lewat URL, forged action role read-only, HR departemen, halaman basi), `AssignmentSnapshotTest` |
| Aktual & bukti | `actuals.*` | `ActualPagesTest`, `ActualLifecycleTest` (pegawai hanya aktual sendiri, TL hanya timnya, forged action role read-only), `ActualImportTest` |
| Scorecard | `scorecards.*` | `ScorecardPagesTest` (scorecard individu hanya pegawai/HR/auditor, hitung ulang hanya HR walau forged) |
| Approval & koreksi | `approvals.*` | `ApprovalPagesTest`, `ApprovalWorkflowTest` (tidak memutus tahap yang tidak dipegang, tidak memutus scorecard sendiri, delegasi hanya saat berlaku), `CorrectionGuardTest`, `CorrectionTest` |
| Team review & notifikasi | `team.*`, `team-review.*`, notifikasi | `TeamReviewPagesTest` (forged: pemilik/tim salah, issue asing; coaching notes hanya TL), `TeamReviewTest` |
| Dashboard & laporan | `dashboard`, `reports.index` | `DashboardTest` (angka dan periode dalam scope), `ReportCatalogTest` (laporan dan baris per scope), `ReportExportTest` |
| Commercial/Support | `operations.index` | `OperationalRecordTest` (tipe dan tim per akun, data biaya terbatas, import hanya untuk tim pengimpor) |
| Accounting | `accounting.index` | `ReceivablesTest` (data finansial hanya dalam scope accounting, import hanya oleh timnya) |
| Produksi & PPIC | `production.index`, `ppic.index` | `ProductionPagesTest`, `ProductionImportTest`, `PpicBoardTest` (tim lain tidak melihat batch, hanya PPIC memindah work order) |
| Quality | `quality.*` | `QualityPagesTest` (tim lain tidak dapat membuka NCR), `NcrLifecycleTest` (transisi hanya oleh yang berhak, bukti restricted) |
| Integrasi | `integrations.index` | `IntegrationAccessTest` (hanya System Admin/HR/Auditor membaca, hanya System Admin mengoperasikan) |

## 3. Job dan proses latar

| Job/proses | Pengecekan |
|---|---|
| `GenerateReportExport` | Akses pemilik dievaluasi ulang di worker sebelum file dibuat; scope dibekukan saat permintaan (`ReportExportTest`) |
| `SendNotificationEmail` | Hanya ke penerima notifikasi; menyalin judul/isi notifikasi in-app miliknya dan menautkan ke deep link yang mewajibkan login dan mengecek kepemilikan |
| `ProcessOutboxMessage` | Payload ID; idempotent; dead-letter dengan requeue manual teraudit |
| `integration:run` | Berjalan sebagai akun layanan per koneksi (`RUN_AS`) dengan scope akun itu; koneksi nonaktif secara bawaan |
| Command `ops:*`, `integration:*`, `identity:*` | Hanya operator dengan akses server; aksi yang mengubah data diaudit atas nama operator/sistem |

## 4. Direct object access

Record yang dapat dialamatkan lewat URL memakai `public_id` (ULID), bukan ID berurutan, dan setiap pencarian record dilakukan di dalam scope akun. Test "direct URL" ada untuk pegawai, periode, NCR, review approval, ekspor, bukti, dan batch import (lihat tabel di atas).

## 5. Temuan tinjauan Step 18

| Temuan | Tindakan |
|---|---|
| Rute bawaan `storage/{path}` (disk `local`, `serve`) terdaftar tanpa auth | Dimatikan (`config/filesystems.php`), dijaga test |
| Unduhan bukti/ekspor tanpa batas laju | Throttle `downloads` 60/menit per akun |
| Reset password membocorkan apakah alamat terdaftar dan tanpa throttle rute | Jawaban seragam + throttle `password-reset` (bucket per rute per IP) |
| Tautan reset password dibangun dari Host request (host header poisoning) | Tautan dari `APP_URL`; host selain `APP_URL` ditolak; header host/prefix dari proxy tidak dipercaya |

Tidak ada entry point bisnis tanpa pengecekan server yang ditemukan.
