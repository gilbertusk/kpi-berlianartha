# Prompt Step 01 — Arsitektur dan Rencana Implementasi

Anda bertindak sebagai Lead Architect. Susun desain teknis untuk aplikasi KPI berdasarkan PRD dan keputusan Step 00. Jangan mengubah aturan bisnis dan jangan memulai implementasi fitur.

## Prasyarat

Baca PRD, `docs/product/DECISION_REGISTER.md`, `docs/product/ROLE_APPROVAL_MATRIX.md`, serta `PROJECT_PROGRESS.md`. Jika Step 00 belum dikerjakan, berhenti dan laporkan prasyarat yang hilang.

## Stack terkunci

- Laravel 13, PHP 8.4.
- Blade, Livewire, Alpine.js, Tailwind CSS.
- PostgreSQL 18, Redis, Laravel Horizon, S3-compatible storage.
- Pest, Playwright, Docker Compose, Nginx/PHP-FPM, GitHub Actions.
- Modular monolith. Jangan memakai microservices, Kubernetes, Next.js, atau SPA terpisah tanpa keputusan arsitektur baru yang disetujui.

## Pekerjaan

1. Buat `docs/architecture/SOLUTION_ARCHITECTURE.md` berisi system context, container view, module boundaries, data flow, trust boundary, deployment topology, serta hubungan web/worker/scheduler.
2. Buat ADR terpisah minimal untuk:
   - modular monolith;
   - full-stack Laravel dengan Livewire;
   - PostgreSQL sebagai source of truth;
   - Redis hanya sebagai cache/queue, bukan source of truth;
   - object storage untuk evidence;
   - audit append-only secara logis;
   - effective-dated organization dan versioned KPI definition;
   - timezone: simpan timestamp secara konsisten dan tampilkan Asia/Jakarta;
   - strategi identifier, decimal precision, serta money/percentage storage;
   - authentication/SSO dan authorization scope.
3. Definisikan modul: Identity, Organization, KPI Dictionary, Templates, Periods, Targets, Actuals, Evidence, Scoring, Approval, Calibration, Correction, Dashboard, Reporting, Notification, Audit, Integration, dan modul operasional khusus.
4. Buat sequence diagram untuk submit actual, approval, period lock, correction, import, dan asynchronous export.
5. Buat `docs/architecture/DOMAIN_BOUNDARIES.md`: aggregate, service, event, policy, dan ketergantungan yang diperbolehkan. Cegah business logic menumpuk pada controller atau Livewire component.
6. Buat `docs/implementation/BACKLOG.md` berisi epic, dependency, acceptance criteria, risiko, dan mapping ke Step 02–21.
7. Buat `docs/implementation/TEST_STRATEGY.md` dan `docs/implementation/RELEASE_STRATEGY.md`.
8. Buat threat model awal dan data-classification map.
9. Perbarui `PROJECT_PROGRESS.md` tanpa menghapus histori.

## Prinsip desain

- Gunakan database transaction untuk perubahan status kritis.
- Gunakan explicit state transition, policy, idempotency key, optimistic locking jika relevan, dan transactional outbox untuk efek samping penting.
- Published definition dan record final tidak boleh dimutasi diam-diam.
- Pisahkan application log dari business audit trail.
- Setiap query dan export wajib menerapkan organization scope.
- Rancang adapter integrasi, tetapi jangan mengunci ke sistem sumber yang belum disetujui.

## Acceptance criteria

- Setiap kebutuhan inti PRD memiliki rumah modul yang jelas.
- Tidak ada circular dependency antarmodul.
- Diagram membedakan satu deployable application dari process/container runtime.
- Risiko data, otorisasi, concurrency, correction, dan audit mempunyai mitigasi.
- Backlog memiliki urutan dependency dan exit gate.

## Validasi dan laporan

Periksa seluruh link Markdown, konsistensi istilah, dan mapping PRD → modul → backlog → test. Laporkan file yang dibuat, ADR utama, risiko yang masih terbuka, dan status `PASS/PARTIAL/BLOCKED`.
