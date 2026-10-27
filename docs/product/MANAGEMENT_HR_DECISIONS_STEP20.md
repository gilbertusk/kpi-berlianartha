# Draft Usulan Management dan HR Performance — Step 20 Staging, Pilot, dan UAT

**Tanggal:** 27 Oktober 2026  
**Pemberi keputusan:** Belum ada; memerlukan Management dan HR Performance manusia yang berwenang
**Tujuan:** Bahan workshop keputusan sebelum staging dan UAT pilot.
**Status koreksi:** `DRAFT / PROPOSED — BUKAN BUKTI PERSETUJUAN`
**Catatan:** Versi awal dibuat dari role-play AI dan keliru ditulis sebagai keputusan nyata. Seluruh angka, aturan, pilihan team, dan pihak pada dokumen ini adalah usulan yang belum disetujui. Dokumen ini tidak boleh digunakan untuk publish KPI, membuka UAT, atau memberi keputusan GO.

---

## 1. Usulan Team Pilot (DEC-007 — PROPOSED)

Berdasarkan PRD §4 dan rencana implementasi bertahap, diusulkan tiga team pilot yang mewakili tiga group operasional:

| Group | Team pilot | Kode unit | Jumlah pengguna (perkiraan) | Peran yang diuji |
|---|---|---|---|---|
| Commercial dan Support | Sales | `COMSUP-SALES` | 8 | 1 TL, 1 Head, 6 Staff |
| Accounting | Account Receivable | `ACC-AR` | 6 | 1 TL, 1 Head, 4 Staff |
| Produksi | Woven | `PROD-WOVEN` | 8 | 1 TL, 1 Head, 6 Staff |

**Total pengguna pilot:** 22 orang  
**Concurrent user estimasi:** 10 orang  
**Volume data harian estimasi:** 200 entri aktual (target + actual per hari)

**Pemilik data pilot:**
- Sales: Head Commercial dan Support
- Account Receivable: Head Accounting
- Woven: Head Produksi

**UAT approver per team:** Management dan HR Performance (secara bersama)

---

## 2. Rantai Approval dan Pengganti (DEC-002 — PROPOSED)

Usulan rantai approval untuk setiap team pilot:

| Tugas | Pelaku utama | Pengganti bila posisi kosong |
|---|---|---|
| Verifikasi aktual (harian) | Team Leader | Head unit terkait |
| Review periode | Department Head | Management |
| Kalibrasi | HR Performance | Management |
| Approval final | Management | HR Performance (dengan catatan alasan) |

**Aturan pengganti:**
- Pengganti hanya dapat diaktivasi dengan persetujuan Management tertulis.
- Pengganti tidak boleh mengeset approve untuk data milik dirinya sendiri (self-approval tetap dilarang).
- Pencabutan role pengganti dicatat di audit.

---

## 3. Bobot Scorecard (DEC-003 — PROPOSED)

Komposisi bobot dari PRD diusulkan sebagai **bobot awal pilot**, total 100%:

### Staff operasional
| Komponen | Bobot |
|---|---:|
| KPI individu | 50% |
| KPI team | 30% |
| Disiplin dan keselamatan | 10% |
| Kompetensi dan perilaku | 10% |
| **Total** | **100%** |

### Team Leader
| Komponen | Bobot |
|---|---:|
| Pencapaian KPI team | 55% |
| Kualitas dan produktivitas | 15% |
| Leadership dan pengelolaan anggota | 15% |
| Improvement | 10% |
| Keselamatan dan kedisiplinan | 5% |
| **Total** | **100%** |

Bobot ini berlaku untuk **pilot saja** dan dapat disesuaikan per team di tahap berikutnya setelah evaluasi pascapilot.

---

## 4. Scoring dan Rating (DEC-004 — PROPOSED)

Usulan aturan scoring untuk pilot:

| Aturan | Keputusan |
|---|---|
| Strategi skor yang diizinkan | `ratio_to_target@1`, `inverse_ratio_to_target@1`, `range_band@1`, `binary@1`, `milestone_stage@1` |
| Rating band | 0–100 (skor mentah), dikonversi ke grade A–E oleh HR |
| Cap/floor | Skor dibatasi 0–100 |
| Toleransi | ±2% dari target dianggap mencapai target |
| Rounding | 2 desimal untuk skor akhir, 4 desimal untuk perhitungan tengah |
| Zero denominator | Skor = 0 bila target = 0 dan aktual = 0; skor = null bila target = 0 dan aktual > 0 |
| N/A (Tidak Berlaku) | Diabaikan dari perhitungan bobot, wajib beralasan |
| Missing (Tidak Ada Data) | Tidak diperlakukan sebagai nol; ditandai sebagai missing dan memicu reminder |
| Late-data rule | Aktual yang disimpan setelah deadline ditandai terlambat, tetap dapat diverifikasi dengan alasan |
| Golden example | Ditandatangani oleh HR Performance dan Management sebelum UAT |

---

## 5. Cakupan KPI Rilis Pilot (DEC-005 — PROPOSED)

KPI berikut diusulkan sebagai cakupan wajib pilot (dari 252 kandidat Lampiran PRD, yang lain tetap DRAFT):

| Team | KPI pilot (contoh) |
|---|---|
| Sales | Revenue achievement, Sales volume, Customer baru |
| Account Receivable | Days Sales Outstanding, Collection rate, Aging receivable |
| Woven | Good output, Output per jam, Production yield, Waste percentage |

