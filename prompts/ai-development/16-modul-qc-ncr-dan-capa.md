# Prompt Step 16 — Modul Quality Control, NCR, dan CAPA

Anda bertindak sebagai Quality Management Product Engineer. Implementasikan Quality Control, defect tracking, NCR, CAPA, dan hubungannya dengan scorecard secara dapat diaudit.

## Prasyarat

Baca quality process yang disetujui, defect/reason taxonomy, severity, SLA, approver, evidence requirement, retention, source mapping, serta `PROJECT_PROGRESS.md`. Jangan menciptakan standar mutu, disposition, atau approval baru.

## Pekerjaan

1. Implementasikan inspection reference untuk incoming, in-process, dan final sesuai source system/approved workflow.
2. Implementasikan defect record dengan category, severity, quantity/unit, process, work order/product/lot, machine/shift bila relevan, detected-at, source, owner, dan evidence.
3. Implementasikan lifecycle minimum sesuai PRD: `TEMUAN → CONTAINMENT → ROOT_CAUSE → CORRECTIVE_ACTION → VERIFICATION → CLOSED`. Tahap tambahan seperti review, disposition, preventive action, atau effectiveness review hanya boleh ditambahkan setelah disetujui dan harus tetap dapat dipetakan ke lifecycle PRD.
4. Implementasikan CAPA dengan root-cause method/reference, correction, corrective action, preventive action bila disetujui, owner, due date, verification, effectiveness review bila diwajibkan, closure evidence, dan reopen.
5. Hubungkan complaint/return/repeat defect ke NCR/CAPA tanpa menggandakan source record.
6. Metric-source untuk rejection, first-pass yield, complaint, return, repeat defect, inspection lead time, serta closure SLA hanya menggunakan formula approved.
7. Corrective action dashboard harus menunjukkan overdue, severity, owner, linked KPI, repeat issue, dan verification status.
8. Terapkan role scope dan perlindungan evidence sensitif. Semua transition dan perubahan disposition harus diaudit.
9. Tambahkan test lifecycle, unauthorized transition, overdue calculation, duplicate defect, repeat-defect matching hanya jika rule tersedia, closure without evidence, reopen, concurrent review, dan linkage ke KPI snapshot.
10. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- Jangan mengizinkan CAPA ditutup hanya karena action ditandai selesai jika effectiveness verification diwajibkan.
- Jangan hard-code severity atau root-cause taxonomy yang belum disetujui.
- Jangan menghapus NCR/CAPA yang pernah memengaruhi scorecard.

## Acceptance criteria

- Defect dapat ditelusuri ke source/work order/inspection yang relevan.
- NCR dan CAPA hanya berpindah melalui transition sah.
- Overdue dan closure SLA dapat direkonsiliasi.
- KPI terkait memakai record/version yang jelas.
- Reopen dan correction mempertahankan histori.

## Laporan akhir

Laporkan state model, taxonomy yang aktif, sample traceability, test evidence, source integration status, dan status `PASS/PARTIAL/BLOCKED`.
