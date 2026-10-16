# Application Structure

**Status:** Baseline Step 02 — 12 Oktober 2026; diperbarui Step 05 dan Step 07
**Referensi:** ADR-0001 (modular monolith), ADR-0002 (Laravel/Livewire), `DOMAIN_BOUNDARIES.md`

## 1. Skeleton

Aplikasi dibuat dari official Laravel Livewire starter kit (Laravel 13, Livewire 4, Flux UI free, Fortify) dengan Pest. Struktur standar Laravel dipertahankan agar tooling, upgrade, dan onboarding tetap sederhana.

| Lokasi | Isi saat ini |
|---|---|
| `app/Http/Controllers/HealthController.php` | Liveness probe publik minimum (`GET /health`) |
| `app/Actions/Fortify`, `app/Providers/FortifyServiceProvider.php` | Authentication lokal dari starter kit. Kredensial diperiksa lewat adapter `CredentialAuthenticator` (Step 05). Registrasi publik nonaktif; akun dibuat oleh System Admin (DEC-013) |
| `app/Modules/Shared` | Fondasi data Step 04 (audit, outbox, idempotency, optimistic lock, storage metadata) serta cast `BusinessDate`, `BusinessRuleViolation`, `Actor`, dan `GuardsInput` |
| `app/Modules/Organization` | Hierarki company → department → team bertanggal efektif, pegawai, penugasan, `OrganizationDirectory` (kontrak baca publik), dan policy |
| `app/Modules/IdentityAccess` | Role/capability, `AccessControl`, role assignment, delegasi, lifecycle akun, audit login, middleware session/MFA, dan base page Livewire `GuardedPage`. Detail: `IDENTITY_ACCESS.md` |
| `app/Modules/KpiGovernance` | Kamus KPI dan template scorecard berversi, strategi skor allowlist, kandidat PRD, dan `TemplateResolver`. Detail: `KPI_GOVERNANCE.md` |
| `app/Modules/PeriodAssignment` | Periode penilaian, deadline per tahap, snapshot penugasan dan approver, serta kontrak `PeriodLifecycleParticipant`. Detail: `PERIOD_TARGET.md` |
| `app/Modules/Target` | Slot dan versi target, validasi nilai terhadap definisi KPI, staged import CSV, dan unduhan file isian. Detail: `PERIOD_TARGET.md` |
| `app/Modules/ActualEvidence` | Aktual per rentang observasi, evidence di object storage private dengan karantina dan tautan bertanda tangan, serta staged import CSV/XLSX. Detail: `ACTUAL_EVIDENCE.md` |
| `app/Modules/Scoring` | Mesin skor murni (aritmetika eksak, strategi berversi, policy scorecard bertanda tangan), snapshot perhitungan immutable, dan ekspor rekonsiliasi. Skor resmi diblokir sampai DEC-004. Detail: `SCORING.md` |
| `resources/views/pages/scorecards/` | Halaman Livewire scorecard (daftar periode, rekonsiliasi, detail per KPI) |
| `app/Modules/Workflow` | Review, kalibrasi, approval final, bypass tahap review bertanda tangan, penguncian periode, dan koreksi aktual terverifikasi. Riwayat keputusan append-only. Detail: `WORKFLOW.md` |
| `app/Modules/Notification` | Notifikasi di aplikasi dan email, preferensi, consumer outbox, pengingat dan eskalasi dari aturan bertanda tangan. Detail: `DASHBOARD_NOTIFICATION_TEAM_REVIEW.md` |
| `app/Modules/TeamReview` | Daily team review, corrective action dengan PIC dan tenggat, coaching notes terbatas |
| `app/Modules/Reporting` | Query dashboard per peran dan halaman team (tanpa cache); katalog laporan dan ekspor Excel/PDF lewat antrean. Detail: `REPORTING.md` |
| `app/Modules/Operations` | Register operasional generik (satu tabel, jenis catatan bertipe) untuk sumber KPI team: revisi Design dan costing vs aktual. Detail: `docs/product/COMMERCIAL_SUPPORT_SOURCES.md` |
| `resources/views/pages/operations/` | Halaman Livewire register operasional |
| `app/Modules/Integration` | Inventori koneksi, kontrak manifest `kpi-inbound-v1`, adapter anti-corruption ke staged import modul, pipeline inbound (autentikasi, checksum, rekonsiliasi, retry/dead-letter, arsip/karantina), exception register, migrasi master pegawai. Detail: `docs/integration/` |
| `resources/views/pages/integrations/` | Dashboard run integrasi dan exception register |
| `app/Modules/Quality` | NCR dan CAPA Quality Control: lifecycle PRD §10.3, PIC/tenggat/bukti per tahap, reopen, koreksi, repeat link, KPI terkait berversi. Detail: `docs/product/QUALITY_NCR_CAPA.md` |
| `resources/views/pages/quality/` | Dashboard corrective action NCR dan halaman detail NCR |
| `app/Modules/Production` | Read model produksi, packing, pengiriman, stok warehouse, dan work order dari batch sumber; kalender shift berversi; board PPIC dengan riwayat status append-only. Bukan MES/WMS/ERP. Detail: `docs/product/PRODUCTION_SOURCES.md` |
| `resources/views/pages/production/`, `resources/views/pages/ppic/` | Halaman Livewire impor/monitoring produksi dan board PPIC |
| `app/Modules/Accounting` | Read model piutang dari snapshot sumber: staged import, aging berbucket PRD, rekonsiliasi, faktur pajak. Bukan buku besar. Detail: `docs/product/ACCOUNTING_SOURCES.md` |
| `resources/views/pages/accounting/` | Halaman Livewire piutang (impor snapshot, aging, rekonsiliasi) |
| `resources/views/pages/reports/` | Halaman Livewire laporan: katalog, filter, pratinjau, dan daftar ekspor |
| `resources/views/pages/{⚡dashboard,notifications,team,team-review}` | Halaman Livewire dashboard, notifikasi, halaman team, dan team review |
| `resources/views/pages/approvals/` | Halaman Livewire approval (inbox, board periode, scorecard, antrean koreksi, bypass) |
| `resources/views/pages/actuals/` | Halaman Livewire aktual (daftar periode, entri dan bukti, import) |
| `resources/views/pages/kpi/` | Halaman Livewire KPI (kamus, detail/versi/perbandingan, template builder/preview) |
| `resources/views/pages/periods/` | Halaman Livewire periode (daftar, setup dan snapshot, matriks target, import) |
| `resources/views/pages/administration/` | Halaman Livewire Administration (organisasi, pegawai, akun, role, delegasi, access review) |
| `app/Support/Operations/Heartbeat.php`, `app/Console/Commands/Operations/*`, `app/Jobs/Operations/QueueSmokeJob.php` | Tooling operasional platform (Step 03): heartbeat worker/scheduler, `ops:readiness`, `ops:queue-smoke`; sejak Step 18 juga `ops:metrics`, `ops:security-check`, `ops:storage-snapshot`, `ops:storage-verify`. Bukan modul bisnis |
| `app/Http/Middleware/*`, `app/Support/Observability/*`, `app/Providers/ObservabilityServiceProvider.php`, `config/observability.php` | Hardening dan observability platform (Step 18): correlation ID + log request (`RequestContext`), header keamanan/CSP (`SecurityHeaders`), proxy tepercaya (`TrustConfiguredProxies`), redaksi log, slow query, job gagal, limiter unduhan. Detail: `docs/operations/OBSERVABILITY.md` |
| `scripts/backup.sh`, `scripts/dr-rehearsal.sh`, `scripts/dr/*` | Backup terenkripsi dan restore rehearsal terisolasi (Step 18). Detail: `docs/operations/BACKUP_RESTORE.md` |
| `config/navigation.php` | Definisi menu utama. Menu Administration memakai ability `administration.view`; menu Periode & Target memakai `periods.view` |
| `resources/views/components/ui/` | UI primitives aplikasi |
| `resources/views/layouts/` | Application shell (sidebar) dan auth layout |
| `resources/views/modules/placeholder.blade.php` | Halaman placeholder menu yang belum diimplementasikan |
| `resources/views/errors/` | Halaman error mandiri (401/403/404/419/429/500/503) |
| `tests/Feature/Smoke`, `tests/Feature/Ui`, `tests/Unit/ArchTest.php` | Smoke, UI component, dan architecture tests |