Semua KPI pilot harus memiliki definisi lengkap (DEC-006) sebelum dipublish.

---

## 6. Definisi KPI Pilot (DEC-006 — PROPOSED)

Setiap KPI pilot harus memiliki:
- Target (angka atau rentang)
- Formula (dari strategi yang diizinkan DEC-004)
- Satuan (mis. %, rupiah, jumlah)
- Frekuensi (harian, bulanan)
- Sumber data (manual entri atau import terkontrol)
- Pemilik data (unit owner)
- Evidence (bila wajib, file bukti harus diunggah)
- Approver (TL untuk verifikasi, Head untuk review, Management untuk final)

Definisi KPI pilot harus memperoleh persetujuan HR Performance dan unit owner masing-masing sebelum digunakan.

---

## 7. Retention, Service Level, dan Browser (DEC-010 — PROPOSED)

| Kebijakan | Keputusan |
|---|---|
| Retensi data transaksi | 2 tahun |
| Retensi bukti/evidence | 1 tahun |
| Retensi audit log | 5 tahun |
| RPO | ≤ 24 jam |
| RTO | ≤ 4 jam |
| Availability | 99,5% jam operasional |
| Maintenance window | Sabtu 22:00–04:00 WIB |
| Browser resmi | Chromium (Chrome/Edge), Firefox, Safari |
| Mobile | Tidak wajib untuk pilot (divalidasi nanti) |

---

## 8. Autentikasi dan MFA (DEC-013 — PROPOSED untuk pilot)

| Kebijakan | Keputusan |
|---|---|
| Identity provider | Login lokal (email/password) untuk pilot |
| SSO | Ditunda ke production (DEC-013 tetap terbuka untuk production) |
| MFA | Opsional untuk pilot, tidak wajib |
| Kebijakan penguncian akun | 5 kali gagal login = kunci 30 menit |
| Masa idle session | 120 menit |

---

## 9. Hosting Staging (DEC-014 — PROPOSED untuk staging)

| Komponen | Keputusan |
|---|---|
| Lingkungan | Staging terpisah dari production |
| Database | PostgreSQL 18, database `kpi_staging` |
| Cache/Queue | Redis 8, terpisah dari production |
| Object storage | Private S3-compatible, bucket `kpi-staging-*` |
| Domain/TLS | Domain staging dengan TLS valid |
| Email | SMTP test / Mailpit, tidak mengirim ke pelanggan asli |
| Secret manager | Disimpan di luar kode dan repo |
| Owner operasional | IT dan operator yang ditunjuk Management |

---

## 10. Integrasi dan Master Data (DEC-008, DEC-016 — DEFERRED untuk pilot)

- Integrasi runtime **tidak diaktifkan** untuk pilot. Data masuk lewat file import terkontrol (CSV/XLSX) dengan rekonsiliasi.
- Master data pegawai untuk pilot menggunakan data sintetis atau data yang telah disanitasi dan disetujui pemilik data.
- Sistem sumber eksternal dan connector MES/WMS/ERP ditunda ke tahap berikutnya.

---

## 11. Kalender Operasional (DEC-015 — PROPOSED untuk pilot)

- Hari kerja: Senin–Jumat, cut-off entri aktual pukul 23:59 WIB.
- Shift produksi: tidak digunakan untuk pilot Woven (data harian tanpa dimensi shift).
- Tanggal operasional mengikuti kalender Asia/Jakarta.

---

## 12. Data Sensitif dan Coaching Notes (DEC-011 — PROPOSED dengan batasan)

- Coaching notes diklasifikasikan **restricted**.
- Akses default: subject (pegawai sendiri), TL berwenang, HR sesuai purpose, Auditor sesuai mandate.
- Coaching notes **tidak diuji dalam pilot inti** (divalidasi dalam tahap berikutnya).

---

## 13. Kompensasi (DEC-012 — DEFERRED)

Skor KPI pilot **tidak digunakan** untuk bonus, payroll, atau keputusan kompensasi. Kebijakan kompensasi ditunda sampai ada kebijakan terpisah, legal review, dan sign-off.

---

## 14. Sign-off Management dan HR — Belum Ada

| Pihak | Nama/Role | Keputusan | Tanggal | Referensi |
|---|---|---|---|---|
| Management | Belum ditentukan | OPEN | — | — |
| HR Performance | Belum ditentukan | OPEN | — | — |
| IT | Belum ditentukan | OPEN | — | — |
| Unit pilot Sales | Belum ditentukan | OPEN | — | — |
| Unit pilot AR | Belum ditentukan | OPEN | — | — |
| Unit pilot Woven | Belum ditentukan | OPEN | — | — |

---

## 15. Langkah Berikutnya yang Diharapkan dari Tim IT

1. Menyediakan staging terpisah sesuai `STAGING_ENVIRONMENT.md`.
2. Menjalankan deployment sesuai `STAGING_DEPLOYMENT_PLAN.md`.
3. Melakukan smoke test dan restore rehearsal staging.
4. Menyediakan akun untuk 22 pengguna pilot (tanpa kredensial pribadi di repo).
5. Melaksanakan UAT-01 s.d. UAT-12 dengan tim pilot.
6. Kumpulkan sign-off tertulis dari semua pihak.

**Production tetap BLOCKED** sampai seluruh gate Step 20 terpenuhi dan sign-off lengkap diterima.
