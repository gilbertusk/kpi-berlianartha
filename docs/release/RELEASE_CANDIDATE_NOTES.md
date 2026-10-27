# Catatan Kandidat Pilot dan Rencana Pemulihan

**Draft, 27 Oktober 2026.** Belum ada release candidate staging yang dipilih atau dipromosikan. Snapshot kode saat persiapan: `f68769bed8392944b64994c585b59c99ec0ab1b9`. Digest image, tag release, versi konfigurasi, versi migrasi staging, jadwal deploy, dan owner **belum ada**. Verifikasi ulang seluruhnya pada commit final; jangan menyebut snapshot ini sebagai versi yang telah di-deploy.

## Cakupan yang tersedia untuk dievaluasi

Fondasi identitas/organisasi, governance KPI berversi, periode/target, aktual/evidence, scoring engine, approval/lock/koreksi, dashboard, laporan/ekspor, audit, dan operasi dasar memiliki bukti teknis dari Step 05–19. UAT harus memakai konfigurasi dan data bisnis yang disetujui; test otomatis Step 19 memakai policy skor **TEST-ONLY**, bukan policy resmi. Modul khusus Commercial/Support, Accounting, Produksi/QC dan koneksi integrasi hanya masuk bila dipilih eksplisit beserta owner, definisi, dan data sumber.

## Known issues / penahan rilis

| ID | Dampak | Penyelesaian yang dibutuhkan |
|---|---|---|
| KI-01 | Tidak ada skor resmi: DEC-004 terbuka, `config/scoring.php` kosong | Policy dan golden examples bisnis bertanda tangan |
| KI-02 | Uji p95 pada data sintetis bukan bukti beban pilot | DEC-007 dan ukur ulang di staging |
| KI-03 | Browser resmi dan screen reader manual belum disahkan/diuji | DEC-010 dan UAT manual |
| KI-04 | Upload evidence UI tidak dicakup E2E browser in-process; scanner malware belum tersedia | Uji UI + scanner nyata di staging sebelum evidence diverifikasi |
| KI-05 | Perhitungan ulang paralel dua proses belum diuji | Nilai risiko berdasarkan jumlah worker pilot; uji jika perlu |
| OPS-01 | Restore terbukti hanya pada lokal dengan data sintetis kecil | Restore staging dengan volume representatif dan target DEC-010 |
| OPS-02 | Provider, SSO/MFA, alert route, secret manager dan support owner belum disetujui | DEC-013/014 dan bukti runtime staging |

D-06/N-01 (`vite-plus`) ditutup pada 26 Oktober; CI terakhir yang dicatat untuk `002184e` lulus setelah retry timeout jaringan font Bunny. Build aset saat ini membutuhkan akses jaringan ke `fonts.bunny.net`; periksa ketersediaan atau sediakan mitigasi yang ditinjau sebelum membangun artifact final. Tidak ada acceptance risiko otomatis atas item di tabel.

## Rencana rollback staging — perlu review operator sebelum eksekusi

1. Sebelum deploy, catat digest image lama/baru, SHA, versi migrasi dan konfigurasi, queue payload compatibility, data control totals, recovery point terenkripsi, serta pemilik keputusan dan ambang stop/rollback. Bila belum ada versi sebelumnya, tidak ada image untuk di-roll back; rencanakan penghentian pilot dan recovery dari snapshot yang teruji, bukan asumsi rollback kode.
2. Pada kegagalan smoke, rekonsiliasi, keamanan, atau defect Critical/High: hentikan akses pilot/penulisan baru secara terkontrol, hentikan worker/scheduler yang dapat memproses payload tidak kompatibel, pertahankan log/audit dan bukti; Incident Commander serta data owner memutuskan langkah berdasarkan dampak. Jangan menghapus data atau menjalankan reset database.
3. Bila schema tetap kompatibel, kembalikan **image immutable sebelumnya** untuk web/worker/scheduler dan konfigurasi yang kompatibel; restart worker aman; verifikasi readiness, queue, scheduler, audit, akses, dan kontrol total. nginx/http harus sesuai aset image aplikasi yang dipilih.
4. Jangan menganggap `migrate:rollback` aman. Untuk perubahan schema/data, gunakan forward-fix ter-review atau restore hanya berdasarkan keputusan pemilik data setelah rehearsal terisolasi, dengan `docs/operations/MIGRATION_POLICY.md` dan `docs/operations/DR_RUNBOOK.md`. Jika restore dipilih, dokumentasikan gap antara snapshot dan waktu insiden, rekonsiliasi/replay lewat workflow teraudit, serta konsekuensi object storage dan outbox.
5. Catat waktu mulai/selesai, penyebab, versi akhir, hasil verifikasi, data yang memerlukan rekonsiliasi, tiket/owner, dan keputusan lanjut/NO-GO dalam `PILOT_UAT_REPORT.md`. Re-entry UAT memerlukan retest kasus terdampak dan persetujuan ulang.

**Saat ini rencana ini belum direhearsal di staging.** Rilis ke production tetap NO-GO.
