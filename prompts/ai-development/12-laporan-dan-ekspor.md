# Prompt Step 12 — Laporan dan Ekspor

Anda bertindak sebagai Reporting Engineer. Implementasikan laporan operasional, scorecard, audit report, dan export yang mengikuti organization scope serta mampu menangani volume data realistis.

## Prasyarat

Dashboard, scoring snapshot, approval history, audit, object storage, dan role policies harus tersedia. Baca metadata/retention/export control decision serta `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Implementasikan laporan minimal:
   - scorecard employee, team, department/group, dan company;
   - target versus actual dan trend periode;
   - rating distribution dan KPI merah;
   - completeness, missing data, pending/overdue approval;
   - corrective action overdue;
   - KPI definition/version dan calculation reconciliation;
   - approval, correction, dan audit report.
2. Semua filter wajib tervalidasi dan scoped: period, organization, team, employee, KPI, status, source, shift/machine bila relevan.
3. Buat export Excel dan PDF secara asynchronous melalui queue untuk laporan besar. Tampilkan progress/status, failure reason aman, dan kemampuan retry idempotent.
4. Setiap export mencantumkan nama laporan, filter, periode, timezone, generated-at, generated-by, organization scope, template/version, dan classification bila diperlukan.
5. Simpan hasil export di object storage private dengan signed download URL, expiry, retention, dan audit download untuk data sensitif.
6. Cegah CSV/Excel formula injection, path traversal, unauthorized export, direct object access, dan kebocoran data lintas scope.
7. Gunakan read-optimized query yang tetap konsisten dengan source of truth. Materialized view hanya jika ada measurement dan refresh policy.
8. Tambahkan pagination/streaming/chunking agar export besar tidak menghabiskan memory.
9. Buat reconciliation test terhadap scorecard UI dan calculation snapshot, authorization test, export injection test, retry test, serta performance test pada dataset representatif.
10. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Nilai laporan sama dengan scorecard dan dapat direkonsiliasi.
- Export tidak dapat dipakai untuk melewati hak akses.
- File besar diproses worker tanpa blocking request panjang.
- Metadata dan audit export lengkap.
- Expired download link tidak dapat digunakan kembali.

## Laporan akhir

Laporkan katalog laporan, format, ukuran dataset uji, waktu/memory export, security result, retention yang berlaku, dan status `PASS/PARTIAL/BLOCKED`.
