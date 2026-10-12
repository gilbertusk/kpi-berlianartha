# Prompt Step 09 — Scoring Engine dan Scorecard

Anda bertindak sebagai Domain Engineer dan Test Engineer untuk kalkulasi KPI. Bangun mesin skor deterministik yang hanya memakai aturan bisnis yang telah disetujui.

## Prasyarat

Pastikan decision register memiliki aturan direction, formula, tolerance, cap, N/A, missing, rounding, bobot, dan rating. Jika aturan inti masih `OPEN`, bangun interface dan test fixture saja, tandai engine bisnis `BLOCKED`, dan jangan menebak angka.

## Pekerjaan

1. Implementasikan pure scoring domain service terpisah dari controller, Livewire, database query, dan tampilan.
2. Dukung strategy yang benar-benar disetujui, misalnya higher-is-better, lower-is-better, target-band, boolean/milestone, atau custom-approved strategy.
3. Gunakan decimal arithmetic dan urutan pembulatan eksplisit. Dokumentasikan precision pada target, actual, achievement, score, weight, contribution, dan final score.
4. Tangani zero denominator, negative values, missing, N/A, outlier, score cap/floor, tolerance, dan late data secara eksplisit.
5. Buat immutable calculation snapshot yang merekam input, definition version, formula/strategy version, intermediate values, output, timestamp, dan alasan recalculation.
6. Implementasikan idempotent recalculation. Recalculation tidak boleh menimpa hasil final tanpa workflow yang sah.
7. Implementasikan aggregation employee → team → group/department → company hanya sesuai rule yang disetujui; jangan otomatis memakai average jika denominasi bisnis berbeda.
8. Buat scorecard UI yang menunjukkan target, actual, achievement, score, weight, contribution, rating, data status, serta drill-down ke sumber/evidence sesuai akses.
9. Sediakan reconciliation view dan export sederhana untuk HR/auditor.
10. Buat exhaustive table-driven test, property/boundary test, golden examples yang ditandatangani business owner, serta comparison terhadap perhitungan manual.
11. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Input dan rule version yang sama selalu menghasilkan output yang sama.
- Total final dapat direkonsiliasi hingga kontribusi KPI individual.
- Boundary rating dan rounding teruji pada nilai tepat di batas dan di sekitarnya.
- Missing/N/A tidak diam-diam dianggap nol.
- Formula lama tetap mereproduksi scorecard periode lama setelah rule baru dipublish.

## Laporan akhir

Tampilkan beberapa golden example tanpa data sensitif, test matrix, perbedaan dari hitung manual, keputusan formula yang belum disetujui, dan status `PASS/PARTIAL/BLOCKED`.
