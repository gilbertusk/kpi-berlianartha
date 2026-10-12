# Repository Instructions

## Source of truth

Sebelum mengerjakan perubahan, baca:

1. `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx` atau `scripts/build_prd.py` bila DOCX tidak dapat dibaca.
2. Prompt step aktif pada `prompts/ai-development/`.
3. `PROJECT_PROGRESS.md`, decision register, ADR, dan hasil step sebelumnya jika sudah tersedia.

Jangan mengarang bobot, formula, target, rating, approver, SLA, sumber data, atau aturan kompensasi. Keputusan yang belum disetujui harus tetap berstatus terbuka atau blocked.

## Git and push policy

Setiap step dikerjakan secara berurutan dan harus memiliki commit terpisah.

1. Sebelum mulai, sinkronkan branch aktif dengan `origin/main` menggunakan fast-forward only dan pastikan perubahan milik pengguna tidak tertimpa.
2. Kerjakan hanya scope prompt step aktif.
3. Jalankan seluruh validasi yang diwajibkan oleh prompt.
4. Perbarui `PROJECT_PROGRESS.md` secara append-only dengan hasil nyata.
5. Periksa staged diff untuk memastikan tidak ada secret, credential, data personal, file environment, log, atau artifact sementara.
6. Gunakan commit message `step-XX: <ringkasan>` untuk step implementasi. Gunakan `docs:` atau `chore:` hanya untuk perubahan yang bukan step implementasi.
7. Push commit ke `origin/main` setelah commit berhasil dibuat.
8. Verifikasi commit lokal sama dengan `origin/main` sebelum menyatakan push berhasil.

Jika step berstatus `PARTIAL` atau `BLOCKED` tetapi menghasilkan perubahan atau dokumentasi penting, commit dan push perubahan tersebut dengan status yang jujur di `PROJECT_PROGRESS.md`. Jangan menandai step `PASS` tanpa bukti test/runtime yang diwajibkan.

Jika push gagal karena authentication, network, branch protection, conflict, atau CI, jangan mengarang keberhasilan. Pertahankan commit lokal, laporkan commit SHA dan blocker, lalu lanjutkan hanya setelah blocker diselesaikan.

## Safety

- Jangan commit `.env`, token, password, private key, credential cloud, dump database production, atau data pegawai/customer yang sensitif.
- Jangan melakukan force-push, menghapus branch remote, atau menulis ulang histori tanpa perintah eksplisit pengguna.
- Jangan menggunakan `git reset --hard` atau menghapus perubahan pengguna.
- Integration, staging, backup/restore, dan production deployment hanya boleh dinyatakan lulus berdasarkan bukti runtime.

