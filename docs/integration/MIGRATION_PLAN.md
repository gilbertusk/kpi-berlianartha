# Rencana migrasi data dan cutover (Step 17)

Status: **BLOCKED** untuk migrasi produksi. Belum ada sumber legacy yang disetujui, field mapping yang ditandatangani, atau owner bisnis yang ditetapkan (DEC-008, DEC-016). Yang tersedia adalah alat migrasi master pegawai yang sudah diuji, rencana, dan template sign-off.

## 1. Mapping migrasi

| Data | Sumber legacy | Tujuan | Status | Catatan |
|---|---|---|---|---|
| Struktur organisasi | — | Seeder referensi PRD §4 + pemeliharaan di aplikasi | Siap (seeder, tanggal efektif dari HR) | `ORGANIZATION_REFERENCE_EFFECTIVE_FROM` wajib dikonfirmasi HR di produksi |
| Master pegawai | Ekspor HRIS (belum ditetapkan) | `employees`, home assignment | Alat siap, sumber BLOCKED | Kontrak `migration-employees-v1`: `employee_number`, `full_name`, `home_team_code`, `position_title`, `effective_from`; nama kolom legacy dipetakan |
| Akun pengguna | — | Dibuat lewat administrasi (Step 05) atau SSO (DEC-013) | Tidak dimigrasi | Password tidak pernah dimigrasi |
| Periode historis | Belum ada | `assessment_periods` | BLOCKED | Butuh kalender dan keputusan periode (DEC-004, DEC-015) |
| Target historis | Belum ada | Target | BLOCKED | Butuh definisi KPI disetujui (DEC-006) |
| Aktual historis | Belum ada | Aktual | BLOCKED | Butuh definisi KPI dan sumber resmi |
| Metadata bukti | Belum ada | Evidence | BLOCKED | File bukti tidak dimigrasi tanpa klasifikasi dan retensi (DEC-010) |
| Riwayat approval | — | — | **Tidak dimigrasi** | Riwayat approval tidak boleh dibuat dari data legacy; approval hanya terjadi di sistem |

## 2. Aturan alat migrasi pegawai

- `php artisan integration:migrate-employees <file>` = **dry run** (semua dijalankan dalam transaksi lalu dibatalkan); `--apply` meng-commit.
- Idempotent per nomor induk: pegawai yang sudah ada dengan nama dan home team sama → tidak berubah; berbeda → **konflik** di exception register, tidak pernah ditimpa; baris tidak valid (nomor ganda di file, team tidak aktif pada tanggal efektif, tanggal salah) → ditolak.
- Total kontrol: baris sumber = dibuat + tidak berubah + konflik + ditolak ("seimbang"), dan jumlah per team.
- Setiap run tercatat di `integration_runs` (mode `migration_dry_run`/`migration_apply`) dengan SHA-256 file; exception tanpa nama pegawai.

## 3. Hasil dry run (salinan aman)

Dijalankan 2026-10-16 di PostgreSQL 18 (database sekali pakai `kpi_test` di Docker Compose lokal, struktur referensi dengan tanggal efektif 2026-01-01) memakai fixture sintetis `tests/Fixtures/integration/legacy-employees.csv` (10 baris, label `[FIXTURE]`):

| Run | Mode | Baris | Dibuat | Sudah ada | Konflik | Ditolak | Seimbang |
|---|---|---|---|---|---|---|---|
| 1 | dry run | 10 | 7 | 0 | 0 | 3 | ya |
| 2 | dry run | 10 | 7 | 0 | 0 | 3 | ya |
| 3 | apply | 10 | 7 | 0 | 0 | 3 | ya |
| 4 | apply | 10 | 0 | 7 | 0 | 3 | ya |

Pegawai `MIG-*` di database setelah keempat run: 7 (tidak ada duplikasi). Dua dry run identik dan tidak menulis data; apply kedua tidak menambah apa pun. Test otomatis mengulang skenario ini termasuk konflik dengan pegawai yang sudah ada.

## 4. Cutover dan rollback (usulan, butuh sign-off)

1. **Freeze**: hentikan perubahan master di sumber; catat waktu ekstraksi.
2. **Ekstraksi**: ekspor sumber, hitung SHA-256 dan total kontrol (jumlah pegawai per team) di sisi sumber.
3. **Backup**: backup database produksi sebelum apply (prosedur dan RPO/RTO menunggu DEC-010).
4. **Dry run** di salinan produksi yang aman minimal dua kali; hasil harus identik dan seimbang; semua konflik/penolakan diputuskan pemilik data di exception register.
5. **Apply** sekali; bandingkan total kontrol sumber dengan hasil (jumlah per team).
6. **Verifikasi**: sampel pegawai per team dicek pemilik data; apply kedua harus 0 dibuat.
7. **Go/no-go** oleh pemilik bisnis.
8. **Rollback**: data hasil migrasi tidak dihapus (histori dijaga); bila cutover dibatalkan sebelum go-live, pulihkan dari backup langkah 3; setelah go-live, koreksi lewat proses master data (rename, transfer bertanggal efektif) dengan audit.

## 5. Exception register dan pemilik pembersihan data

Setiap konflik, penolakan, selisih rekonsiliasi, file karantina, dan dead-letter tercatat di halaman Integrasi dengan pemilik data dari inventori koneksi (usulan, menunggu DEC-016). Keputusan "diterima apa adanya" atau "diselesaikan di sumber" mencatat alasan, pemilik data yang menyetujui, dan pelaksananya.

## 6. Sign-off pemilik bisnis (template)

| Item | Pemilik | Tanggal | Keputusan | Catatan |
|---|---|---|---|---|
| Sistem sumber dan metode akses (DEC-008) | IT + pemilik proses | | | |
| System of record dan owner master (DEC-016) | IT + unit owner | | | |
| Field mapping master pegawai | HR | | | |
| Hasil dua dry run pada salinan produksi | HR | | | |
| Exception yang diterima apa adanya | Pemilik data terkait | | | |
| Rencana cutover dan rollback | IT + HR | | | |
| Go/no-go | Management | | | |
