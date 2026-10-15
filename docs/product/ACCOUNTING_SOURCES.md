# Accounting, Account Receivable, dan Tax Invoice — read model dan kontrak impor (Step 14)

Sistem KPI **bukan** buku besar dan bukan source of truth akuntansi. Ia menyimpan snapshot apa yang dilaporkan sistem akuntansi pada sebuah tanggal as-of, menghitung aging, dan menyediakan rekonsiliasi. Tidak ada yang ditulis balik ke sistem sumber, dan tidak ada posting jurnal.

Belum ada koneksi ke sistem akuntansi (DEC-008, DEC-016 OPEN). Data masuk sebagai file sesuai kontrak di bawah; **integrasi runtime adalah blocker eksternal**.

## 1. Yang aktif dan yang belum

| Kebutuhan (prompt Step 14) | Status | Dasar |
|---|---|---|
| Read model invoice, alokasi pembayaran, jatuh tempo, customer, dispute, PIC penagihan, tindak lanjut, faktur pajak, referensi sumber | Aktif (dari snapshot file) | Data minimum |
| AR aging dengan tanggal as-of, outstanding, hari lewat jatuh tempo, dispute, PIC, tindak lanjut | Aktif | Bucket PRD §10.2 |
| Bucket aging | Aktif: belum jatuh tempo, 1–30, 31–60, 61–90, > 90 hari (versi `prd-10.2`) | PRD §10.2; perubahan butuh keputusan berversi |
| Staged import, hash sumber, idempotent, tanpa overwrite | Aktif | Prompt |
| Rekonsiliasi snapshot (total sumber vs total kontrol; bucket vs outstanding) | Aktif | Aritmetika, bukan formula KPI |
| Tax invoice: referensi, status dan alasan dari sumber, invoice tanpa faktur | Aktif (read-only) | Data sumber |
| Tenggat penerbitan faktur, queue berbasis aturan | **BLOCKED** | Aturan business owner belum ada |
| DSO, collection rate, overdue %, unapplied payment, forecast | **BLOCKED** | Formula belum disetujui (DEC-006); denominator tidak dikarang |
| Perlakuan credit note dan lebih bayar | **BLOCKED** | Baris credit note ditolak saat impor; kelebihan alokasi ditampilkan sebagai pengecualian, tidak di-aging |
| Collection follow-up dan promise-to-pay di dalam sistem KPI | **BLOCKED** | Hanya "bila disetujui"; saat ini tindak lanjut dibaca dari sumber |
| Dashboard rekonsiliasi Admin Accounting (open item, koreksi, kelengkapan dokumen, close readiness) | **BLOCKED** | Butuh sumber dan aturan; hanya rekonsiliasi snapshot yang tersedia |

## 2. Kontrak file impor

CSV (UTF-8, koma atau titik koma) atau XLSX dengan baris judul. Satu file = satu snapshot per team, sistem sumber, dan tanggal as-of.

| Kolom | Wajib untuk | Aturan |
|---|---|---|
| `record_type` | semua | `invoice`, `allocation`, `tax_invoice`. `credit_note` ditolak (aturan belum ada) |
| `document_number` | semua | Kode tanpa spasi. Invoice: nomor invoice. Allocation: nomor pembayaran. Tax invoice: nomor faktur |
| `related_document` | allocation, tax_invoice | Nomor invoice di file yang sama |
| `customer_code` | invoice | Kode customer di sistem sumber |
| `customer_name` | — | Opsional (drill-down); tidak masuk audit |
| `document_date` | semua | `YYYY-MM-DD`, tidak setelah tanggal as-of |
| `due_date` | invoice | `YYYY-MM-DD`, tidak sebelum `document_date` |
| `currency` | invoice | Kode 3 huruf; alokasi harus sama dengan invoice-nya |
| `amount` | invoice, allocation | Positif, titik desimal, maks. 2 desimal |
| `status` | tax_invoice | Kode dari sumber. Pada invoice, `disputed` menandai dispute |
| `status_reason`, `collection_owner`, `next_action`, `next_action_date` | — | Dari sumber |

