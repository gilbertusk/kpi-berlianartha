# Matriks integrasi (Step 17)

Sumber kebenaran teknis: `config/integrations.php` (inventori yang sama ditampilkan di halaman **Integrasi**). DEC-008 (sistem, akses, owner, SLA) dan DEC-016 (system of record per master) masih OPEN, sehingga **tidak ada koneksi berstatus PROVEN**. Status PROVEN hanya diberikan setelah bukti runtime pada sistem nyata; contract test pada fixture tidak menjadikan koneksi PROVEN.

## 1. Status per koneksi

| Koneksi | Data | System of record | Arah | Status | Alasan | Bukti yang ada |
|---|---|---|---|---|---|---|
| `hris-employees` | Master pegawai | HRIS (belum ditetapkan) | Inbound (migrasi) | BLOCKED | Sumber HRIS, mapping, sign-off HR | Alat migrasi; dua dry run + dua apply di PostgreSQL 18 pada fixture sintetis |
| `hris-organization` | Struktur organisasi | Sistem KPI (Step 04) | — | DEFERRED | Dikelola di aplikasi sampai DEC-016 | — |
| `attendance` | Absensi | Belum ditetapkan | — | DEFERRED | Tidak ada KPI disetujui yang memakai | — |
| `crm-sales-orders` | Sales/order | CRM/ERP | Inbound | BLOCKED | Tidak ada kontrak internal disetujui | — |
| `erp-receivables` | Piutang, faktur pajak | Sistem akuntansi | Inbound file | BLOCKED | Sistem, akses, owner | Contract test pipeline + adapter (staged, rekonsiliasi, auto-commit) |
| `erp-purchasing` | Purchasing | ERP | — | DEFERRED | Tidak ada modul/KPI disetujui | — |
| `mes-production` | Output, downtime, waste | MES/ERP | Inbound file | BLOCKED | Sistem, kalender shift (DEC-015) | Contract test pipeline + adapter |
| `mes-packing` | Packing | MES/WMS | Inbound file | BLOCKED | Sistem, kalender shift | Kontrak Step 15 |
| `tms-delivery` | Pengiriman, POD | ERP/TMS | Inbound file | BLOCKED | Sistem | Kontrak Step 15 |
| `erp-work-orders` | Work order, jadwal | ERP | Inbound file | BLOCKED | Sistem; write-back tidak diizinkan | Kontrak Step 15 |
| `wms-stock` | Stok per lokasi | WMS/ERP | Inbound file | BLOCKED | Sistem, master lokasi | Contract test pipeline (isolasi kegagalan) |
| `qms-inspection` | Inspeksi QC | QMS | Inbound file | BLOCKED | Sistem, taxonomy defect | Kontrak Step 16 |
| `qms-complaints` | Complaint/return | CRM/QMS | Rujukan | DEFERRED | Record tidak disalin | Rujukan nomor di NCR (Step 16) |
| `smtp-email` | Notifikasi | Sistem KPI | Outbound | BLOCKED | SMTP produksi, domain (DEC-014) | Mailpit di environment lokal |
| `sso` | Identitas | IdP perusahaan | Inbound | BLOCKED | DEC-013 | Login lokal + 2FA |
| `outbound-results` | Hasil KPI ke payroll/HRIS | Sistem KPI | Outbound | DEFERRED | Kompensasi di luar scope (DEC-012) | Outbox: retry terbatas, dead-letter, requeue manual |

Metadata lengkap per koneksi (protocol, autentikasi, pola file, versi skema, jadwal, volume, owner, pemilik data, SLA, retry, idempotensi, rekonsiliasi, penanganan error, klasifikasi data) ada di `config/integrations.php` dan tampil di halaman Integrasi.

## 2. Kontrak inbound file (`kpi-inbound-v1`)

Satu pengiriman = file data CSV + sidecar `<file>.manifest.json` di `<INTEGRATION_ROOT>/inbox/<koneksi>/` pada disk `INTEGRATION_DISK`.

```json
{
  "contract": "kpi-inbound-v1",
  "connection": "mes-production",
  "file": "production-2026-10-13.csv",
  "sha256": "<sha256 isi file>",
  "rows": 7,
  "unit": "PROD-WOVEN",
  "coverage_date": "2026-10-13",
  "source_system": "mes-woven",
  "source_extracted_at": "2026-10-14 09:30",
  "control_totals": {"quantity": "2250.875"},
  "signature": "<hex HMAC-SHA256 atas manifest tanpa signature, kunci terurut>"
}
```

Kolom file mengikuti kontrak modul tujuan (`PRODUCTION_SOURCES.md`, `ACCOUNTING_SOURCES.md`); nama kolom vendor dipetakan lewat `column_map` koneksi. Angka total kontrol ditulis sebagai teks dengan titik desimal. `control_totals` kosong ditandatangani sebagai `{}`.

## 3. Pipeline inbound

