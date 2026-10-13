# Golden Example Scoring

**Status:** SINTETIS — BELUM DITANDATANGANI. Angka di bawah hanya membuktikan mesin skor cocok dengan hitungan manual. Bukan aturan bisnis.

Contoh bertanda tangan business owner menggantikan dokumen ini setelah DEC-004 (aturan skor dan rating) dan DEC-006 (definisi KPI) disetujui. Formatnya mengikuti `KPI_DEFINITION_TEMPLATE.md` §H. Test otomatisnya ada di `tests/Unit/Scoring/GoldenExamplesTest.php`.

## Aturan yang dipakai contoh

Seluruhnya fiktif dan hanya untuk pengujian:

- Pembulatan KPI: half up, 2 desimal (kecuali disebut lain).
- Policy: kontribusi half up 4 desimal; skor akhir half up 2 desimal; data terlambat dihitung; bobot KPI N/A dibagi proporsional.
- Rating band: `r1` di bawah 60; `r2` [60, 80); `r3` [80, 100); `r4` 100 ke atas.

## GE-01 — Tiga KPI, semua dinilai

| KPI | Bobot | Target | Aktual | Hitungan manual | Skor KPI | Kontribusi |
|---|---:|---:|---|---|---:|---:|
| GE-QUALITY (higher is better, rata-rata) | 50% | 98 | 97.5; 98.2; 99.1 | (97.5 + 98.2 + 99.1) ÷ 3 = 98.2666…; ÷ 98 × 100 = 100.2721… | 100.27 | 50.1350 |
| GE-REJECT (lower is better, jumlah) | 30% | 12 | 4; 5; 2 | 4 + 5 + 2 = 11; 12 ÷ 11 × 100 = 109.0909… | 109.09 | 32.7270 |
| GE-DISCIPLINE (binary) | 20% | — | Ya | Ya → 100 | 100 | 20.0000 |

Total kontribusi 50.1350 + 32.7270 + 20.0000 = **102.8620** → skor akhir **102.86** → rating `r4`.

## GE-02 — Satu KPI N/A

GE-A (bobot 60%): aktual 88 dan 92, rata-rata 90, target 100 → skor 90.00. GE-B (bobot 40%): N/A, bukan nol.

| Aturan bobot N/A | Hitungan | Skor akhir | Rating |
|---|---|---:|---|
| Dibagi proporsional | 90.00 × 60 ÷ 60 = 90.0000 | 90.00 | `r3` |
| Pembagi tetap 100% | 90.00 × 60 ÷ 100 = 54.0000 | 54.00 | `r1` |

Perbedaan hasil ini adalah alasan aturan bobot N/A harus diputuskan, bukan diasumsikan.

## GE-03 — Data missing yang ditahan

GE-A (60%) dinilai. GE-B (40%) tidak memiliki entri dan aturan KPI-nya "tahan sampai data tersedia". Hasil: scorecard **belum lengkap**, penahan `kpi_blocked`, tanpa skor akhir dan tanpa rating. Missing tidak dihitung sebagai nol.

## GE-04 — Urutan pembulatan dan batas rating

| Aktual ÷ target | Pembulatan KPI | Skor KPI | Skor akhir | Rating |
|---|---|---:|---:|---|
| 2 ÷ 3 × 100 = 66.666… | 2 desimal | 66.67 | 66.67 | `r2` |
| 79.995 ÷ 100 × 100 | 4 desimal | 79.9950 | 80.00 | `r3` |

Pada baris kedua, pembulatan akhir (half up) menaikkan 79.9950 menjadi 80.00 sebelum rating dipilih, sehingga hasilnya `r3`, bukan `r2`.

## Perbedaan dari hitungan manual

Tidak ada: setiap angka pada tabel sama dengan keluaran mesin di test.

## Tanda tangan

| Peran | Nama | Keputusan | Tanggal | Referensi |
|---|---|---|---|---|
| Management | — | Belum | — | — |
| HR Performance | — | Belum | — | — |
| Process owner | — | Belum | — | — |