Satu pembayaran boleh dialokasikan ke beberapa invoice (satu baris per invoice). Nomor invoice dan nomor faktur unik per snapshot.

Satu file berisi **satu mata uang**. Tidak ada aturan konversi, sehingga total, bucket, dan total kontrol tidak pernah menjumlahkan mata uang berbeda; pisahkan file per mata uang. File wajib berisi minimal satu invoice, agar file kosong tidak terbaca sebagai "tidak ada piutang".

## 3. Aturan hitung

- **Outstanding** = nilai invoice − alokasi bertanggal sampai tanggal as-of. Pembayaran sebagian mengurangi outstanding; dispute tidak mengubahnya (ditandai, tidak di-netting).
- **Hari lewat jatuh tempo** = tanggal as-of − jatuh tempo, dalam hari kalender. Nol atau kurang = belum jatuh tempo.
- **Lunas** (outstanding nol) tidak di-aging. **Kelebihan alokasi** (outstanding negatif) dilaporkan sebagai saldo kredit, tidak di-aging.
- Semua nilai desimal eksak (brick/math); tidak ada floating point dan tidak ada konversi mata uang.
- **Rekonsiliasi:** outstanding snapshot = total invoice − total alokasi s.d. as-of + saldo kredit yang dipisahkan, dan harus sama dengan jumlah seluruh bucket. Ini pemeriksaan konsistensi internal, bukan pembanding independen. Pembanding independen adalah total kontrol dari sumber (opsional), yang harus sama persis dengan total file agar snapshot dapat di-commit.
- Angka XLSX yang disimpan sebagai number dibaca dengan presisi 15 digit; untuk nilai sangat besar gunakan sel teks atau CSV.

## 4. Siklus snapshot

`staged` → `committed` atau `rejected`.

- Staging menyimpan semua baris dan **semua** masalah per baris; file dengan masalah tidak dapat di-commit.
- File yang sama (hash SHA-256 isi) untuk sistem sumber yang sama dikenali dan mengembalikan snapshot yang sudah ada; halaman menyatakan bahwa tidak ada yang berubah. File yang sama untuk team atau tanggal as-of lain ditolak. File dari snapshot yang **ditolak** boleh diimpor lagi.
- Impor satu team diserialkan dengan lock pada baris unit, sehingga dua impor bersamaan tidak mendapat nomor versi yang sama.
- File baru untuk tanggal as-of yang sama menjadi **versi berikutnya**; versi lama tetap ada dan tetap dapat dilaporkan.
- Snapshot yang sudah committed atau ditolak tidak dapat diubah atau dihapus, dan dokumennya tidak dapat ditambah, diubah, atau dihapus (trigger database PostgreSQL dan SQLite).
- Audit mencatat staging, commit (dengan total), dan penolakan (dengan alasan), tanpa nama customer atau nomor dokumen.

## 5. Akses

| Capability | Role | Catatan |
|---|---|---|
| `accounting_data.view` | Management, Department Head, Team Leader, Auditor | Hanya atas unit Department Accounting (`accounting.department_code`, default `ACC`) dan di dalam scope role. Tidak dapat didelegasikan |
| `accounting_data.manage` | Department Head, Team Leader | Impor, commit, tolak snapshot untuk unit Accounting dalam scope |

HR Performance, System Admin, dan Karyawan tidak memiliki akses. Team Leader team lain memegang capability tetapi tidak pernah melihat data piutang. Laporan piutang berklasifikasi Terbatas dan ikut kontrol ekspor Step 12.

## 6. Pemilik data (usulan, belum disetujui)

| Data | Pemilik | System of record |
|---|---|---|
| Invoice, alokasi pembayaran, saldo | Team Leader Account Receivable | Sistem akuntansi (belum ditetapkan, DEC-016) |
| Faktur pajak | Team Leader Tax Invoice | Sistem pajak/akuntansi (belum ditetapkan) |
| Rekonsiliasi snapshot | Department Head Accounting | Sistem KPI (read model) |
