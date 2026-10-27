# Restore Rehearsal Report — Staging — Template

**Tanggal:** Belum tersedia  
**Tim IT:** Belum ditentukan  
**Snapshot Sumber:** Belum tersedia  
**Target Restore:** Belum tersedia  
**Enkripsi Kunci:** Belum tersedia (simpan di secret manager)  

| # | Langkah Verifikasi | Hasil | Bukti |
|---|-------------------|-------|-------|
| 1 | `sha256sum -c SHA256SUMS` cocok | | |
| 2 | Dekripsi + MDC lulus | | |
| 3 | `pg_restore` ke PostgreSQL terisolasi | | |
| 4 | Skema: jumlah dan migrasi terakhir sama | | |
| 5 | Jumlah baris setiap tabel sama | | |
| 6 | MD5 tabel kunci sama | | |
| 7 | File bukti cocok dengan MANIFEST | | |
| 8 | Login akun uji berhasil | | |
| 9 | RTO terukur (detik) | | |
| 10 | RPO terukur (detik) | | |

**Hasil:** Lulus / Gagal  
**Tanda Tangan Tim IT:**  
**Tanggal:**  

---