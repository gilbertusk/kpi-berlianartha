# Identity, Organization, dan Authorization

**Status:** Baseline Step 05 — 12 Oktober 2026
**Referensi:** ADR-0006 (effective dating), ADR-0009 (authentication adapter dan scoped authorization), `docs/product/ROLE_APPROVAL_MATRIX.md`, `docs/product/DECISION_REGISTER.md` (DEC-002, DEC-011, DEC-013)

Dokumen ini menjelaskan implementasi aktual Step 05. Keputusan bisnis yang masih `OPEN` tidak dikunci di sini. Approver per team, SLA, dan kebijakan MFA/SSO tetap mengikuti decision register.

## 1. Authentication

| Area | Implementasi | Status |
|---|---|---|
| Login lokal | Fortify + `LocalPasswordAuthenticator` (email/password). Fortify memakai adapter ini melalui `Fortify::authenticateUsing` | Development dan fallback terkontrol |
| Adapter boundary | Kontrak `CredentialAuthenticator`. Nilai `IDENTITY_PROVIDER` selain `local` akan gagal saat login. Sistem tidak pernah diam-diam kembali ke login lokal | SSO **belum** diimplementasikan (DEC-013 OPEN) |
| MFA | 2FA TOTP dan passkey bawaan starter kit tersedia untuk setiap akun. `IDENTITY_MFA_REQUIRED=true` mewajibkan 2FA sebelum aplikasi dapat dipakai. Middleware ini juga didaftarkan sebagai persistent middleware Livewire. Default-nya `false` | Kebijakan MFA menunggu DEC-013 |
| Registrasi publik | Nonaktif. Akun hanya dibuat oleh System Admin | — |
| Password | Akun baru dibuat dengan secret acak yang tidak dapat dipakai. Pengguna membuat password sendiri lewat tautan reset. Reset yang berhasil sekaligus memverifikasi email. Akun nonaktif tidak dapat reset. Audit `account.password_reset_link_sent` hanya ditulis bila broker benar-benar mengirim. Bila pengiriman di-throttle atau gagal, admin mendapat pesan dan dapat mengirim ulang | — |
| Hapus akun sendiri | Dihapus dari halaman profil. Akun dinonaktifkan, bukan dihapus, agar nama tetap muncul pada histori (PRD §5.1) | — |
| Session | `EnsureAccountIsActive` berjalan pada setiap request `web`, termasuk update Livewire. Middleware ini mengakhiri session akun nonaktif sebelum dan sesudah request; jalur sesudah request menutup login passkey/2FA yang tidak melewati adapter. Saat akun dinonaktifkan, `remember_token` diganti dan session database dihapus | — |
| Audit login | `auth.login_succeeded`, `auth.login_failed`, `auth.lockout`, `auth.logout`, `auth.password_reset_completed`, `auth.session_terminated`. Email yang dicoba dan password tidak pernah disimpan | — |

Login yang gagal karena password salah, akun tidak dikenal, atau akun nonaktif menghasilkan pesan generik yang sama (`auth.failed`). Untuk akun yang tidak dikenal, waktu pemrosesan disamakan dengan hash dummy.

## 2. Organization

Struktur yang dipakai adalah **Company → Group/Department → Team → Employee** (PRD §4).

| Tabel | Isi |
|---|---|
| `organization_units` | Identitas stabil unit: kode, tipe, dan periode aktif unit |
| `organization_unit_versions` | Nama dan unit induk per periode. Rename atau re-parent menutup versi lama dan membuka versi baru |
| `employees` | NIK, nama, dan tautan opsional ke akun. Data team tidak disimpan di sini |
| `employee_assignments` | `home` atau `temporary` per team, jabatan, periode, alasan, pembuat, dan pengakhir |

Aturan:

