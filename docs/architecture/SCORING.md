# Scoring Engine dan Scorecard

**Status:** Baseline Step 09 — 13 Oktober 2026
**Referensi:** PRD §7.2–7.4, FR 009–010; ADR-0006, ADR-0008; `KPI_GOVERNANCE.md` §5; `ACTUAL_EVIDENCE.md`; DEC-003, DEC-004, DEC-006

Modul `app/Modules/Scoring` menghitung skor KPI dan scorecard. **Skor resmi masih diblokir:** DEC-004 belum disetujui, sehingga `config/scoring.php` tidak berisi policy apa pun dan setiap perhitungan ditolak dengan `scoring_policy_not_approved`. Mesin, snapshot, halaman, dan test sudah ada; yang belum ada adalah aturan bisnisnya.

## 1. Lapisan

| Lapisan | Isi |
|---|---|
| Domain (murni, tanpa framework dan database) | `KpiLineCalculator`, `ScorecardCalculator`, kalkulator per strategi, `ScorecardPolicy`, `RoundingRule`, `RatingBand`, `ActualAggregation`, `Exact` |
| Application | `ScoringService` (use case), `ScorecardInputLoader` (merakit input dari snapshot, target, dan aktual), `ScorecardQuery`, `ScoreAccess` |
| Infrastructure | Model snapshot dan `ConfiguredScorecardPolicies` |

Domain menerima `ScorecardInput` dan `ScorecardPolicy`, lalu mengembalikan `ScorecardResult`. Tidak ada query, waktu sistem, atau angka bawaan di dalamnya.

## 2. Urutan perhitungan

Setiap langkah dibulatkan satu kali, dari nilai eksak.

**Per KPI (`KpiLineCalculator`):**

1. Entri terlambat dibuang bila policy menyatakan demikian. Entri tersisa yang belum terverifikasi membuat KPI menunggu; skor tidak pernah memakai data setengah terverifikasi.
2. KPI yang dinilai terhadap target dan belum memiliki target final berstatus `target_missing`, juga saat tidak ada entri. Aturan missing tidak pernah menutupi target yang belum ada.
3. Entri N/A terverifikasi dikeluarkan dari nilai. Hanya N/A → KPI berstatus N/A. Tidak ada entri → aturan `missing_data_rule` pada definisi KPI. Bila entri ada tetapi semuanya dibuang sebagai terlambat, catatan baris menyebutkannya (`…_late_excluded`).
4. Nilai di luar rentang valid definisi → tidak dinilai.
5. Agregasi yang ditandatangani pada definisi menghasilkan nilai terukur periode.
6. Strategi dari versi definisi yang dikunci template menghasilkan skor eksak.
7. Pembulatan KPI (mode dan jumlah desimal pada definisi) menghasilkan skor KPI.

**Per scorecard (`ScorecardCalculator`):**

1. Kontribusi = skor KPI × bobot ÷ pembagi, dibulatkan dengan aturan kontribusi policy. Pembagi adalah total bobot (100), atau bobot KPI yang dinilai bila policy membagi ulang bobot KPI N/A.
2. Total kontribusi = jumlah eksak kontribusi yang sudah dibulatkan.
3. Skor akhir = total kontribusi dibulatkan dengan aturan akhir policy.
4. Rating = band policy yang memuat skor akhir.

Dengan urutan ini, total selalu dapat direkonsiliasi ke kontribusi tiap KPI.

## 3. Presisi

| Nilai | Presisi |
|---|---|
| Target, aktual | `numeric(18,4)`, divalidasi terhadap aturan input definisi |
| Bobot | `numeric(7,4)` |
| Nilai terukur dan achievement | Rasional eksak (`BigRational`); tidak dibulatkan. Disimpan sebagai teks pecahan pada jejak perhitungan dan teks tampilan |
| Skor KPI | Dibulatkan dengan aturan definisi KPI (0–4 desimal), `numeric(18,4)` |
| Kontribusi, total, skor akhir | Dibulatkan dengan aturan policy (0–4 desimal), `numeric(18,4)` |

Float tidak dipakai. Aritmetika memakai `brick/math`. Mode pembulatan: `half_up`, `half_even`, `down`. Untuk nilai negatif, `half_up` membulatkan menjauhi nol (−0,5 menjadi −1) dan `down` menuju nol; ini perlu disebut saat policy ditandatangani. Batas rating band setengah terbuka: nilai tepat di batas masuk band di atasnya, dan pembulatan akhir dilakukan sebelum band dipilih.