## 2. Namespace modul (diperkenalkan saat modul pertama dibuat)

Modul bisnis ditempatkan di `app/Modules/<Module>` dan mengikuti dependency direction pada `DOMAIN_BOUNDARIES.md`:

```text
app/Modules/<Module>/
  Domain/          # rule, value object, domain event — tanpa Laravel HTTP/Livewire/queue/storage vendor
  Application/     # use case/service, transaction boundary, public contract modul
  Infrastructure/  # Eloquent model/repository, adapter storage/queue/external
  Http/            # controller/Livewire component tipis (input/output + authorization entry)
```

Nama modul mengikuti tabel ownership (`IdentityAccess`, `Organization`, `KpiGovernance`, `PeriodAssignment`, `Target`, `ActualEvidence`, `Scoring`, `Workflow`, `TeamReview`, `Reporting`, `Notification`, `Audit`, `Integration`).

Step 02 **sengaja tidak** membuat folder/class kosong untuk modul tersebut. Folder dibuat oleh step yang mengimplementasikan modulnya (mulai Step 04/05). `tests/Unit/ArchTest.php` otomatis menegakkan larangan dependency pada `App\Modules\*\Domain` begitu `app/Modules` ada.

## 3. Aturan UI

- Controller dan Livewire component tetap tipis; business logic berada pada application/domain service.
- Gunakan `x-ui.*` primitives untuk halaman aplikasi; Flux UI tetap dipakai oleh halaman auth/settings dari starter kit.
- Status selalu memakai teks (+ simbol) dan tidak hanya warna (NFR 007). Fokus keyboard terlihat melalui `:focus-visible` global.
- Design token warna berada di `resources/css/app.css` (`brand`, `info`, `success`, `warning`, `danger`).
- Menyembunyikan menu bukan authorization. Setiap route/Livewire action/download/export wajib memeriksa policy di server (ADR-0009).