- Periode memakai interval setengah terbuka: `effective_from` inklusif, `effective_until` eksklusif. Tanggal disimpan sebagai string `YYYY-MM-DD` melalui cast `BusinessDate`. Cast bawaan `date` tidak dipakai karena di SQLite menyimpan jam dan merusak perbandingan string.
- Satu home team aktif per tanggal. Perpindahan menutup home team lama pada tanggal pindah dan membuka home team baru, sehingga baris lama tetap ada dan query untuk tanggal sebelum pindah tetap mengembalikan team lama.
- Perubahan atas data yang sudah ada hanya boleh berlaku mulai hari ini atau sesudahnya (`retroactive_change`). Aturan ini mencakup pindah team, penugasan sementara, pengakhiran penugasan, rename/re-parent unit, dan penutupan unit. Pengecualiannya: pegawai baru dan unit baru boleh dicatat dengan tanggal mulai lampau karena tidak mengubah record lain. Koreksi histori memerlukan proses koreksi tersendiri yang belum dibangun.
- Penugasan yang tanggal akhirnya sudah lewat tidak dapat diubah (`history_locked`). Penugasan sementara wajib memiliki tanggal akhir dan boleh tumpang tindih dengan home team.
- Hanya boleh ada satu company. Company kedua akan berperan sebagai scope company-wide.
- Unit hanya dapat ditutup bila tidak ada unit turunan dan penugasan pegawai yang masih aktif. Grant yang menunjuk unit tertutup tidak mencakup apa pun (fail closed).
- Setiap perubahan dicatat di audit dan memakai `lock_version`. Form yang sudah basi menghasilkan konflik 409, bukan menimpa data.
- Seed referensi (`OrganizationReferenceSeeder`) membuat 1 company, 3 group/department, dan 21 team. Tanggal berlaku diambil dari `ORGANIZATION_REFERENCE_EFFECTIVE_FROM`. Default non-production adalah tanggal PRD v0.1 (2026-10-12) sebagai placeholder. Seed production ditolak bila variabel ini kosong.

| Kode | Unit | Induk |
|---|---|---|
| `BAL` | PT Berlian Artha Label | — |
| `COMSUP` | Commercial dan Support | BAL |
| `COMSUP-SALES`, `COMSUP-CS`, `COMSUP-RND-COSTING`, `COMSUP-DESIGN-A`, `COMSUP-DESIGN-B`, `COMSUP-ADMIN-PPIC`, `COMSUP-PURCHASING`, `COMSUP-HR`, `COMSUP-GA` | Sales, Customer Service, R&D Costing, Design A, Design B, Admin PPIC, Purchasing, HR, GA | COMSUP |
| `ACC` | Accounting | BAL |
| `ACC-ADMIN`, `ACC-AR`, `ACC-TAX-INVOICE` | Admin Accounting, Account Receivable, Tax Invoice | ACC |
| `PROD` | Produksi | BAL |
| `PROD-WOVEN`, `PROD-WAREHOUSE`, `PROD-SCREEN-PRINT`, `PROD-QC`, `PROD-PACKING-LOGISTIC`, `PROD-LOGISTIC`, `PROD-HOT-CUT`, `PROD-HANG-TAG`, `PROD-FLEXO` | Woven, Warehouse, Screen Print, Quality Control, Packing Logistic, Logistic, Hot Cut, Hang Tag, Flexo Printing | PROD |

Seeder tidak membuat KPI, approver, bobot, atau target.

## 3. Role, capability, dan scope

Role tidak membawa scope. Setiap `role_assignments` menyimpan role, scope, periode, alasan, pemberi, dan data pencabutan. Periode role tidak boleh dimulai sebelum hari ini. Pencabutan menyimpan `revoked_at`, sehingga akses berhenti seketika tanpa mengubah jawaban untuk waktu sebelum pencabutan.

| Role | Jenis scope | Unit yang boleh menjadi scope |
|---|---|---|
| Management | Organization | Company |
| Department Head/Manager | Organization | Department atau satu team |
| Team Leader | Organization | Team |
| Employee | Self (record pegawai sendiri) | — (akun wajib terhubung ke data pegawai) |
| HR Performance | Organization | Company atau department |
| Auditor | Organization | Company, department, atau team |
| System Admin | System (teknis) | — |

