# Role dan Approval Matrix

Dokumen ini memetakan kewenangan berbasis role **dan** organization scope. Nama personel dan approver aktual belum boleh diisi dengan asumsi. Semua assignment harus memiliki tanggal efektif.

## Role minimum

| Role | Scope default | Akses utama | Larangan utama |
|---|---|---|---|
| Management | Seluruh company | Ringkasan lintas unit, drill-down terotorisasi, final approval | Tidak mengubah actual sumber secara langsung |
| Department Head/Manager | Department dan team di bawahnya | Review team, exception, action overdue | Tidak melihat unit lain tanpa assignment |
| Team Leader | Home team/assigned team | Verifikasi anggota/team, daily review, corrective action | Tidak menimpa data final atau self-approve |
| Employee | Diri sendiri dan tugas yang diberikan | Target pribadi, input actual/evidence, feedback | Tidak melihat score individu lain |
| HR Performance | Governance KPI sesuai mandate | Periode, template, completeness, calibration, correction administration | Perubahan nilai harus melalui workflow dan audit |
| Auditor | Scope audit yang ditugaskan | Read-only definition, evidence, approval, audit, export terkontrol | Tidak create/update/approve |
| System Admin | Konfigurasi teknis dan akun | Account, role plumbing, integration config, health | Tidak mengubah target, actual, score, calibration, atau approval |

## Matriks aksi

Legenda: `A` allowed sesuai scope, `C` conditional/delegated, `R` read-only, `—` denied.

| Aksi | Management | Manager | Team Leader | Employee | HR Performance | Auditor | System Admin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Melihat company summary | A | — | — | — | A | R | — |
| Melihat department/team | A | A | A untuk team sendiri | — | A | R sesuai mandate | — |
| Melihat score pribadi | C | C | C | A | C | R sesuai mandate | — |
| Mengelola account teknis | — | — | — | — | — | — | A |
| Mengelola periode | — | — | — | — | A | R | — |
| Membuat/mengubah draft template | — | C | C | — | A | R | — |
| Publish template | C | C | — | — | A sesuai approval | R | — |
| Menetapkan target | — | C | C | C bila ditugaskan | C | R | — |
| Input actual | — | C bila data owner | C bila data owner | A untuk tugasnya | C bila data owner | R | — |
| Upload evidence | — | C | C | A untuk tugasnya | C | R | — |
| Verify actual | — | — | A selain miliknya | — | C | R | — |
| Review team | — | A selain miliknya | — | — | C | R | — |
| Calibration | — | C sesuai policy | — | — | A | R | — |
| Final approval | A selain miliknya | — | — | — | C sebagai administrator | R | — |
| Lock period | C | — | — | — | A setelah precheck | R | — |
| Request correction | C | C | C | C | C | — | — |
| Approve correction/reopen | C sesuai matrix | C sesuai matrix | — | — | C sesuai policy | R | — |
| Export | A | A | A untuk scope | C untuk data sendiri | A | R/terkontrol | — |
| Melihat audit trail | A | C | C | C untuk record sendiri | A | R | C untuk troubleshooting tanpa nilai sensitif |

Semua `C` harus dijelaskan oleh policy dan decision register sebelum UAT.

## Approval flow acuan

1. Pemilik data submit actual dan evidence.
2. Team Leader memverifikasi, kecuali data milik dirinya sendiri.
3. Department Head/Manager mereview bila posisi tersedia untuk team tersebut.
4. Jika posisi Manager/Head tidak tersedia, jalur hanya boleh melewatinya berdasarkan konfigurasi yang disetujui menuju HR Performance atau Management.
5. HR Performance memeriksa kelengkapan dan melakukan calibration sesuai policy.
6. Management memberi final approval.
7. HR menjalankan period-lock precheck dan mengunci periode sesuai kewenangan.

## Delegasi

Delegasi wajib mempunyai pemberi, penerima, role/scope, alasan, mulai, berakhir, pembuat keputusan, dan audit. Delegasi tidak boleh:

- berlaku tanpa tanggal akhir;
- memperluas scope melebihi pemberi;
- memungkinkan self-approval;
- menghapus tanggung jawab atau histori approver asli.

## Matriks approver per team

Kolom bertanda `OPEN` harus diselesaikan melalui DEC-002.

| Department/group | Team | Team Leader | Manager/Head reviewer | Jalur bila reviewer kosong | Substitute approver | Effective date | Status |
|---|---|---|---|---|---|---|---|
| Commercial dan Support | Sales | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | Customer Service | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | R&D Costing | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | Design A | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | Design B | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | Admin PPIC | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | Purchasing | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | HR | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Commercial dan Support | GA | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Accounting | Admin Accounting | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Accounting | Account Receivable | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Accounting | Tax Invoice | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Woven | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Warehouse | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Screen Print | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Quality Control | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Packing Logistic | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Logistic | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Hot Cut | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Hang Tag | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |
| Produksi | Flexo Printing | OPEN | OPEN | OPEN | OPEN | OPEN | OPEN |

## Sign-off matrix

| Area | Wajib menyetujui |
|---|---|
| Organization dan assignment | HR Performance, Department/Team owner |
| Role dan scope | HR Performance, Management, IT/Security |
| Approval chain | Management, HR Performance, relevant unit owner |
| Delegation/self-approval | Management, HR Performance, Internal Audit/Compliance bila ada |
| Data sensitif/coaching notes | HR, Legal/Compliance, Management |

