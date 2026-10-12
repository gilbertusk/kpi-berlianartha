# Prompt Step 19 — Pengujian Menyeluruh dan Kualitas

Anda bertindak sebagai QA Lead dan Performance Engineer. Bangun bukti kualitas release candidate dari requirement sampai runtime. Jangan memperbaiki business rule dengan asumsi; eskalasikan requirement yang ambigu.

## Prasyarat

Seluruh feature step 05–18 yang masuk release scope harus selesai atau tercatat deferred. Baca PRD acceptance criteria, test strategy, browser/support decision, performance target, security report, dan `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Buat requirement traceability matrix: PRD/decision → acceptance criteria → automated/manual test → evidence → status.
2. Lengkapi test pyramid:
   - unit untuk scoring, date, state transition, policy helper;
   - feature/integration untuk database transaction, authorization, queue, storage, import/export;
   - Playwright E2E untuk journey utama setiap role;
   - contract test untuk integrasi;
   - security regression;
   - accessibility dan responsive test;
   - performance/load test dengan workload yang disetujui.
3. Journey minimal: HR publish template/period, target, employee actual/evidence, TL verify, Manager review, HR calibration, Management approve, lock, correction, report/export, audit trace.
4. Uji role/scope matrix dengan positive dan negative scenario, termasuk direct URL, forged request, Livewire action, download, export, dan expired delegation.
5. Uji concurrency: duplicate submit, double approval, recalculation bersamaan, repeated import, queue retry, lock versus edit, dan correction versus export.
6. Uji data boundary: zero, negative bila sah, missing, N/A, decimal precision, large value, date boundary Asia/Jakarta, overnight shift, effective date, and period rollover.
7. Gunakan dataset representatif namun anonim/sintetis. Ukur page response, database queries, queue age, export duration/memory, dan resource usage. Halaman standar ditargetkan tampil dalam 3 detik pada beban yang disepakati sesuai PRD; catat percentile, dataset, concurrency, dan environment pengujian.
8. Jalankan browser matrix yang disetujui dan viewport desktop/tablet. Lakukan accessibility scan dan manual keyboard/focus review.
9. Hapus flaky behavior; jangan sekadar menaikkan timeout. Catat known issue, severity, owner, workaround, dan release decision.
10. Buat `docs/qa/RELEASE_TEST_REPORT.md` serta evidence index. Perbarui `PROJECT_PROGRESS.md`.

## Exit gate

- Tidak ada defect Critical atau High yang terbuka untuk release scope.
- Seluruh acceptance criteria rilis inti memiliki evidence.
- Authorization negative tests lulus.
- Scoring cocok dengan golden examples bisnis.
- Performance memenuhi target yang disepakati atau memiliki approved exception.
- Tidak ada flaky test yang disembunyikan/skipped tanpa issue dan owner.

Step ini dijalankan minimal dua kali: pertama untuk core readiness sebelum pilot, lalu diulang sebagai full regression setelah fitur khusus/integrasi selesai.

## Laporan akhir

Laporkan jumlah test dan hasil per kategori, coverage pada critical domain, performance percentile, accessibility finding, defect per severity, skipped/deferred test, dan status `PASS/PARTIAL/BLOCKED`.
