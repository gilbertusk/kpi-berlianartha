# Disaster Recovery Runbook

**Status:** Step 18 — 16 Oktober 2026. Prosedur diuji pada lingkungan lokal lewat restore rehearsal (`BACKUP_RESTORE.md`). Topologi produksi, lokasi offsite, dan target RPO/RTO belum diputuskan (DEC-010, DEC-014); langkah yang bergantung pada penyedia ditandai **[DEC-014]**.

## 1. Target (usulan, belum disetujui)

| Ukuran | Usulan | Dasar | Terukur (rehearsal lokal) |
|---|---|---|---|
| RPO | ≤ 24 jam | backup harian | 15 detik untuk kegagalan 15 detik setelah snapshot; jendela terburuk = interval backup |
| RTO | ≤ 4 jam | restore + verifikasi + deploy ulang manual | 14 detik restore dan verifikasi (database 0,4 MB, 66 tabel, 3 objek) |

Angka terukur berasal dari data sintetis kecil dan **tidak mewakili produksi**; ulangi di staging dengan volume pilot (DEC-007) sebelum target disetujui (DEC-010). Bila bisnis butuh RPO < 24 jam, diperlukan WAL archiving/PITR atau layanan database terkelola **[DEC-014]**.

## 2. Skenario dan keputusan awal

| Skenario | Tanda | Tindakan |
|---|---|---|
| Container/proses aplikasi mati | A-01, A-03, A-04 | Restart service; tidak perlu restore |
| Redis hilang | A-02 (cache/queue) | Jalankan Redis baru; outbox mengirim ulang pesan tertunda; ekspor yang hilang diulang pemiliknya |
| Database rusak, terhapus, atau host database hilang | A-02 (database) | §3 restore database + objek |
| Object storage hilang/rusak | A-02 (storage), `ops:storage-verify` gagal | §3 langkah objek saja, lalu verifikasi metadata |
| Seluruh lingkungan hilang (region/host) | semua alert | §3 di lingkungan baru **[DEC-014]** |
| Data dirusak oleh rilis/operasi salah | laporan pengguna, audit | Jangan menimpa produksi: restore ke lingkungan terpisah, bandingkan, koreksi lewat alur koreksi teraudit; restore penuh hanya atas keputusan Incident Commander + pemilik bisnis |
| Kunci/secret bocor | Sec | `INCIDENT_RESPONSE.md`; rotasi (`BACKUP_RESTORE.md` §6) |

## 3. Prosedur restore produksi

1. **Deklarasi** insiden SEV-1/SEV-2 (`INCIDENT_RESPONSE.md`), tunjuk Incident Commander; hentikan penulisan: aktifkan maintenance (`php artisan down`), hentikan worker dan scheduler.
2. **Pilih backup**: backup `COMPLETE` terbaru sebelum kerusakan (lihat `backup.json`; jangan pakai direktori dengan `INCOMPLETE`). Catat waktu snapshot → kehilangan data = waktu kejadian − snapshot.
3. **Siapkan target** **[DEC-014]**: PostgreSQL 18 kosong, bucket object storage privat kosong, secret dari secret store (`BACKUP_RESTORE.md` §7), image aplikasi dengan revisi git yang tercatat di `backup.json`.
4. **Uji dulu di lingkungan terisolasi**: `scripts/dr-rehearsal.sh <backup>` harus lulus (checksum, skema, jumlah baris, checksum tabel, file bukti, login).
5. **Restore database**: dekripsi `db.dump.gpg` dengan kunci backup (`gpg_decrypt` di `scripts/dr/lib.sh`; gagal bila file diubah), `pg_restore --no-owner --no-privileges` ke database target dengan user aplikasi.
6. **Restore objek**: dekripsi `objects.tar.gpg`, ekstrak, unggah isi `objects/` ke bucket dengan path yang sama; jalankan `php artisan ops:storage-verify <snapshot-dir>` terhadap database target — 0 rusak, 0 hilang, 0 checksum berbeda.
7. **Verifikasi aplikasi**: `php artisan ops:readiness --strict`, `php artisan ops:security-check --strict`, login akun uji, buka satu scorecard dan satu bukti, cek jumlah baris tabel kunci terhadap `table-counts.txt`.
8. **Pulihkan layanan**: jalankan worker dan scheduler, `php artisan up`, pantau alert 1 jam.
9. **Rekonsiliasi data hilang** (antara snapshot dan kejadian): minta unit memasukkan ulang aktual/keputusan sejak snapshot; integrasi file dikirim ulang dari sumber (idempotent, file yang sudah ada dicatat duplikat); catat di laporan insiden.
10. **Ukur** RTO aktual (deklarasi → layanan pulih) dan RPO aktual; tulis PIR.

## 4. Kontak dan eskalasi

Mengikuti `INCIDENT_RESPONSE.md` §3. Nama on-call, penyedia, dan nomor darurat diisi saat DEC-014.

## 5. Latihan

- Restore rehearsal: usulan per kuartal, setelah perubahan skema besar, dan sebelum go-live (Step 20/21), dengan hasil dicatat di `BACKUP_RESTORE.md` §5.
- Latihan DR penuh (lingkungan baru dari nol) sebelum produksi, setelah DEC-014.
