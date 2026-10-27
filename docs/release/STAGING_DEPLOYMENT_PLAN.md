# Staging Deployment Plan — Step 20

**Status:** Rencana oleh tim IT, belum dieksekusi di staging nyata. Diperlukan persetujuan pemilik bisnis dan IT sebelum eksekusi.

## 1. Tujuan

Deploy release candidate ke staging sebagai langkah sebelum UAT pilot. Staging mirip production tetapi terisolasi, menggunakan data sintetis atau data pilot yang disanitasi.

## 2. Release candidate

| Item | Nilai |
|---|---|
| Git SHA | `ecaa14d90125f54b1697e53653ee2346afbc6f5d` |
| CI terakhir | Run `40271393130` untuk `002184e` (enam job lulus) |
| Image app | Belum dibangun untuk staging |
| Image http | Belum dibangun untuk staging |
| Versi migrasi | Terakhir `2026_10_16_001500` |

## 3. Urutan eksekusi

| # | Langkah | Pemilik | Bukti |
|---|---|---|---|
| 1 | Verifikasi SHA dan CI untuk commit staging | Release Manager | CI log, SHA lokal = origin/main |
| 2 | Build image app dan http dari SHA staging | IT/Operator | Docker image digest |
| 3 | Vulnerability scan image staging | Security | Hasil Trivy/scan |
| 4 | Backup database staging sebelum migrasi | IT/Operator | File backup terenkripsi |
| 5 | Deploy image immutable ke staging | IT/Operator | Waktu deploy, digest |
| 6 | Jalankan migrasi sekali | IT/Operator | `migrate --status`, durasi |
| 7 | Restart web, worker, scheduler | IT/Operator | Container healthy |
| 8 | Smoke test staging | IT/QA | Health, readiness, queue, scheduler, object private |
| 9 | Restore rehearsal staging | IT/QA | Laporan restore, RPO/RTO terukur |
| 10 | Akses owner review | Pemilik akses | Daftar allow/deny per role |
| 11 | Training pilot | Owner training | Daftar hadir, versi materi |
| 12 | UAT eksekusi | Unit pilot | Record UAT-01..12 |
| 13 | Rekonsiliasi skor/report | Data owner | Worksheet manual yang ditandatangani |
| 14 | Review defect dan akses | Semua pemilik | Register defect, access review |
| 15 | Sign-off tertulis | Semua pihak wajib | Referensi dan tanggal tertulis |

## 4. Gate keluar staging

- Tidak ada defect Critical/High terbuka
- Seluruh journey wajib UAT lulus dan direkonsiliasi
- Hak akses ditinjau oleh owner
- Backup/restore staging terbukti
- Runbook dan support owner siap
- Sign-off tertulis lengkap

## 5. Rollback plan

Sebelum deploy, catat:
- Digest image lama dan baru
- SHA dan versi konfigurasi
- Queue payload compatibility
- Data control totals
- Recovery point terenkripsi
- Pemilik keputusan dan ambang stop/rollback

Pada kegagalan smoke, rekonsiliasi, keamanan, atau defect Critical/High:
1. Hentikan akses pilot/penulisan baru secara terkontrol.
2. Hentikan worker/scheduler yang memproses payload tidak kompatibel.
3. Pertahankan log/audit dan bukti.
4. Incident Commander dan pemilik data memutuskan langkah.
5. Jangan menghapus data atau menjalankan reset database.

## 6. Bukti yang diharapkan setelah eksekusi

| Bukti | Lokasi | Status |
|---|---|---|
| Image digest app/http | Docker registry/CI | Belum tersedia |
| Versi migrasi staging | `php artisan migrate --status` | Belum dijalankan |
| Hasil smoke staging | Log/rekaman uji | Belum tersedia |
| Laporan restore staging | `docs/release/` | Belum tersedia |
| Record UAT-01..12 | `docs/release/PILOT_UAT_REPORT.md` | 0 dijalankan |
| Register defect | `docs/release/` | Belum ada |
| Sign-off tertulis | `docs/product/UAT_SIGNOFF_MATRIX.md` | 0 diterima |

## 7. Catatan tim IT

Tim IT bertanggung jawab atas infrastruktur, deployment, smoke test, dan backup/restore staging. Tim IT **tidak** bertanggung jawab atas:
- Persetujuan keputusan bisnis (DEC-002 s.d. DEC-016)
- Penentuan team pilot dan pengguna
- Persetujuan KPI, bobot, formula, dan skor
- Sign-off bisnis untuk UAT

Semua keputusan tersebut harus disetujui oleh Management, HR Performance, dan pemilik data sebelum UAT dapat dilaksanakan.