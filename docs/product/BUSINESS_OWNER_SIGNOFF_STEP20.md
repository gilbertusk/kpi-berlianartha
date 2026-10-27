# Sign-off Pemilik Bisnis — Step 20 Staging, Pilot, dan UAT

**Tanggal:** 27 Oktober 2026  
**Pemberi sign-off:** Pemilik Bisnis (Project Owner)  
**Tujuan:** Menyelesaikan semua kewajiban pemilik bisnis sehingga tim IT dapat melaksanakan staging dan UAT, dan Step 21 dapat dimulai setelah bukti teknis tersedia.

---

## 1. Konfirmasi Keputusan Bisnis (APPROVED)

Saya sebagai pemilik bisnis menyatakan semua keputusan berikut telah disetujui dan tercatat dalam `docs/product/MANAGEMENT_HR_DECISIONS_STEP20.md`:

| DEC | Area | Keputusan | Referensi |
|---|---|---|---|
| DEC-002 | Approval chain | Rantai approval per team pilot; pengganti hanya dengan persetujuan Management tertulis | §2 |
| DEC-003 | Bobot scorecard | Komposisi PRD sebagai bobot awal pilot, total 100% | §3 |
| DEC-004 | Scoring/rating | Aturan scoring pilot, strategi berversi, N/A diabaikan, missing bukan nol | §4 |
| DEC-005 | KPI release scope | Tiga team pilot dengan KPI minimum; sisa DRAFT | §5 |
| DEC-006 | KPI definition | Definisi lengkap wajib per KPI pilot, disetujui HR dan unit owner | §6 |
| DEC-007 | Team pilot | Sales, Account Receivable, Woven; 22 pengguna, 10 concurrent, 200 entri/hari | §1 |
| DEC-010 | Retention dan service level | Data 2 tahun, bukti 1 tahun, audit 5 tahun; RPO ≤ 24 jam, RTO ≤ 4 jam | §7 |
| DEC-013 | Authentication | Login lokal untuk pilot, SSO ditunda, MFA opsional | §8 |
| DEC-014 | Hosting | Staging terpisah dari production | §9 |

Keputusan berikut **tidak diubah** dan tetap sesuai status sebelumnya:
- DEC-008 (integrasi runtime): OPEN — tidak diaktifkan untuk pilot
- DEC-009 (SLA workflow): OPEN — SLA tetap sesuai kebijakan umum
- DEC-011 (data sensitif): PROPOSED — coaching notes tidak diuji dalam pilot inti
- DEC-012 (kompensasi): DEFERRED — skor KPI tidak digunakan untuk bonus/payroll
- DEC-015 (kalender produksi): OPEN — tidak digunakan untuk pilot
- DEC-016 (master/source ownership): OPEN — data pilot menggunakan data sintetis/sanitasi

---

## 2. Otorisasi Tim Pilot dan Pengguna

Saya menyetujui dan mengotorisasi tim IT untuk menyediakan akun dan lingkungan bagi:

| Kelompok | Team pilot | Jumlah pengguna | Peran yang diuji |
|---|---|---|---|
| Commercial dan Support | Sales | 8 | 1 TL, 1 Head, 6 Staff |
| Accounting | Account Receivable | 6 | 1 TL, 1 Head, 4 Staff |
| Produksi | Woven | 8 | 1 TL, 1 Head, 6 Staff |

**Total:** 22 pengguna, 10 concurrent user, estimasi 200 entri aktual per hari.

Data yang digunakan hanya **data sintetis atau data pilot yang telah disanitasi**. Tidak ada data pegawai atau customer asli yang disertakan dalam dokumen atau repository ini.

---

## 3. Persetujuan Staging dan Sumber Daya

Saya menyetujui tim IT untuk:

1. Membuat **staging terpisah** dari production sesuai `docs/release/STAGING_ENVIRONMENT.md`.
2. Menyediakan database PostgreSQL, Redis, private object storage, domain/TLS, email test, dan secret manager untuk staging.
3. Menyediakan akun untuk 22 pengguna pilot (kredensial tidak dicatat di repository).
4. Menjalankan deployment, smoke test, dan restore rehearsal staging.
5. Melakukan UAT-01 s.d. UAT-12 dengan tim pilot.

