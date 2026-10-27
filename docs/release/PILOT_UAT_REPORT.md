# Pilot dan UAT — Step 20

**Tanggal persiapan:** 27 Oktober 2026
**Status:** BLOCKED sebelum deployment staging dan pelaksanaan UAT. **Keputusan:** NO-GO untuk promosi ke production. Dokumen ini adalah rencana dan register bukti, bukan laporan UAT yang sudah terjadi.

## 1. Identitas release dan hasil aktual

| Item | Hasil saat persiapan | Bukti yang diperlukan untuk mengubah status |
|---|---|---|
| Kandidat kode | `f68769bed8392944b64994c585b59c99ec0ab1b9` (`main`, saat preflight Step 20) | SHA final yang dipilih serta CI untuk SHA tersebut |
| CI terakhir yang tercatat | Run `40271393130` untuk `002184e`: enam job lulus; rerun job frontend karena timeout font eksternal | Verifikasi ulang CI dan provenance pada release candidate final |
| Staging: domain, provider, isolation, konfigurasi, secrets | Belum ditentukan (DEC-014); **tidak dideploy** | Nama environment, pemilik, konfigurasi tanpa nilai secret, bukti pemisahan dari production |
| Image immutable dan digest; versi migrasi; waktu deploy | Belum tersedia | Digest app/http dari artifact CI, versi migrasi dan catatan deploy nyata |
| Pilot team / pengguna / data owner | Belum disetujui (DEC-007) | Persetujuan Management/HR/IT untuk minimal satu team Commercial/Support, satu Accounting, satu Produksi |
| UAT cases / pass / fail / reconciliation | 12 kasus direncanakan; **0 dijalankan, 0 lulus, 0 gagal**; belum direkonsiliasi | Record eksekusi per kasus, aktor, waktu, versi, hasil, lampiran bukti |
| Defect Step 20 | Belum ada UAT; **bukan** berarti tidak ada defect | Defect register dari UAT dengan severity, reproduksi, owner, versi perbaikan, retest |
| Sign-off | 0 diterima; seluruh domain pada `docs/product/UAT_SIGNOFF_MATRIX.md` masih OPEN | Persetujuan tertulis pihak wajib dengan scope dan referensi |
| Kesiapan staging/UAT terverifikasi | **0 dari 5 kelompok gate Step 20 terbukti** (prasyarat, deploy, UAT, operasional, sign-off); ini bukan persentase penyelesaian proyek | Bukti terpisah untuk setiap kelompok di checklist |

## 2. Prasyarat dan batas scope

- **Core readiness teknis** Step 19 terdokumentasi di `docs/qa/RELEASE_TEST_REPORT.md`: suite PostgreSQL/Redis, browser Chromium/Firefox/WebKit, aksesibilitas otomatis, dan uji performa sintetis. Step 19 tetap PARTIAL: golden example bisnis DEC-004 dan beban pilot DEC-007 belum ada. Step 18 juga PARTIAL: bukti backup/restore hanya lokal pada data sintetis, bukan staging.
- **Governance bisnis:** DEC-002 (approver), DEC-003 (bobot), DEC-004 (rating/scoring), DEC-005 (KPI pilot), DEC-006 (definisi/target/sumber), DEC-007 (team, user, volume), DEC-010 (browser/RPO/RTO/retention), DEC-013 (auth/MFA), DEC-014 (staging dan operasional) masih tanpa sign-off. DEC-009 diperlukan bila pengingat/eskalasi masuk scope; DEC-015 bila aktual produksi per shift masuk scope; DEC-008/016 bila koneksi atau master eksternal masuk scope.
- **Keamanan evidence:** scanner malware belum terintegrasi; evidence tetap karantina, sehingga verifikasi KPI yang mewajibkan evidence tidak dapat diklaim lulus di staging. Pilih dan buktikan scanner sebelum UAT evidence, tanpa memakai mode `local-unscanned` sebagai pengganti.
- Fitur khusus Step 13–17 dan koneksi eksternal **tidak otomatis** masuk pilot core; pilih secara eksplisit dengan owner dan sumber data. Jangan gunakan kandidat KPI PRD yang masih DRAFT atau policy TEST-ONLY sebagai konfigurasi resmi.
- Tidak ada data pegawai/customer atau credential pilot disimpan di dokumen/repo. Gunakan data sintetis atau data pilot yang disanitasi/disetujui, dengan daftar akun dan bukti di penyimpanan terbatas milik owner.

## 3. Rencana UAT per role dan lintas unit

Untuk setiap kasus: tentukan team dan aktor yang disetujui, tanggal efektif, data uji/expected result manual yang disahkan, environment + SHA/digest, status `NOT RUN/PASS/FAIL/BLOCKED`, screenshot/log yang sudah disanitasi, defect ID dan retest. Uji penolakan akses selain jalur sukses. Semua kasus di bawah **NOT RUN/BLOCKED** sampai prasyarat masing-masing dipenuhi.

