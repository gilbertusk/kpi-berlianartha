# Prompt Step 08 — Aktual, Evidence, dan Import

Anda bertindak sebagai Domain Engineer untuk data realisasi. Implementasikan actual KPI, evidence, validasi sumber, dan import yang dapat diaudit.

## Prasyarat

Periode, assignment, target, object storage abstraction, role scope, dan KPI definition harus tersedia. Baca source ownership, evidence policy, data classification, serta `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Implementasikan actual entry per frekuensi KPI dengan value, observation window, source, source record ID, actor, status, note, dan version.
2. Bedakan `MISSING`, `DRAFT`, `SUBMITTED`, `VERIFIED`, `REJECTED`, dan status lain hanya jika workflow menyetujuinya. Jangan menyamakan nilai nol dengan missing.
3. Implementasikan evidence metadata dan upload langsung/terkontrol ke S3-compatible storage. Validasi size, extension, MIME/content signature, jumlah file, checksum, ownership, dan access scope.
4. Gunakan signed URL berumur pendek untuk akses evidence. Jangan expose storage bucket secara publik.
5. Sediakan antivirus scanning interface/quarantine state. Bila scanner eksternal belum tersedia, tandai integrasi sebagai blocker dan cegah file tidak lolos scan diperlakukan aman.
6. Implementasikan staged CSV/XLSX import: upload → parse → validate → preview → commit atomik → reconciliation report. Simpan source file hash dan idempotency key.
7. Cegah duplikasi berdasarkan kombinasi source system/reference/period/KPI/subject yang disetujui.
8. Buat UI personal actual entry, bulk team entry sesuai role, evidence viewer, import history, error download, completeness, dan late submission reason.
9. Setiap create/update/submit/import harus menghasilkan audit yang aman.
10. Tambahkan test value boundary, decimal precision, zero-versus-missing, malicious filename, unauthorized file access, duplicate import, retry, transaction rollback, dan concurrent edit.
11. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- Jangan menaruh binary evidence di PostgreSQL.
- Jangan mempercayai MIME dari browser saja.
- Jangan mengubah actual yang sudah masuk tahap final; gunakan correction workflow pada step berikutnya.
- Jangan mengklaim antivirus aktif tanpa runtime evidence.

## Acceptance criteria

- Actual dapat direkonsiliasi ke target, KPI version, subject, period, actor, dan source.
- Evidence hanya dapat diakses pengguna berwenang.
- Import retry tidak menggandakan data.
- Invalid file/row tidak meninggalkan partial state.
- Missing, zero, N/A, dan not-applicable dapat dibedakan sesuai keputusan.

## Laporan akhir

Laporkan actual lifecycle, evidence controls, import results, security tests, integrasi yang belum tersedia, dan status `PASS/PARTIAL/BLOCKED`.
