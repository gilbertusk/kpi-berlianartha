# Prompt Step 05 — Autentikasi, Organisasi, dan Otorisasi

Anda bertindak sebagai Senior Laravel Security Engineer. Implementasikan identity, organization hierarchy, assignment, role, dan authorization scope.

## Prasyarat

Baca PRD, decision register, role approval matrix, security ADR, data conventions, dan `PROJECT_PROGRESS.md`. Bila identity provider belum dipilih, implementasikan local development authentication yang dapat diganti adapter SSO; jangan mengklaim SSO selesai.

## Struktur wajib

Company → Group/Department → Team → Employee. Seed referensi 21 team tanpa mengaktifkan KPI kandidat:

- Commercial dan Support: Sales, Customer Service, R&D Costing, Design A, Design B, Admin PPIC, Purchasing, HR, GA.
- Accounting: Admin Accounting, Account Receivable, Tax Invoice.
- Produksi: Woven, Warehouse, Screen Print, Quality Control, Packing Logistic, Logistic, Hot Cut, Hang Tag, Flexo Printing.

## Pekerjaan

1. Implementasikan user lifecycle, login/logout, reset credential sesuai kebijakan, session protection, dan MFA/SSO adapter boundary jika diputuskan.
2. Implementasikan organization unit dan employee assignment bertanggal efektif agar perubahan organisasi tidak mengubah histori.
3. Implementasikan role minimum: Management, Department Head/Manager, Team Leader, Employee, HR Performance, Auditor, System Admin.
4. Pisahkan role dari organization scope. Seorang Manager hanya boleh mengakses unit yang menjadi cakupannya.
5. Implementasikan delegation/acting assignment bertanggal, alasan, pemberi izin, dan audit. Jangan membuka self-approval.
6. Gunakan Laravel Policies/Gates untuk setiap aksi. Jangan hanya menyembunyikan tombol UI.
7. Pastikan System Admin dapat mengelola konfigurasi teknis/akun tetapi tidak boleh mengubah skor atau actual KPI.
8. Buat UI master organization, employee assignment, role assignment, delegation, dan access review dengan pagination/filter/audit.
9. Buat authorization test matrix mencakup allow dan deny untuk seluruh role, lintas department/team, inactive assignment, expired delegation, dan direct URL/API access.
10. Buat seeder development dengan akun fiktif, password non-production, dan label yang jelas.
11. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Struktur 3 group/department dan 21 team tersedia dengan effective date.
- Histori assignment tidak berubah ketika pegawai pindah team.
- Pengguna tidak dapat membaca atau mengubah data di luar scope melalui UI maupun request langsung.
- Auditor read-only; System Admin tidak dapat memodifikasi nilai KPI.
- Delegation kedaluwarsa otomatis dan tercatat.
- Semua kombinasi role/scope kritis memiliki automated test.

## Laporan akhir

Berikan role-scope matrix aktual, data seed, bukti negative authorization test, status SSO, blocker bisnis, dan status `PASS/PARTIAL/BLOCKED`.
