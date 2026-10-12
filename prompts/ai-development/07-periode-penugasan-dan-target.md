# Prompt Step 07 — Periode, Penugasan, dan Target

Anda bertindak sebagai Domain Engineer. Implementasikan periode penilaian, snapshot penugasan, penerapan template, serta target KPI.

## Prasyarat

Step organization dan KPI template harus lulus. Baca keputusan frekuensi, kalender, approver, target owner, timezone, dan `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Implementasikan periode dengan tipe/frekuensi, start/end, timezone, status, deadline per tahap, grace period jika disetujui, serta kalender kerja bila relevan.
2. Gunakan lifecycle eksplisit seperti `DRAFT → OPEN → IN_PROGRESS → REVIEW → FINALIZED → LOCKED`; sesuaikan hanya dengan keputusan yang disetujui.
3. Implementasikan assignment snapshot yang mengikat employee/team/jabatan, hierarchy, template version, dan approver chain pada periode tersebut.
4. Implementasikan target untuk company, department/group, team, dan employee sesuai applicability KPI.
5. Dukung manual entry dan staged import target dengan validation preview, row-level error, duplicate detection, idempotency, serta summary sebelum commit.
6. Terapkan unit, precision, min/max, effective date, dan evidence/source rule dari KPI definition.
7. Implementasikan submit/review/publish target jika decision register mewajibkannya. Jangan membuat jalur approval baru tanpa keputusan.
8. Cegah perubahan target setelah cut-off/publish/lock kecuali melalui controlled revision yang beralasan dan diaudit.
9. Buat UI period setup, assignment preview, target matrix, completeness indicator, import result, dan history.
10. Tambahkan test untuk boundary date Asia/Jakarta, duplicate period, overlapping period, employee transfer, template version selection, import rollback, authorization, dan concurrency.
11. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- HR dapat membuat periode dan melihat tepat siapa dinilai oleh siapa.
- Perpindahan employee setelah snapshot tidak mengubah periode lama.
- Target tidak dapat dimasukkan dengan unit atau precision tidak valid.
- Import invalid tidak menghasilkan partial commit yang tersembunyi.
- Target final tidak dapat ditimpa langsung.

## Laporan akhir

Laporkan lifecycle, aturan snapshot, import evidence, test boundary/concurrency, blocker keputusan, serta status `PASS/PARTIAL/BLOCKED`.
