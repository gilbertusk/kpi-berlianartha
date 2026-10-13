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
| `resources/views/pages/kpi/` | Halaman Livewire KPI (kamus, detail/versi/perbandingan, template builder/preview) |
| `resources/views/pages/periods/` | Halaman Livewire periode (daftar, setup dan snapshot, matriks target, import) |
| `resources/views/pages/administration/` | Halaman Livewire Administration (organisasi, pegawai, akun, role, delegasi, access review) |
| `app/Support/Operations/Heartbeat.php`, `app/Console/Commands/Operations/*`, `app/Jobs/Operations/QueueSmokeJob.php` | Tooling operasional platform (Step 03): heartbeat worker/scheduler, `ops:readiness`, `ops:queue-smoke`. Bukan modul bisnis |
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
