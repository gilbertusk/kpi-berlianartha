# Paket Prompt Pengembangan Sistem KPI PT Berlian Artha Label

Folder ini berisi prompt berurutan untuk membangun sistem KPI sampai siap dipakai dan diserahterimakan. Jalankan **satu prompt pada satu waktu** sesuai nomor. Jangan menjalankan beberapa step secara paralel karena setiap step memakai artefak dan keputusan dari step sebelumnya.

## Stack yang dikunci

- Laravel 13 dan PHP 8.4.
- Blade, Livewire, Alpine.js, dan Tailwind CSS.
- PostgreSQL 18.
- Redis dan Laravel Horizon.
- S3-compatible object storage untuk evidence dan hasil export.
- Pest untuk backend/feature test dan Playwright untuk browser test.
- Docker, Docker Compose, Nginx, dan PHP-FPM.
- GitHub Actions untuk CI/CD, kecuali repository resmi perusahaan memakai GitLab.
- Modular monolith: satu repository dan satu aplikasi; web, queue worker, serta scheduler berjalan sebagai process/container terpisah dari image yang sama.

## Dokumen sumber utama

- `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx`
- `scripts/build_prd.py` sebagai sumber teks PRD yang dapat dicari apabila agent tidak dapat membaca DOCX.

PRD masih berstatus draft validasi bisnis. Karena itu, Step 00 wajib dijalankan sebelum coding. Keputusan bisnis yang belum disetujui tidak boleh diubah menjadi asumsi tersembunyi dalam kode.

## Cara menggunakan

1. Buka repository pada root project.
2. Berikan file prompt step yang aktif kepada AI coding agent.
3. Pastikan agent membaca PRD, `PROJECT_PROGRESS.md`, decision register, ADR, dan hasil step sebelumnya.
4. Ikuti urutan release track di bawah; nomor file menunjukkan kelompok deliverable, bukan izin untuk melewati pilot.
5. Izinkan agent menyelesaikan hanya ruang lingkup step aktif.
6. Periksa bukti test dan acceptance criteria.
7. Lanjutkan ke step berikutnya hanya ketika exit criteria step aktif dinyatakan PASS atau ada blocker eksternal yang terdokumentasi.

Status `BLOCKED` tidak sama dengan `PASS`. Integrasi, UAT, restore backup, dan production deployment hanya boleh dinyatakan berhasil berdasarkan bukti runtime yang nyata.

## Urutan prompt

| Step | File | Hasil utama |
|---:|---|---|
| 00 | `00-validasi-keputusan-bisnis.md` | Decision register dan sign-off gate |
| 01 | `01-arsitektur-dan-rencana-implementasi.md` | ADR, diagram, backlog, risk register |
| 02 | `02-bootstrap-aplikasi-laravel.md` | Laravel skeleton dan smoke test |
| 03 | `03-infrastruktur-lokal-dan-ci.md` | Docker Compose, quality gate, CI |
| 04 | `04-fondasi-data-audit-dan-transaksi.md` | Database conventions, audit, outbox |
| 05 | `05-autentikasi-organisasi-dan-otorisasi.md` | Login, 21 team, RBAC dan scope |
| 06 | `06-kamus-kpi-dan-template-berversi.md` | KPI dictionary dan template versioning |
| 07 | `07-periode-penugasan-dan-target.md` | Period lifecycle dan target |
| 08 | `08-aktual-evidence-dan-import.md` | Actual, evidence, serta staged import |
| 09 | `09-scoring-engine-dan-scorecard.md` | Mesin skor deterministik |
| 10 | `10-approval-kalibrasi-lock-dan-koreksi.md` | Workflow penilaian lengkap |
| 11 | `11-dashboard-notifikasi-dan-corrective-action.md` | Dashboard per peran dan eskalasi |
| 12 | `12-laporan-dan-ekspor.md` | Excel/PDF dan audit report |
| 13 | `13-modul-commercial-dan-support.md` | Fitur khusus Sales, Design, Costing, support |
| 14 | `14-modul-accounting-ar-dan-tax.md` | AR aging, Accounting, Tax Invoice |
| 15 | `15-modul-produksi-ppic-dan-warehouse.md` | PPIC board dan dimensi produksi |
| 16 | `16-modul-qc-ncr-dan-capa.md` | Quality, NCR, CAPA, defect |
| 17 | `17-integrasi-dan-migrasi-data.md` | Adapter integrasi dan migration rehearsal |
| 18 | `18-keamanan-observability-backup-dan-dr.md` | Security hardening dan operational readiness |
| 19 | `19-pengujian-menyeluruh-dan-kualitas.md` | Regression, E2E, load, accessibility |
| 20 | `20-staging-pilot-dan-uat.md` | Release candidate dan sign-off pilot |
| 21 | `21-production-rollout-hypercare-dan-handover.md` | Go-live, rollout 21 team, handover |

## Urutan release track sesuai PRD

Nomor file tidak dijalankan lurus setelah Step 12. Urutan implementasi wajib mengikuti rencana bertahap PRD:

1. **Governance:** Step 00–01.
2. **Fondasi produk inti:** Step 02–12.
3. **Core readiness:** jalankan bagian core pada Step 18–19.
4. **Pilot lintas fungsi:** Step 20 untuk minimal satu team Commercial/Support, satu Accounting, dan satu Produksi.
5. **Rollout fondasi inti:** perluas core yang sudah lolos pilot ke 21 team dengan wave plan terdokumentasi.
6. **Fitur khusus dan integrasi:** Step 13–17 hanya setelah pilot fondasi dinyatakan PASS.
7. **Final hardening dan regression:** ulangi Step 18–20 terhadap scope tambahan.
8. **Production completion, hypercare, dan handover:** Step 21.

Fitur PPIC board, AR aging, NCR/CAPA, analisis produksi detail, dan connector otomatis tidak boleh menahan pilot fondasi inti jika belum masuk scope pilot yang disetujui.

## Aturan kemajuan

Setiap step harus memperbarui `PROJECT_PROGRESS.md` secara append-only dengan:

- tanggal dan nomor step;
- scope yang selesai;
- file atau migration yang berubah;
- perintah validasi serta hasilnya;
- keputusan dan asumsi;
- blocker;
- status `PASS`, `PARTIAL`, atau `BLOCKED`;
- next step yang diperbolehkan.

Jangan menghapus riwayat step sebelumnya. Jangan menganggap build hijau sebagai bukti fitur bisnis telah diterima.

## Commit dan push setiap step

Setiap step yang menghasilkan perubahan wajib mempunyai commit tersendiri dengan format `step-XX: <ringkasan>`, kemudian di-push ke `origin/main`. Sebelum commit, periksa staged diff dan pastikan tidak ada `.env`, credential, data sensitif, log, atau artifact sementara. Setelah push, verifikasi SHA lokal sama dengan `origin/main`.

Jika step berstatus `PARTIAL` atau `BLOCKED`, tetap commit dan push dokumentasi/progress yang relevan dengan status yang jujur. Jangan melakukan force-push atau menyatakan push berhasil ketika authentication, network, branch protection, conflict, atau CI masih gagal. Aturan lengkap berada di `AGENTS.md`.