Nilai skor yang ditetapkan definisi (batas bawah, batas atas, skor rentang, ya/tidak, tahap) tidak boleh memiliki desimal lebih banyak dari pembulatan KPI. Validasi ini ditambahkan ke publish definisi; versi lama yang melanggar tidak dinilai.

## 4. Policy scorecard

Aturan tingkat scorecard yang tidak ada di definisi KPI disimpan sebagai policy berversi di `config/scoring.php`, ditinjau lewat git seperti kode.

| Field | Isi |
|---|---|
| `key`, `version` | Identitas. Versi yang sudah dipakai tidak boleh berubah isi |
| `signoff_reference` | Referensi persetujuan bisnis; wajib |
| `effective_from`, `effective_until` | Masa berlaku setengah terbuka; antarpolicy tidak boleh tumpang tindih |
| `late_data_rule` | `include` atau `exclude` |
| `not_applicable_weight_rule` | `redistribute_proportionally`, `keep_full_denominator`, atau `block_until_resolved` |
| `contribution_rounding`, `final_rounding` | Mode dan jumlah desimal |
| `rating_bands` | Band berurutan tanpa celah: `key`, `label`, `min`, `max` |

- **Tidak ada nilai bawaan.** Setiap field wajib dinyatakan. Field tidak dikenal, band bercelah, atau entri tidak valid menghentikan seluruh perhitungan, bukan dilewati.
- **Tidak ada policy cadangan.** Tanpa policy yang berlaku pada tanggal acuan periode, perhitungan ditolak.
- Policy yang dipakai sebuah perhitungan disalin utuh ke snapshot beserta hash-nya. Bila isi sebuah versi yang sudah dipakai berubah, perhitungan berikutnya ditolak (`scoring_policy_changed`). Menutup versi dengan `effective_until` diperbolehkan.

## 5. Status data

| Status KPI | Arti | Pengaruh |
|---|---|---|
| `scored` | Dinilai | Menyumbang skor |
| `missing_scored_zero` | Missing, dan aturan KPI menyatakan skor nol | Menyumbang nol |
| `not_applicable`, `missing_not_applicable`, `rule_not_applicable` | N/A dari aktual, dari aturan missing, atau dari aturan nilai nol | Dikeluarkan; bobotnya mengikuti aturan policy |
| `missing_blocked` | Missing, dan aturan KPI menahan | Menahan scorecard |
| `pending_verification` | Ada entri belum terverifikasi | Menahan |
| `target_missing` | Target final belum ada | Menahan |
| `invalid_input`, `unsupported` | Data atau aturan tidak dapat dinilai | Menahan |

Missing dan N/A tidak pernah menjadi nol secara diam-diam. Nol hanya muncul bila aturan KPI yang ditandatangani menyatakannya.

Scorecard berstatus `complete` bila semua KPI dinilai atau dikeluarkan lewat aturan, dan skor akhir serta rating tersedia. Selain itu `incomplete`, dengan daftar penahan: `no_lines`, `kpi_blocked`, `weight_not_100`, `not_applicable_blocked`, `no_scored_kpi`, `rating_out_of_range`. Bobot yang tidak berjumlah 100 untuk subjek tertentu tidak dinormalisasi dengan tebakan.

## 6. Strategi dan agregasi

Setiap strategi allowlist Step 06 memiliki tepat satu kalkulator dengan key dan versi yang sama: `ratio_to_target@1`, `inverse_ratio_to_target@1`, `range_band@1`, `binary@1`, `milestone_stage@1`. Target nol atau negatif, aktual nol, dan nilai di luar aturan ditangani lewat parameter yang ditandatangani; tanpa aturan, KPI tidak dinilai.

Agregasi yang didukung: `sum`, `average`, `last_value`, `minimum`, `maximum`, `count`. `ratio_of_sums` membutuhkan pembilang dan penyebut per observasi, yang belum dicatat aktual, sehingga dilaporkan `unsupported` dan tidak didekati.

Mengubah perilaku strategi berarti versi strategi baru; mengubah perilaku mesin berarti `ENGINE_VERSION` baru. Snapshot menyimpan versi yang menghasilkannya.

## 7. Snapshot perhitungan

| Tabel | Isi |
|---|---|
| `scorecard_calculations` | Satu perhitungan satu subjek: nomor urut, perhitungan sebelumnya, versi mesin, policy lengkap dan hash-nya, input lengkap dan fingerprint-nya, status, penahan, bobot, total kontribusi, skor akhir, rating, alasan, pelaku, dan waktu |
| `scorecard_calculation_lines` | Satu baris per KPI: versi definisi, strategi, agregasi, bobot, target, nilai terukur, achievement, skor KPI, kontribusi, status data, dan jejak langkah |

