# Prompt Step 10 — Approval, Kalibrasi, Period Lock, dan Koreksi

Anda bertindak sebagai Workflow Engineer. Implementasikan state machine penilaian end-to-end dengan segregation of duties dan audit lengkap.

## Prasyarat

Approval matrix per team, delegation rule, SLA, correction rule, period lifecycle, scoring snapshot, dan authorization tests harus tersedia. Jangan menebak approver yang masih terbuka.

## Alur acuan

Actual/evidence → Team Leader verification → Manager review → HR calibration → Management final approval → period lock → correction request/reopen terkontrol. Sesuaikan hanya berdasarkan keputusan yang disetujui.

## Pekerjaan

1. Implementasikan state machine eksplisit dengan allowed transition, actor policy, prerequisite, validation, timestamp, comment/reason, dan audit.
2. Implementasikan inbox tugas per approver, bulk action yang aman, detail review, return/reject, resubmit, delegation, serta escalation metadata.
3. Approval chain harus dapat melewati Department Head/Manager ketika posisi tersebut belum tersedia, tetapi hanya berdasarkan konfigurasi per team yang telah disetujui. Simpan alasan, effective date, dan approver pengganti.
4. Cegah self-approval dan aksi ganda pada langkah yang sama. Terapkan optimistic lock/database lock yang tepat untuk race condition approval.
5. Implementasikan HR calibration tanpa kehilangan nilai asli. Simpan proposed value, calibrated value, reason, evidence, actor, dan approval.
6. Implementasikan final approval dan period-lock precheck: completeness, unresolved rejection, missing evidence, pending correction, formula/version consistency, dan outbox processing yang relevan.
7. Record final tidak boleh diedit langsung. Implementasikan correction request dengan reason, before/after, reviewer, effective timestamp, version baru, recalculation, dan audit linkage.
8. Reopen period hanya untuk role dan kondisi yang disetujui; tampilkan warning dampak dan catat approval.
9. Buat UI timeline status, approver chain, pending inbox, calibration compare, lock checklist, correction queue, dan audit history.
10. Kirim domain event/outbox untuk notifikasi tanpa menggagalkan transaksi utama secara tidak konsisten.
11. Tambahkan test happy path, reviewer kosong dengan approved bypass, reject/resubmit, expired delegation, self-approval, double-click, concurrent approval, correction, reopen, lock failure, dan unauthorized transition.
12. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Tidak ada state yang dapat dilompati melalui request langsung.
- Setiap keputusan approval dapat ditelusuri ke actor, role/scope, waktu, komentar, dan data version.
- Calibration tidak menghapus original score.
- Locked data tidak dapat diubah tanpa correction/reopen resmi.
- Concurrent approval tidak menghasilkan dua hasil final.

## Laporan akhir

Laporkan state diagram aktual, policy matrix, concurrency evidence, correction example, open approval decision, dan status `PASS/PARTIAL/BLOCKED`.