Scope organisasi mencakup unit tersebut beserta seluruh turunannya pada tanggal keputusan. Scope company mencakup semua unit.

### Matriks role–capability aktual (`RoleCapabilityMatrix`)

Legenda: `A` diizinkan dalam scope, `—` ditolak, `R*` plafon untuk modul KPI berikutnya. `R*` belum dipakai oleh fitur apa pun. Kondisi "C" pada ROLE_APPROVAL_MATRIX, seperti kepemilikan data, status workflow, dan tahap approval, wajib ditegakkan oleh policy modul Step 06–10.

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `organization.view` | A | A | A | — | A | A | A |
| `organization.manage` | — | — | — | — | A | — | — |
| `employee.view` | A | A | A | A (diri sendiri) | A | A | — |
| `employee.manage` | — | — | — | — | A | — | — |
| `account.view` | — | — | — | — | — | A | A |
| `account.manage` | — | — | — | — | — | — | A |
| `role.view` | A | — | — | — | A | A | A |
| `role.manage` | — | — | — | — | — | — | A |
| `delegation.view` | A | — | — | — | A | A | A |
| `delegation.manage` | — | — | — | — | A | — | — |
| `access_review.view` | A | — | — | — | A | A | A |
| `kpi_definition.view` / `kpi_template.view` (Step 06) | A | A | A | — | A | A | — |
| `kpi_definition.manage` / `kpi_template.manage` (Step 06) | — | — | — | — | A | — | — |
| `kpi_definition.publish` / `kpi_template.publish` (Step 06) | — | — | — | — | A | — | — |
| `period.view` / `target.view` (Step 07) | A | A | A | — | A | A | — |
| `period.manage` (Step 07) | — | — | — | — | A | — | — |
| `target.manage` / `target.approve` (Step 07) | — | — | — | — | A | — | — |
| `actual.view` (Step 08) | A | A | A | A (diri sendiri) | A | A | — |
| `actual.enter` (Step 08) | — | — | — | A (diri sendiri) | A | — | — |
| `actual.verify` (Step 08) | — | — | A | — | — | — | — |
| `scorecard.view` (Step 09) | A | A | A | A (diri sendiri) | A | A | — |
| `individual_scorecard.view` (Step 09) | — | — | — | — | A | A | — |
| `scorecard.calculate` (Step 09) | — | — | — | — | A | — | — |
| `approval_route.manage` (Step 10) | — | — | — | — | A | — | — |
| `correction.request` (Step 10, default sementara) | — | — | — | — | A | — | — |
| `correction.approve` (Step 10, default sementara) | A | — | — | — | — | — | — |
| `team_review.view` (Step 11) | A | A | A | — | A | A | — |
| `team_review.manage` (Step 11) | — | A | A | — | — | — | — |
| `coaching_note.manage` (Step 11, akses tersempit sampai DEC-011) | — | — | A | — | — | — | — |
| `report.view` (Step 12) | A | A | A | — | A | A | — |
| `audit_report.view` (Step 12) | — | — | — | — | A | A | — |
| `operational_record.view` (Step 13; jenis catatan dapat membatasi role) | A | A | A | — | A | A | — |
| `operational_record.manage` (Step 13) | — | A | A | — | — | — | — |
| `accounting_data.view` (Step 14; hanya unit Accounting, tidak dapat didelegasikan) | A | A | A | — | — | A | — |
| `accounting_data.manage` (Step 14; hanya unit Accounting) | — | A | A | — | — | — | — |
| `production_data.view` (Step 15; hanya unit Produksi dan PPIC) | A | A | A | — | A | A | — |
| `production_data.manage` (Step 15; hanya jenis data tanggung jawab team) | — | A | A | — | — | — | — |
| `ppic_board.manage` (Step 15; hanya atas team PPIC) | — | A | A | — | — | — | — |
| `quality_record.view` (Step 16; NCR team QC atau team penanggung jawab) | A | A | A | — | A | A | — |
| `quality_record.manage` (Step 16; atas team QC: semua aksi; atas team penanggung jawab: tahap investigasi) | — | A | A | — | — | — | — |
| `integration.view` (Step 17; jumlah, checksum, keputusan; tanpa isi baris) | — | — | — | — | A | A | A |
| `integration.manage` (Step 17; jalankan, ulangi, putuskan exception) | — | — | — | — | — | — | A |
| `kpi.value.modify` | — | R* | R* | R* | R* | — | — |
| `approval.decide` | R* | R* | R* | — | R* | — | — |