- **Immutable.** Trigger database menolak `UPDATE` dan `DELETE` pada kedua tabel.
- **Idempotent.** Fingerprint dihitung dari versi mesin, aturan policy, dan input kanonis. Subjek yang fingerprint-nya tidak berubah mempertahankan perhitungan terakhirnya. Subjek yang berubah mendapat perhitungan baru dengan nomor urut berikutnya dan alasan; tidak ada yang ditimpa.
- **Kapan boleh.** Hanya saat periode IN_PROGRESS atau REVIEW. Periode FINALIZED atau LOCKED hanya berubah lewat workflow koreksi Step 10.
- **Alasan wajib** untuk setiap perhitungan.
- **Reproduksi.** Snapshot memuat seluruh input dan aturan, sehingga scorecard periode lama tetap dapat dibaca dan direproduksi setelah versi KPI atau policy baru dipublish.

## 8. Agregasi antarlevel

Tidak ada roll-up otomatis dari pegawai ke team, department, atau company. PRD dan decision register belum menetapkan aturannya, dan rata-rata tidak boleh dipakai bila denominasi bisnis berbeda. Scorecard unit dihitung dari template unit itu sendiri, dengan target dan aktual level unit. Roll-up ditambahkan setelah aturannya disetujui.

## 9. Akses

| Capability | Mgmt | Head | TL | Employee | HR | Auditor | SysAdmin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `scorecard.view` | A | A (scope) | A (scope) | A (diri sendiri) | A | A | — |
| `individual_scorecard.view` | — | — | — | — | A | A | — |
| `scorecard.calculate` | — | — | — | — | A (scope) | — | — |

- `scorecard.view` mencakup scorecard unit dalam scope dan scorecard milik sendiri.
- Scorecard pegawai lain membutuhkan `individual_scorecard.view`. Hak Manager dan Team Leader untuk melihat skor individu anggota masih bersyarat pada ROLE_APPROVAL_MATRIX dan belum diaktifkan.
- Bukti dibuka dari scorecard hanya untuk entri KPI tersebut dan akun yang berhak melihat aktualnya.

## 10. Halaman

| Halaman | Isi |
|---|---|
| `/scorecards` | Periode yang memuat scorecard dalam scope akun |
| `/periods/{period}/scorecards` | Daftar rekonsiliasi: subjek, status, skor akhir, rating; tombol hitung beralasan untuk HR; penjelasan blokir bila policy belum disetujui |
| `/periods/{period}/scorecards/{assignment}` | Target, aktual, achievement, skor, bobot, kontribusi, rating, status data per KPI, entri sumber dan buktinya, serta perhitungan sebelumnya |
| `/periods/{period}/scorecards/export.csv` | Ekspor rekonsiliasi sesuai scope, diaudit |

## 11. Test

- **Tabel per strategi:** rasio, rasio terbalik, rentang, binary, milestone, termasuk nol, negatif, dan parameter tidak dapat dipakai.
- **Boundary:** mode pembulatan tepat di, di bawah, dan di atas setengah; batas rating band; pembulatan akhir sebelum rating.
- **Properti (acak berbenih):** skor dalam batas bawah dan atas, monotonik, rekonsiliasi, deterministik dan tidak bergantung urutan entri, missing tidak pernah nol tanpa aturan.
- **Golden example:** `docs/product/SCORING_GOLDEN_EXAMPLES.md`. Contoh saat ini sintetis dan belum ditandatangani.
- **Snapshot:** immutable, idempotent, reproduksi periode lama.

## 12. Batasan yang diketahui

- Skor resmi diblokir sampai DEC-004 disetujui dan sebuah policy bertanda tangan ditambahkan.
- Golden example bertanda tangan business owner belum ada (DEC-004, DEC-006).
- `ratio_of_sums` belum didukung.
- Roll-up antarlevel belum ada (§8).
- Toleransi dan penanganan outlier tidak diimplementasikan karena tidak ada aturan yang disetujui; nilai di luar rentang valid definisi tidak dinilai.
- Flag wajib pada item template (`is_mandatory`) dicatat di snapshot tetapi belum memengaruhi perhitungan: apakah KPI wajib boleh menjadi N/A adalah pertanyaan aturan yang belum diputuskan.
- Halaman scorecard memuat ulang input seluruh periode untuk memeriksa apakah perhitungan sudah basi; cukup untuk skala saat ini dan diukur pada Step 19.
- Kalibrasi, approval final, dan penguncian skor adalah Step 10.