| ID | Role / cakupan | Skenario dan pemeriksaan hasil | Prasyarat utama | Bukti yang harus disimpan |
|---|---|---|---|---|
| UAT-01 | System Admin, HR; tiga kelompok pilot | Provision akun, role, home team, tanggal efektif; review akses lintas team dan larangan admin ubah skor | DEC-002/007/013/016 | Daftar akses yang disetujui, hasil allow/deny, audit |
| UAT-02 | HR, Management, unit owner | Review definisi KPI, versi template, bobot tepat 100%, effective date; buka periode dan snapshot penugasan | DEC-003/005/006/007 | Versi approved, sign-off definisi, snapshot |
| UAT-03 | Employee, TL, HR; perwakilan Commercial/Support | Masukkan target dan aktual manual, N/A/missing, submit, rejection/resubmit, scope sendiri vs team lain | DEC-002/004/006/007 | Expected vs actual, rejection trail, audit |
| UAT-04 | Data owner Accounting, HR | Import terkontrol, preview error, commit/retry idempoten, bandingkan jumlah/total dan record ditolak dengan sumber | DEC-005/006/007/016; DEC-008 bila integrasi | Control totals sumber vs staging, file uji aman, error rows |
| UAT-05 | Data owner Produksi, TL | Input harian atau import yang disetujui; cek periode, work order, dimensi tanggal/shift bila dipilih | DEC-005/006/007; DEC-015 bila shift | Rekonsiliasi per tanggal/WO; aturan kalender signed |
| UAT-06 | Employee, TL, Auditor | Unggah bukti via UI, scan bersih, akses signed sesuai scope, tautan kedaluwarsa, penolakan lintas team | Scanner aktif, evidence policy DEC-006, staging storage DEC-014 | Hasil scanner, akses/deny, audit unduhan |
| UAT-07 | TL, Head/Manager, HR, Management | Verifikasi, review/bypass berizin, kalibrasi, approval final; self-approval ditolak; deadline bila disetujui | DEC-002/004/009 (bila SLA diuji) | State transition, aktor, alasan, audit |
| UAT-08 | HR, Management, Auditor | Lock setelah precheck; edit final ditolak; permintaan dan approval koreksi menghasilkan versi baru | DEC-002/004/006 | Before/after versi, reason, audit, penolakan edit |
| UAT-09 | HR, Management, process/data owner | Rekonsiliasi manual target → aktual → formula → skor → bobot → kontribusi, termasuk zero/N/A/missing dan rounding | Golden examples signed DEC-004 dan definisi approved | Worksheet manual yang ditandatangani, selisih dan disposisi |
| UAT-10 | TL, Employee, Management, Auditor | Dashboard/drill-down, corrective action, KPI merah bila ambang disetujui, Auditor read-only; aksesibilitas keyboard dan screen reader manual | DEC-004/007/010/011 sesuai konten | Screenshot aman, hasil NVDA/VoiceOver, defect |
| UAT-11 | HR, Management, Auditor | Ekspor Excel/PDF, filter/scope, metadata periode/versi, cocokkan baris dan total dengan UI/source | DEC-004/006/007/010 | Control totals dan checksum artefak yang disimpan aman |
| UAT-12 | IT/Security, support owner, HR | Operasional: monitoring/alert, queue/scheduler, mail safe recipient, backup+restore staging, rollback rehearsal, access review | DEC-007/010/013/014; owner support | Readiness, alert route, restore report, RPO/RTO terukur |

Training singkat dan user guide dibutuhkan untuk Employee (entri/privasi), TL (verifikasi/review), Manager (review), HR (governance/kalibrasi), Management (approval), Auditor (baca/audit), dan System Admin (akun/scope tanpa mengubah nilai). **Training belum dijalankan; pemilik dan jadwal belum ditetapkan.** Catat daftar hadir, versi materi, latihan per role, dan kanal bantuan; jangan masukkan nama/akun nyata di repo.

## 4. Log eksekusi, defect, dan keputusan

Saat ini belum ada eksekusi staging/pilot untuk dicatat. Saat prasyarat terpenuhi, buat record per kasus: ID, team/role, versi image dan konfigurasi, waktu, expected/actual, hasil, tautan bukti terbatas, defect ID, owner, fix version, retest, dan keputusan owner. Register defect memakai severity `docs/product/UAT_SIGNOFF_MATRIX.md`: Critical wajib tutup; High wajib tutup atau keputusan NO-GO formal; Medium butuh workaround aman, owner, target dan risk acceptance. Tidak ada penerimaan implisit.

Exit gate **belum lulus**: seluruh journey wajib dan rekonsiliasi, review hak akses owner, restore staging, runbook/support owner, serta sign-off tertulis Management, HR Performance, perwakilan masing-masing tiga kelompok pilot, IT/Security, dan data owner. Lihat `GO_LIVE_CHECKLIST.md`. **Production tetap BLOCKED.**

## Addendum 27 Oktober 2026 — seed staging yang belum dijalankan

Pemilik memilih role System Admin untuk akun pribadi dan meminta akun lain sebagai data seed staging. `StagingPilotSeeder` disiapkan sebagai **seed demonstrasi manual**, hanya untuk environment staging, dengan email admin dari secret/config operator; detail ada di `STAGING_SEED.md`. Akun sintetis Sales/AR/Woven tidak menyetujui atau menetapkan team pilot resmi. Seeder tidak dijalankan pada staging; seluruh hasil UAT, akses nyata, dan sign-off pada laporan ini tetap **NOT RUN/BLOCKED**.