Invariant berikut ditegakkan di constructor matrix dan diuji:

- Auditor hanya memegang capability `*.view`.
- System Admin dan Auditor tidak pernah memegang capability yang mengubah nilai KPI (`kpi.value.modify`, `approval.decide`, `target.manage`, `target.approve`), dan juga tidak pernah memegang capability konfigurasi KPI (`kpi_definition.manage/publish`, `kpi_template.manage/publish`, `period.manage`). Lihat `KPI_GOVERNANCE.md` dan `PERIOD_TARGET.md`.
- `target.manage` dan `target.approve` hanya dipegang HR Performance sampai jalur penetapan dan persetujuan target per team diputuskan (DEC-002). Keduanya tidak dapat didelegasikan.

### Aturan keputusan (`AccessControl::decide`)

Satu titik keputusan dipakai oleh policy, aksi Livewire, dan filter daftar:

1. Akun nonaktif → ditolak (`account_inactive`).
2. Kumpulkan grant yang aktif pada tanggal bisnis Asia/Jakarta dan belum dicabut pada saat keputusan. Grant berasal dari role assignment dan delegasi yang diterima.
3. Capability tidak dimiliki → ditolak (`no_grant`).
4. Untuk capability yang mensyaratkan independensi (`approval.decide`), target berupa record milik aktor sendiri → ditolak (`self_action`). Aturan ini berlaku walaupun aktor memiliki scope company atau menerima delegasi. Penerima delegasi juga tidak dapat menyetujui record milik pemberi delegasi, karena ia bertindak atas nama pemberi.
5. Target unit atau pegawai harus berada dalam scope grant (`out_of_scope`). Scope pegawai dihitung dari home team dan penugasan sementara yang aktif pada tanggal keputusan.

Tidak ada `Gate::before` atau super-admin bypass.

### Larangan mengubah hak akses sendiri

- System Admin tidak dapat memberi, mencabut, mengaktifkan, atau menonaktifkan akses akun sendiri. Larangan ini diterapkan di policy dan di service.
- Aturan dua orang: admin yang membuat sebuah akun (`provisioned_by_user_id`) tidak dapat memberi role bisnis kepada akun tersebut (`provisioner_grant`). Pengecualiannya adalah role Employee. Aturan ini mencegah "akun boneka" dipakai untuk menyetujui atau mengubah nilai KPI. Akibatnya, pemberian role bisnis kepada akun baru membutuhkan minimal dua System Admin.
- System Admin aktif terakhir tidak dapat dinonaktifkan (`last_system_admin`).
- Kombinasi role yang berisiko tidak diblokir karena belum ada keputusan bisnis, tetapi ditandai pada access review:
  - System Admin yang juga memegang role bisnis;
  - Auditor yang juga memegang role operasional;
  - akun nonaktif yang masih memiliki role.

## 4. Delegasi