### Primitives

| Komponen | Kegunaan |
|---|---|
| `x-ui.button` | Button/link; default `type="button"`; variant `primary`, `secondary`, `danger`, `ghost` (variant tidak dikenal = error) |
| `x-ui.label`, `x-ui.input`, `x-ui.field-error` | Form field dengan label terasosiasi, hint, `aria-invalid`, dan `aria-describedby` ke pesan error |
| `x-ui.alert` | Pesan `info`/`success`/`warning`/`danger` dengan role dan label teks |
| `x-ui.status-badge` | Badge status teks + simbol |
| `x-ui.page-header` | Judul halaman (`h1`), deskripsi, slot actions |
| `x-ui.table-shell` | Tabel dengan caption, header `scope="col"`, dan slot empty |
| `x-ui.empty-state`, `x-ui.loading-state`, `x-ui.error-state` | State kosong, memuat (`role="status"`), dan gagal (`role="alert"`) |

## 4. Konfigurasi runtime

- `config('app.timezone')` = UTC (runtime/persistence); `config('app.business_timezone')` dari `APP_TIMEZONE` (default `Asia/Jakarta`).
- Default database connection `pgsql`. Test memakai SQLite in-memory melalui `phpunit.xml`.
- `/health` didaftarkan di luar middleware group `web` (tanpa session/cookie/CSRF). Endpoint bawaan `/up` dinonaktifkan agar hanya ada satu probe publik.

## 5. Technical debt yang dicatat

- Bahasa UI belum diputuskan: halaman Step 02 berbahasa Indonesia, sedangkan halaman auth/settings starter kit masih bahasa Inggris dan `APP_LOCALE=en` (atribut `lang="en"`). Keputusan bahasa dan file `lang/` diperlukan sebelum UAT (WCAG 3.1.1).
- Test bawaan starter kit (`tests/Feature/Auth`, `tests/Feature/Settings`) masih bergaya kelas PHPUnit; test baru memakai sintaks Pest. Konversi otomatis `pest --drift` gagal di Windows.
- Driver S3 (`league/flysystem-aws-s3-v3`) dan `phpredis` tersedia sejak Step 03 (phpredis di image Docker); disk khusus evidence/export dan aturan upload diputuskan pada Step 08.
