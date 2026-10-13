# KPI Governance: Kamus KPI dan Template Berversi

**Status:** Baseline Step 06 — 12 Oktober 2026
**Referensi:** PRD §7, ADR-0006 (effective dating), ADR-0008 (numeric precision), `docs/product/KPI_DEFINITION_TEMPLATE.md`, DEC-003/004/005/006

Modul `app/Modules/KpiGovernance` menyimpan definisi KPI dan template scorecard. Belum ada KPI resmi yang di-seed. Bobot, formula, dan rating dari PRD masih draft, sehingga setiap versi published wajib membawa referensi sign-off bisnis.

## 1. Schema

| Tabel | Isi |
|---|---|
| `kpi_definitions` | Identitas stabil: `code` (unik), `origin` (`authored` atau `prd_candidate`), dan unit pemilik |
| `kpi_definition_versions` | Isi definisi per versi (lihat §2), status, periode berlaku, alasan perubahan, versi sebelumnya, data workflow (pembuat, pengaju, pengembali, penerbit, retire), dan `lock_version` |
| `kpi_templates` | Identitas template untuk satu scope: unit, jenis scorecard (`employee` atau `team`), dan jabatan opsional. Unik per scope |
| `kpi_template_versions` | Catatan, referensi sign-off, periode berlaku, alasan perubahan, workflow, dan `lock_version` |
| `kpi_template_items` | KPI yang **dikunci ke satu versi definisi**, beserta komponen, bobot `numeric(7,4)`, urutan, flag wajib, dan aturan applicability |

Isi versi hanya dapat diubah selama berstatus DRAFT. Trigger database (PostgreSQL dan SQLite) menolak tiga jenis perubahan, termasuk yang dilakukan lewat SQL langsung:

- perubahan kolom isi pada versi non-draft;
- transisi status di luar lifecycle;
- `DELETE` versi non-draft, dan insert/update/delete item pada versi template non-draft.

## 2. Isi definisi KPI

Field mengikuti `KPI_DEFINITION_TEMPLATE.md`:

- **Identitas dan kepemilikan:** nama, tujuan, deskripsi, process owner, data owner, level penilaian.
- **Pengukuran:** satuan, tipe nilai, arah, frekuensi, agregasi.
- **Aturan input (Step 07, opsional):** jumlah desimal input (`decimal_places`, 0–4) serta nilai input minimum dan maksimum (`min_valid_value`, `max_valid_value`, disimpan sebagai string desimal kanonis). Target divalidasi terhadap aturan ini; aktual menyusul pada Step 08. Ketiganya termasuk isi versi, sehingga dibekukan sejak versi keluar dari draft.
- **Formula:** formula (uraian), pembilang, penyebut, strategi skor dan versinya, parameter skor.
- **Sumber dan bukti:** sumber data, metode input, kebutuhan bukti beserta uraiannya.
- **Aturan penilaian:** kebijakan pengecualian (missing, N/A, terlambat), aturan target.
- **Governance:** approver atau jalur approval, referensi sign-off, tanggal berlaku.

Publish ditolak bila field wajib (`DefinitionContent::REQUIRED_FOR_PUBLISH`) belum lengkap. Termasuk di dalamnya guardrail prompt: owner, sumber, formula, aturan target, approver, dan sign-off. Strategi skor juga harus valid dan sesuai dengan arah KPI.

## 3. Lifecycle

```text
DRAFT ──submit──▶ REVIEW ──publish──▶ PUBLISHED ──retire──▶ RETIRED
   ▲                 │
   └────return───────┘
```

- **Submit.** Hanya untuk versi yang sudah lengkap. Isi versi dibekukan sejak masuk REVIEW.
- **Publish.** Harus dilakukan oleh akun **berbeda** dari pengaju (`self_review`), sesuai larangan self-approval di PRD §4.1.
- **Perubahan isi.** Versi published tidak pernah diubah. Perubahan memakai "Buat versi baru": isi disalin ke draft v(n+1), alasan perubahan wajib diisi, sedangkan sign-off dan tanggal berlaku dikosongkan agar diisi ulang.
- **Satu versi kerja.** Satu KPI atau template hanya boleh memiliki satu versi DRAFT atau REVIEW pada satu waktu.
- **Tanggal berlaku.**
  - Interval setengah terbuka.
  - Publish versi baru menutup versi published sebelumnya pada tanggal mulai versi baru. Hanya `effective_until` yang diisi; isi versi lama tidak disentuh.
  - Penggantian versi tidak boleh mundur (`retroactive_change`) dan tidak boleh tumpang tindih (`effective_overlap`).
  - Publikasi pertama boleh memakai tanggal mulai lampau karena belum ada histori yang berubah.
- **Retire.** Mengisi tanggal akhir hari ini atau sesudahnya. Versi retired tetap menjawab untuk tanggal saat versi itu berlaku (`scopeEffectiveOn`).

## 4. Template scorecard