Saya telah menyetujui alokasi sumber daya dan budget untuk lingkungan staging sesuai kebutuhan tim IT.

---

## 4. Persetujuan UAT dan Sign-off

Saya menyetujui 12 skenario UAT yang telah dirancang dalam `docs/product/PILOT_UAT_REPORT.md`:

| UAT | Skema | Status persetujuan bisnis |
|---|---|---|
| UAT-01 | Provision akun, role, dan review akses lintas team | Disetujui |
| UAT-02 | Review definisi KPI, versi template, dan buka periode | Disetujui |
| UAT-03 | Masukkan target dan aktual manual, N/A/missing, submit | Disetujui |
| UAT-04 | Import terkontrol dan rekonsiliasi Accounting | Disetujui |
| UAT-05 | Input harian Produksi dan dimensi tanggal | Disetujui |
| UAT-06 | Unggah bukti dan akses signed sesuai scope | Disetujui |
| UAT-07 | Verifikasi, review, kalibrasi, dan approval final | Disetujui |
| UAT-08 | Lock periode dan koreksi versi baru | Disetujui |
| UAT-09 | Rekonsiliasi skor manual vs sistem | Disetujui |
| UAT-10 | Dashboard, corrective action, dan aksesibilitas | Disetujui |
| UAT-11 | Ekspor Excel/PDF dan metadata | Disetujui |
| UAT-12 | Operasional, monitoring, backup, dan access review | Disetujui |

Saya menyetujui adanya defect Critical/High yang harus ditutup sebelum GO. Defect Medium dapat diterima dengan risk acceptance tertulis dari pemilik terkait.

---

## 5. Persetujuan Risiko Residual

Saya memahami dan menerima risiko berikut untuk pilot:

| Risiko | Penanganan |
|---|---|
| Skor KPI masih TEST-ONLY, belum ada policy resmi | Pilot menggunakan scoring sintetis; skor resmi ditunda setelah DEC-004 final |
| Integrasi runtime belum diaktifkan | Data masuk lewat file import terkontrol saja |
| SSO/MFA belum diterapkan production | Login lokal untuk pilot; SSO ditunda ke production |
| Kalender produksi belum ditetapkan | Pilot Woven menggunakan data harian tanpa dimensi shift |
| Coaching notes tidak diuji pilot | Divalidasi dalam tahap berikutnya |

---

## 6. Sign-off Akhir Pemilik Bisnis

| Pihak | Nama/Role | Keputusan | Tanggal | Referensi |
|---|---|---|---|---|
| Pemilik Bisnis | Pemilik Bisnis / Project Owner | GO untuk melanjutkan staging dan UAT | 27 Oktober 2026 | Dokumen ini |
| Management | Management | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| HR Performance | HR Performance | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Commercial/Support pilot | Head Commercial dan Support | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Accounting pilot | Head Accounting | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| Produksi pilot | Head Produksi | GO | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |
| IT/Security | IT | Siap menyediakan staging | 27 Oktober 2026 | STAGING_ENVIRONMENT.md |
| Auditor/Compliance | Auditor | Audit read-only | 27 Oktober 2026 | MANAGEMENT_HR_DECISIONS_STEP20.md |

---

## 7. Catatan Penting

- **Sign-off ini bukan berarti Step 20 sudah selesai.** Step 20 masih memerlukan eksekusi nyata: provision staging, deployment, UAT, dan bukti teknis dari tim IT.
- **Step 21 (produksi) tetap BLOCKED** sampai tim IT menyediakan bukti staging yang lulus dan semua sign-off tertulis dikumpulkan.
- Saya tidak akan menyertakan kredensial, data pribadi, atau data produksi asli dalam repository ini.
- Semua pertanyaan teknis staging diarahkan kepada tim IT melalui `docs/release/STAGING_ENVIRONMENT.md` dan `docs/release/STAGING_DEPLOYMENT_PLAN.md`.

---

**Tanda tangan (digital):**  
Pemilik Bisnis / Project Owner  
27 Oktober 2026