| Tahap | Implementasi | Hasil bila gagal |
|---|---|---|
| Receive | File + manifest di inbox; file tanpa manifest menunggu. Pengirim menaruh file data dulu, manifest terakhir. Ukuran diperiksa sebelum dibaca (`INTEGRATION_MAX_FILE_BYTES`, manifest maks. 16 KB), lalu disalin lewat stream | Quarantine `file_too_large` / `manifest_invalid` |
| Authenticate | HMAC-SHA256 manifest dengan kunci koneksi (`INTEGRATION_<KONEKSI>_HMAC_KEY`) | Quarantine `signature_invalid` |
| Checksum dan manifest | Kontrak, koneksi, nama file, SHA-256 isi | Quarantine `manifest_invalid` / `checksum_mismatch` |
| Ekstrak lama | Waktu ekstraksi lebih lama dari data yang sudah diterima untuk team, tanggal, dan sumber yang sama | Quarantine `stale_extract` |
| Replay | Konten (SHA-256) sama untuk team, tanggal, dan sumber yang sama dengan target yang masih berlaku; konten yang sudah dimuat lewat modul juga dicatat duplikat dan tidak di-commit atas nama orang lain | Run `duplicate`, tidak ada data baru |
| Anti-corruption | `CsvHeaderMapper` (nama kolom vendor → kontrak), `InboundAdapter` per modul | — |
| Schema/semantic validate | Staged import modul tujuan (header, tipe, aturan bisnis), sebagai akun layanan (`run-as`) dengan hak team-nya | Header/aturan ditolak → quarantine `rejected_by_target`; baris salah → batch staged tanpa baris tersimpan + exception `rows_rejected`; akun tidak berwenang → quarantine `not_authorized` |
| Preview/reconcile | Baris dan setiap total kontrol yang dideklarasikan koneksi (wajib ada di manifest) vs batch | Exception `reconciliation_difference` (nilai terbatas tidak ditulis untuk koneksi Restricted) |
| Atomic commit | Hanya bila `auto_commit` koneksi disetujui dan rekonsiliasi cocok; selain itu manusia meng-commit di halaman modul | Penolakan commit → exception `commit_refused` |
| Archive/quarantine | `archive/<koneksi>/<tanggal>/<run>/` atau `quarantine/<koneksi>/<run>/` | — |
| Retry | Kegagalan teknis: `failed`, jeda bertambah, maks. `INTEGRATION_MAX_ATTEMPTS`; run yang ditinggalkan crash dihitung sebagai percobaan; lalu `dead_letter` + exception; pemulihan manual `integration:retry <run>` atau tombol Ulangi (diaudit) | File tetap di inbox |
| Konkurensi | Satu proses per koneksi (cache lock) untuk scheduler, tombol Jalankan, dan retry | Koneksi dilewati dengan alasan |

Setiap file adalah run tersendiri; kegagalan satu file atau satu sistem tidak menyentuh file/koneksi lain, dan transaksi modul tujuan selalu utuh (commit penuh atau tidak sama sekali).

Outbound memakai transactional outbox yang sudah ada: percobaan terbatas (`OutboxProcessor::MAX_ATTEMPTS`), dead-letter (`failed_at`), dan pemulihan manual `php artisan ops:outbox-requeue <id> --reason="..."` (diaudit). Belum ada outbound feed yang disetujui.

## 4. Dashboard dan exception register

Halaman **Integrasi** (`integration.view`: System Admin, HR Performance, Auditor; `integration.manage`: System Admin) menampilkan per koneksi status dan alasannya, run terakhir, sukses terakhir, watermark (waktu ekstraksi sumber terakhir yang diterima) dan umurnya, jumlah baris/diterima/ditolak/duplikat, retry, dead-letter, exception terbuka, dan hasil rekonsiliasi terakhir; daftar run dengan rincian rekonsiliasi; serta exception register dengan keputusan **diterima apa adanya** atau **diselesaikan di sumber**, beserta alasan dan pemilik data yang menyetujui. Run dan exception tidak dapat dihapus.

## 5. Batas kredensial

- Kunci manifest dan akun layanan hanya dari environment/secret store (`INTEGRATION_<KONEKSI>_HMAC_KEY`, `INTEGRATION_<KONEKSI>_RUN_AS`); `.env.example` berisi nama variabel dengan nilai kosong, dan semua koneksi nonaktif secara bawaan.
- Kunci manifest minimal 32 karakter; koneksi dengan kunci lebih pendek tidak dijalankan.
- Akun layanan (run-as) harus akun khusus non-interaktif dengan role team yang dimuatnya, didaftarkan dan direview di access review; role layanan khusus belum ada.
- Signature manifest tidak disimpan; pesan kegagalan teknis hanya menyimpan nama kelas exception, tidak pernah pesannya (bisa memuat detail koneksi); audit dan exception register hanya berisi jumlah, kode, dan nomor baris.
- Test memakai kunci acak per test dan memeriksa bahwa kunci serta pesan exception tidak muncul di run, exception register, atau audit log.
- Kredensial media transfer (SFTP/share/object storage) belum ada; akan masuk lewat secret store yang sama setelah DEC-008/DEC-014.