- **Bobot.** Total bobot wajib **tepat 100%** (PRD §7.4). Bobot disimpan dengan 4 desimal dan dijumlahkan sebagai bilangan bulat (satuan 0,0001); float tidak pernah dipakai. Aturan 100% hanya dapat diubah melalui revisi PRD dan keputusan bisnis formal.
- **Isi template.** Satu KPI hanya boleh muncul sekali per versi template. Item dikunci ke versi definisi tertentu, sehingga scorecard lama selalu dapat direproduksi.
- **Syarat publish template.**
  - Semua versi KPI yang dirujuk sudah published dan berlaku pada tanggal mulai template.
  - Referensi sign-off sudah diisi.
- **Applicability item.** Aturan applicability hanya berupa daftar jabatan terstruktur `{"positions": [...]}`. Expression bebas tidak didukung.
- **Kontrak untuk step berikutnya.** `TemplateResolver::versionOn()` dan `forScope()` adalah kontrak baca untuk Step 07. Dua periode dengan tanggal berbeda akan mendapat versi template yang berlaku masing-masing. Pemakaiannya dijelaskan di `PERIOD_TARGET.md` §2.
- **Bila KPI mendapat versi baru.** Template yang sudah published tetap merujuk versi KPI lama. Untuk memakai versi KPI baru, buat versi template baru.

## 5. Strategi skor (allowlist)

| Key@versi | Arah yang didukung | Parameter wajib |
|---|---|---|
| `ratio_to_target@1` | Higher is better | aturan umum + `score_floor`, `score_cap`, `zero_target_rule` |
| `inverse_ratio_to_target@1` | Lower is better | aturan umum + `score_floor`, `score_cap`, `zero_target_rule`, `zero_actual_rule` |
| `range_band@1` | Range, higher, lower | aturan umum + `bands` [min, max) berurutan tanpa overlap, `out_of_band_score` |
| `binary@1` | Binary | aturan umum + `achieved_score`, `not_achieved_score` |
| `milestone_stage@1` | Milestone | aturan umum + `stages` (label, skor tidak menurun) |

Aturan umum yang wajib dipilih untuk setiap KPI:

- `missing_data_rule`;
- `rounding_mode`;
- `rounding_scale` (0–4).

Sistem tidak memberi nilai default untuk aturan-aturan ini karena DEC-004 masih OPEN. Angka disimpan sebagai string desimal kanonis, dan key yang tidak dikenal dibuang. Tidak ada `eval`, expression engine, atau kode dari pengguna. Perhitungan skor diimplementasikan pada Step 09 dengan key dan versi yang sama.

## 6. Menambah unit, frekuensi, arah, atau strategi tanpa merusak histori

1. **Satuan, frekuensi, arah, agregasi, metode input, tipe nilai.** Tambahkan key baru di `Domain/Vocabulary.php`. Key yang sudah dipakai jangan pernah dihapus atau di-rename, karena versi published menyimpan key tersebut. Label boleh diperbaiki. Bila arah baru membutuhkan strategi baru, lanjutkan ke langkah 2.
2. **Strategi skor baru.**
   - Buat kelas yang mengimplementasikan `ScoreStrategy` dengan key baru, lalu daftarkan di `ScoreStrategyRegistry`.
   - Untuk mengubah perilaku strategi yang sudah ada, buat **versi baru** (misalnya `ratio_to_target@2`) sebagai kelas atau konfigurasi terpisah. Versi lama jangan diubah, karena KPI published dan periode terkunci harus tetap menghitung dengan aturan lamanya.
   - Draft baru otomatis memakai versi terbaru (`current()`). Versi published tetap memakai versi yang tersimpan.
3. **Aturan applicability baru.** Tambahkan key baru di `ItemApplicability` dengan validasi eksplisit. Jangan menambahkan field teks bebas yang akan dievaluasi.
4. Tambahkan unit test untuk validasi strategi atau vocabulary baru, serta test bahwa versi lama tetap tervalidasi seperti sebelumnya.

## 7. Akses

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `kpi_definition.view` / `kpi_template.view` | A | A (scope) | A (scope) | — | A | A | — |
| `kpi_definition.manage` / `kpi_template.manage` | — | — | — | — | A (scope) | — | — |
| `kpi_definition.publish` / `kpi_template.publish` | — | — | — | — | A (scope) | — | — |

- **Scope.** Definisi mengikuti unit pemilik; template mengikuti unit template.
- **Hak bersyarat yang belum diaktifkan.** Hak "C" untuk Manager dan TL ("Membuat/mengubah draft template") serta Management dan Manager ("Publish template") di ROLE_APPROVAL_MATRIX belum diaktifkan sampai policy-nya diputuskan.
- **Invariant.** System Admin dan Auditor tidak pernah memegang capability konfigurasi KPI.

## 8. Kandidat PRD

`kpi:import-candidates` (dan `KpiCandidateSeeder` pada `APP_ENV=local`) mengimpor 252 kandidat dari Lampiran A PRD.

- **Kode.** Format `CAND-<TEAM>-<NN>`, dengan `origin = prd_candidate`.
- **Status awal.** DRAFT dengan nama saja.
- **Tampilan.** Dashboard dan daftar menampilkan label "Kandidat PRD".
- **Publish.** Kandidat tidak dapat diajukan atau dipublish sampai semua field dan sign-off dilengkapi.
- **Design A dan Design B.** Kandidat untuk kedua team dibuat terpisah walaupun namanya sama (DEC-001).
