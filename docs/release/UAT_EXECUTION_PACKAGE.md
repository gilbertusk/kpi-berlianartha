# Paket Eksekusi UAT — Step 20 (Template Siap Digunakan)

**Tanggal:** 27 Oktober 2026  
**Status:** Template siap digunakan saat staging tersedia. Belum ada eksekusi nyata.  
**Penggunaan:** Tim IT provision staging terlebih dahulu, lalu Unit Pilot mengisi setiap kasus UAT.  
**Batasan:** Dokumen ini adalah template. Jangan menandai lulus/gagal sebelum eksekusi nyata di staging.

---

## 1. Identitas Release dan Environment

| Item | Nilai | Sumber |
|---|---|---|
| Git SHA | `c2eb01d33f59c120b4e04594e0b060fd971f7705` | `git rev-parse HEAD` |
| CI Terakhir | Belum tersedia untuk staging | Perlu verifikasi ulang di GitHub Actions |
| Image App Digest | Belum tersedia | `docker build --target production` |
| Image HTTP Digest | Belum tersedia | `docker build --target http` |
| Versi Migrasi | `2026_10_16_001500` | `php artisan migrate --status` |
| Waktu Deploy | Belum tersedia | Catatan waktu deploy nyata |
| Aktor Deploy | Belum tersedia | Nama IT operator |
| Environment Staging | Belum ditentukan | Nama domain dan provider staging |

---

## 2. UAT Execution Report — 12 Kasus

Setiap kasus diisi oleh Unit Pilot setelah staging tersedia. Status: `NOT RUN / PASS / FAIL / BLOCKED`.

| ID | Role / Cakupan | Skenario | Prasyarat | Status | Expected | Actual | Bukti | Defect ID |
|---|---|---|---|---|---|---|---|---|
| UAT-01 | System Admin, HR | Provision akun, role, home team, tanggal efektif; review akses lintas team | DEC-002/007/013/016 | NOT RUN | Akses sesuai scope | | | |
| UAT-02 | HR, Management, unit owner | Review definisi KPI, versi template, bobot 100%, buka periode | DEC-003/005/006/007 | NOT RUN | Versi approved, snapshot | | | |
| UAT-03 | Employee, TL, HR | Masukkan target dan aktual manual, N/A/missing, submit | DEC-002/004/006/007 | NOT RUN | Expected vs actual | | | |
| UAT-04 | Data Owner Accounting, HR | Import terkontrol, preview error, commit/retry idempoten | DEC-005/006/007/016 | NOT RUN | Control totals cocok | | | |
| UAT-05 | Data Owner Produksi, TL | Input harian, dimensi tanggal/shift | DEC-005/006/007 | NOT RUN | Rekonsiliasi per tanggal | | | |
| UAT-06 | Employee, TL, Auditor | Unggah bukti, scan bersih, akses signed | Scanner aktif, DEC-006 | NOT RUN | Akses/deny sesuai scope | | | |
| UAT-07 | TL, Head, HR, Management | Verifikasi, review, kalibrasi, approval final | DEC-002/004/009 | NOT RUN | State transition, audit | | | |
| UAT-08 | HR, Management, Auditor | Lock periode, koreksi versi baru | DEC-002/004/006 | NOT RUN | Before/after versi | | | |
| UAT-09 | HR, Management, data owner | Rekonsiliasi skor manual vs sistem | Golden examples signed | NOT RUN | Worksheet signed | | | |
| UAT-10 | TL, Employee, Management, Auditor | Dashboard, corrective action, aksesibilitas | DEC-004/007/010/011 | NOT RUN | Screenshot, NVDA/VoiceOver | | | |
| UAT-11 | HR, Management, Auditor | Ekspor Excel/PDF, metadata periode/versi | DEC-004/006/007/010 | NOT RUN | Control totals, checksum | | | |
| UAT-12 | IT/Security, support owner, HR | Operasional: monitoring, backup, restore, access review | DEC-007/010/013/014 | NOT RUN | Readiness, alert, restore report | | | |

---

## 3. Defect Register

| Defect ID | Kasus Terkait | Temuan | Severity | Reproduksi | Owner | Fix Version | Retest | Status |
|---|---|---|---|---|---|---|---|---|
| | | | | | | | | |
| | | | | | | | | |

**Aturan:** Critical wajib ditutup. High wajib ditutup atau keputusan NO-GO formal. Medium butuh workaround aman, owner, target, dan risk acceptance.

---

## 4. Sign-off Akhir

| Pihak | Nama/Role | Scope yang Disetujui | Keputusan GO/NO-GO | Tanggal | Tanda Tangan / Referensi |
|---|---|---|---|---|---|
| Management | | Semua domain pilot | | | |
| HR Performance | | Semua domain pilot | | | |
| Commercial/Support pilot | | Sales | | | |
| Accounting pilot | | Account Receivable | | | |
| Produksi pilot | | Woven | | | |
| IT/Security | | Staging dan infrastruktur | | | |
| Auditor/Compliance | | Audit read-only | | | |

---

## 5. Keputusan GO/NO-GO

| Item | Status |
|---|---|
| Keputusan | GO / NO-GO / BLOCKED |
| Pemberi Keputusan | Management dan HR Performance |
| Tanggal | |
| Referensi | UAT execution report, defect register, sign-off di atas |
| Catatan | Jika NO-GO/BLOCKED, catat owner dan tindakan perbaikan |

---