| Aturan | Implementasi |
|---|---|
| Data wajib | Pemberi (pemilik role sumber), penerima, role, scope unit, alasan, mulai, tanggal akhir wajib, akun yang mengizinkan (`granted_by_user_id`), dan audit `delegation.created` |
| Siapa boleh membuat | Pemilik role untuk role delegable miliknya, atau HR Performance dalam scope `delegation.manage` |
| Role yang dapat didelegasikan | Management, Department Head, Team Leader, HR Performance. Employee, Auditor, dan System Admin tidak dapat didelegasikan |
| Isi delegasi | Hanya capability baca dan `approval.decide`. Pengelolaan master data, akun, role, dan input nilai tidak ikut |
| Batas | Tidak boleh mulai di masa lalu, tidak boleh melebihi periode atau scope role sumber, tidak boleh diberikan kepada pemilik role, dan tidak boleh diberikan kepada akun yang mengizinkannya |
| Kedaluwarsa | Otomatis. Authorization mengabaikan delegasi pada atau setelah `effective_until`. `identity:expire-delegations` (dijadwalkan per jam) menulis audit `delegation.expired` satu kali per delegasi |
| Gugur | Delegasi gugur bila role sumber dicabut atau berakhir, atau akun pemberi dinonaktifkan. Pencabutan role sumber juga menandai delegasi turunannya sebagai dicabut |
| Self-approval | Delegasi tidak pernah mengizinkan penerima menyetujui record miliknya sendiri |

## 5. Halaman administrasi

Semua halaman berada di bawah `/administration`. Setiap halaman memeriksa policy saat dimuat, dan setiap aksi Livewire memeriksa ulang policy dengan objek target yang sebenarnya. Properti state seperti ID record yang sedang diedit dan versi form diberi atribut `#[Locked]`.

| Halaman | Isi | Akses halaman |
|---|---|---|
| Struktur organisasi | Daftar unit per tanggal, filter tipe/teks, pagination, riwayat versi, tambah unit, ubah nama/induk, tutup unit | `organization.view`; perubahan memerlukan `organization.manage` |
| Pegawai & penugasan | Direktori sesuai scope dengan filter tanggal/team/teks dan pagination; pendaftaran pegawai | Scope `employee.view` yang menjangkau orang lain |
| Detail pegawai | Profil, riwayat penugasan, pindah home team, penugasan sementara, akhiri penugasan, koreksi nama | `employee.view` atas pegawai tersebut. Akun Employee hanya dapat membuka record miliknya |
| Akun | Daftar dengan filter, buat akun, nonaktifkan/aktifkan, kirim reset, hubungkan NIK | `account.view`; perubahan memerlukan `account.manage` dan bukan akun sendiri |
| Role & scope | Daftar dengan filter role/status/teks, beri role, cabut role | `role.view`; perubahan memerlukan `role.manage` |
| Delegasi | Daftar delegasi dalam scope atau milik sendiri, buat delegasi, cabut delegasi | `delegation.view`/`manage`, atau pemilik role delegable |
| Access review | Hak efektif per akun, temuan SoD, login terakhir, audit trail identitas/organisasi | `access_review.view`. Reviewer dengan scope department hanya melihat grant di dalam scope-nya; grant lain hanya dihitung. Audit trail hanya ditampilkan untuk scope company |

## 6. Data pengembangan

`DevelopmentIdentitySeeder` hanya berjalan pada `APP_ENV=local` (dipanggil oleh `DatabaseSeeder`) dan menolak berjalan di production. Seeder ini membuat 13 akun fiktif:

- nama diawali `[DEV]` dan email `dev.*@kpi.example.test`;
- NIK `DEV-xxxx`;
- satu contoh delegasi selama 14 hari dari Head Produksi kepada TL Woven.

Password diambil dari `DEV_SEED_PASSWORD`. Bila variabel itu kosong, seeder membuat password acak dan mencetaknya sekali. Password tidak disimpan di repository.

## 7. Batasan yang diketahui

- SSO, provisioning dari HRIS, dan penautan identitas eksternal belum ada (DEC-013, DEC-016).
- Approver aktual per team belum diisi (DEC-002). Team Leader, Manager, dan pengganti dikonfigurasi sebagai role assignment setelah sign-off. Sejak Step 07, `RoleHolderDirectory` menjadi kontrak baca publik untuk merekam pemegang role ke snapshot approver periode.
- Shift belum disetujui (DEC-015). Sejak Step 15 kalender shift berupa konfigurasi berversi di `config/production.php` dengan default kosong; lihat `docs/product/PRODUCTION_SOURCES.md`.
- Coaching notes dan data RESTRICTED lain belum ada (DEC-011).
- Retention/purge audit dan session menunggu DEC-010.
