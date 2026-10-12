# Prompt Step 11 — Dashboard, Notifikasi, dan Corrective Action

Anda bertindak sebagai Full-stack Laravel Engineer dan UX Engineer. Buat dashboard operasional per peran, notification/escalation, serta corrective action. Jangan membuat angka dummy terlihat seperti data produksi.

## Prasyarat

Organization scope, actual, scoring, approval, SLA, dan notification preference harus tersedia. Baca accessibility requirement dan `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Buat dashboard Management: ringkasan company, group/department, team, trend, rating distribution, KPI merah, missing data, overdue approval, dan drill-down terotorisasi.
2. Buat dashboard HR Performance: period progress, completeness, calibration queue, lock readiness, rating distribution, anomaly, dan correction request.
3. Buat dashboard Manager/Team Leader: anggota, target-versus-actual, KPI merah, pending verification, missing actual, rejected item, masalah, serta corrective action.
4. Buat employee dashboard: KPI milik sendiri, deadline, actual/evidence status, score yang boleh dilihat, feedback, dan tindakan berikutnya.
5. Auditor hanya mendapat read-only audit/reconciliation view; System Admin mendapat health/configuration view tanpa hak mengubah nilai.
6. Lengkapi halaman Team Leader dengan coaching notes berakses terbatas, permintaan revisi data, delegasi penilaian, dan histori perubahan anggota. Kebutuhan approval cuti harus diimplementasikan sebagai integrasi/link ke HRIS bila HRIS menjadi source of truth; jangan membangun modul cuti baru tanpa keputusan scope.
7. Implementasikan Daily Team Review dan corrective action: masalah, reason/penyebab, dampak KPI, owner/PIC, due date, priority, action, evidence, reviewer, completion, overdue, dan audit. Status minimum mengikuti PRD: `OPEN`, `IN_PROGRESS`, `WAITING`, `VERIFIED`, `CLOSED`.
8. Implementasikan notification preference dan channel internal/email yang disetujui. Gunakan queued notification dan deep link yang selalu melakukan authorization check.
9. Implementasikan reminder dan escalation idempotent. Hindari duplicate notification; simpan delivery attempt dan hasil tanpa membocorkan data sensitif.
10. Optimalkan query dengan pagination, aggregate terukur, eager loading, cache yang aman, serta invalidation yang benar. Cache tidak boleh melewati scope pengguna.
11. Terapkan responsive desktop/tablet, keyboard navigation, focus state, label, empty/loading/error state, dan status yang tidak hanya bergantung pada warna.
12. Tambahkan feature test dashboard per role, coaching-note access, revision/delegation, cross-scope denial, notification retry/deduplication, scheduler, corrective action SLA, serta query-count regression untuk halaman utama.
13. Perbarui `PROJECT_PROGRESS.md`.

## Acceptance criteria

- Setiap role hanya melihat data dan action dalam scope-nya.
- Angka dashboard dapat ditelusuri ke record sumber.
- Reminder/escalation yang dijalankan ulang tidak menggandakan notifikasi.
- Corrective action mempunyai owner dan deadline yang dapat diaudit.
- Dashboard utama memenuhi target performa yang telah disetujui pada data representatif.

## Laporan akhir

Laporkan dashboard per role, query/performance evidence, notification channel yang benar-benar diuji, accessibility result, blocker, dan status `PASS/PARTIAL/BLOCKED`.
