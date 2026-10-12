# Prompt Step 06 — Kamus KPI dan Template Berversi

Anda bertindak sebagai Domain Engineer untuk KPI governance. Implementasikan KPI dictionary dan template yang berversi. Kandidat KPI di lampiran PRD tidak otomatis menjadi KPI aktif.

## Prasyarat

Baca decision register, KPI definition template, organization model, audit conventions, dan `PROJECT_PROGRESS.md`. Hanya gunakan definisi berstatus `APPROVED` sebagai data resmi; sisanya boleh menjadi draft berlabel jelas.

## Pekerjaan

1. Implementasikan KPI definition dengan field minimal: code, name, purpose, description, owner, evaluation subject, unit, value type, direction, frequency, aggregation, formula reference, data source, input method, evidence requirement, exception policy, effective dates, status, dan version.
2. Pisahkan formula/score rule dari teks bebas. Gunakan konfigurasi tervalidasi atau strategy yang di-whitelist; jangan menjalankan expression arbitrary dari pengguna.
3. Implementasikan lifecycle `DRAFT → REVIEW → PUBLISHED → RETIRED`. Published version immutable. Perubahan membuat versi baru dengan effective date dan alasan.
4. Implementasikan KPI template per team/jabatan dengan items, bobot, urutan, mandatory flag, dan applicability rule.
5. Validasi total bobot setiap scorecard tepat 100% sebelum template dapat dipublish, sesuai PRD. Perubahan terhadap aturan total 100% hanya boleh dilakukan melalui revisi PRD dan keputusan bisnis formal.
6. Cegah overlapping effective dates atau dua published version yang ambigu pada scope sama.
7. Buat UI untuk dictionary, version comparison, template builder, preview, review/publish, retire, dan audit history.
8. Impor kandidat dari lampiran PRD sebagai `DRAFT CANDIDATE` bila dibutuhkan, bukan published configuration.
9. Tambahkan factory, seeder aman, validation test, versioning test, effective-date test, weight test, authorization test, serta immutability test.
10. Dokumentasikan cara menambah unit, frequency, direction, dan scoring strategy tanpa merusak histori.
11. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- Jangan mengubah definisi published in-place.
- Jangan membuat formula berdasarkan nama KPI atau team di controller.
- Jangan memakai `eval`, raw executable code, atau expression engine tanpa allowlist.
- KPI yang belum memiliki owner, source, formula, target rule, dan approver tidak boleh dipublish.

## Acceptance criteria

- HR dapat membuat draft, meminta review, dan mempublish template sesuai policy.
- Dua periode berbeda dapat memakai versi template berbeda tanpa mengubah histori.
- Total bobot, effective date, dan kelengkapan definisi tervalidasi.
- Perbandingan versi menunjukkan field yang berubah dan alasan.
- Candidate KPI tetap jelas berbeda dari approved KPI.

## Laporan akhir

Laporkan schema, lifecycle, published-versus-draft count, strategy yang didukung, test result, keputusan yang masih terbuka, dan status `PASS/PARTIAL/BLOCKED`.
