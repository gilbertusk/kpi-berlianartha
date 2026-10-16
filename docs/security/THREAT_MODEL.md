# Threat Model — Sistem KPI PT Berlian Artha Label

**Status:** Baseline; wajib diperbarui ketika integration, hosting, SSO, dan data classification difinalkan.

## 1. Assets

- identity, role, organization assignment, dan delegation;
- KPI definition, target, actual, score, rating, calibration, dan approval;
- evidence, coaching notes, corrective action, NCR/CAPA;
- customer, invoice/outstanding, production/work-order references;
- audit event dan export;
- secret, session, integration credential, backup, dan recovery material.

## 2. Trust boundaries

1. Browser/user ↔ edge/web application.
2. Application ↔ PostgreSQL/Redis/object storage.
3. Worker ↔ external systems/email/identity provider.
4. Developer/CI ↔ artifact registry/deployment environment.
5. Admin/operator ↔ production control plane/backup.

## 3. Threat register

| ID | Threat | Scenario | Impact | Required controls | Validation |
|---|---|---|---|---|---|
| T-001 | Broken object authorization | User mengganti ID team/employee/file/export | Data personal/KPI bocor | Server policy + organization scope + opaque download + audit | Negative tests per role/scope |
| T-002 | Privilege escalation | System Admin atau delegated user mengubah score | Integrity | Separation of duties, explicit policies, delegation scope/expiry | Forged request/Livewire tests |
| T-003 | Self-approval | Approver menyetujui record miliknya | Governance failure | Ownership check, substitute matrix, atomic state guard | Workflow negative tests |
| T-004 | Session/account takeover | Cookie/token dicuri atau session lemah | Full unauthorized access | TLS, secure cookie, MFA/SSO, session rotation/revoke, rate/risk control | Security config/session tests |
| T-005 | CSRF/XSS/injection | Form, note, import, filter, atau template berisi payload | State change/data theft | CSRF, validation, output escaping, parameterized query, CSP plan | Automated/manual security tests |
| T-006 | Mass assignment | Request mengubah field status/scope tersembunyi | Integrity/privilege | DTO/form request, allowlist, domain command | Feature tests |
| T-007 | Malicious upload | Evidence executable/polyglot/oversized | Malware/availability | Quarantine, signature/MIME/size validation, scan, private storage | Upload test suite |
| T-008 | Signed URL leakage | URL panjang umur atau tidak scoped | Evidence leak | Short expiry, authorization before issue, private bucket, audit | Expiry/unauthorized tests |
| T-009 | Import replay/duplicate | Batch yang sama diproses ulang | Double actual/score | Hash, idempotency key, source unique key, reconciliation | Replay tests |
| T-010 | Formula/config abuse | Arbitrary expression atau unreviewed formula dipublish | Wrong scoring/RCE | Whitelisted strategies, version approval, no eval | Publish and parser tests |
| T-011 | Race condition | Double approval, edit-vs-lock, correction-vs-export | Inconsistent state | Transaction, lock/token, unique constraints, idempotency | Concurrency tests |
| T-012 | Audit tampering/bypass | Bulk update tidak tercatat | Non-repudiation loss | Domain service audit in same transaction, restricted DB role | Direct-path and immutability tests |
| T-013 | Export exfiltration | Broad export or spreadsheet injection | Data leak/code execution | Scope snapshot, metadata, formula sanitization, expiry, audit | Export security tests |
| T-014 | Secret leakage | `.env`, logs, CI output, error page | System compromise | Secret manager, redaction, ignore rules, scanning | Repo/log/image scan |
| T-015 | Webhook/API spoofing | Fake/replayed source event | Wrong actual | TLS, signature/OAuth, timestamp/nonce, replay guard | Contract/security tests |
| T-016 | Queue poisoning/retry storm | Bad payload repeatedly fails | Availability/data duplicates | Schema/version, bounded retry, DLQ/manual recovery, idempotency | Failure/replay tests |
| T-017 | Cache scope leak | Cached dashboard reused across scopes | Cross-team exposure | Scope-aware keys, safe invalidation, no sensitive shared cache | Cache authorization tests |
| T-018 | Backup exposure/failure | Backup unencrypted or untested | Mass leak/irrecoverability | Encryption, access control, retention, isolated restore rehearsal | Restore and access evidence |
| T-019 | Dependency/container compromise | Vulnerable package/image | Application compromise | Lockfiles, audit/scan, minimal image, SBOM, patch policy | CI scan and exception register |
| T-020 | Availability exhaustion | Heavy report/import/dashboard | Denial of service | Pagination, async job, rate/size limit, query budget, monitoring | Load/resource tests |

## 4. Abuse cases requiring explicit tests

- Employee requests another employee's evidence by URL.
- Team Leader modifies team identifier in Livewire payload.
- System Admin calls score update route directly.
- Manager approves own score using expired delegation.
- Same import or webhook is replayed many times.
- Actual is edited while HR locks the period.
- Formula/rating changes after a historical period is locked.
- Export contains a cell beginning with spreadsheet formula markers.
- Notification deep link opens record after user's scope changed.
- Object-storage URL is reused after expiry.

## 5. Residual/open risks

- Identity provider/MFA is not selected.
- Hosting/network topology and WAF are not selected.
- Antivirus product/runtime is not selected.
- Retention, RPO/RTO, and legal-hold requirements are open.
- Integration protocols and credential lifecycle are open.

These items block production security acceptance, not architecture drafting.


## 6. Tinjauan Step 18 (2026-10-16)

Tinjauan per area terhadap kode pada akhir Step 18. "Bukti" menunjuk test otomatis atau langkah runtime; kontrol eksternal (TLS, WAF, secret manager, SSO/MFA) **tidak** diklaim aktif karena hosting (DEC-014) dan identity provider (DEC-013) belum diputuskan.

| Area | Kontrol yang berlaku | Bukti | Residual / blocker |
|---|---|---|---|
| Authentication | Login lokal Fortify; throttle 5/menit per email+IP; pesan gagal generik; akun nonaktif diputus di setiap request (`EnsureAccountIsActive`); 2FA/passkey tersedia, `mfa` middleware pada semua halaman bisnis bila `IDENTITY_MFA_REQUIRED=true` | `AuthenticationTest`, `AccountLifecycleTest`, `TwoFactorChallengeTest` | SSO dan kewajiban MFA: DEC-013. Penguncian akun setelah N kegagalan belum ada kebijakan; hanya throttle |
| Reset password | **Diperbaiki Step 18:** jawaban sama untuk alamat terdaftar, tidak terdaftar, atau yang baru dikirimi tautan (tidak ada enumerasi akun); throttle `password-reset` 5/menit per IP per rute untuk `password.email` dan `password.update`; tautan reset selalu dari `APP_URL`; token broker 60 menit, throttle broker 60 detik per akun | `HardeningTest` (reset password) | Perbedaan waktu respons (pengiriman email sinkron) tidak dihilangkan; dapat ditekan dengan email lewat queue saat SMTP produksi ditetapkan (DEC-014) |
| Authorization scope | `AccessControl` + `RoleCapabilityMatrix` + scope unit bertanggal; Auditor dan System Admin tidak memegang capability yang mengubah nilai KPI | `docs/security/AUTHORIZATION_REVIEW.md` (entry point → pengecekan → test negatif) | Recertification akses berkala: proses belum disetujui |
| Session | Session database, cookie `HttpOnly`, `SameSite=lax`; ID session diganti saat login, dihapus saat logout/akun nonaktif; produksi wajib `SESSION_SECURE_COOKIE=true` dan `SESSION_ENCRYPT=true` (gerbang `ops:security-check`) | `HardeningTest` (gerbang konfigurasi) | Masa idle 120 menit adalah default framework, bukan kebijakan yang disetujui (DEC-013) |
| CSRF | Middleware `ValidateCsrfToken` grup `web` tanpa pengecualian; Livewire mengirim token di setiap update; `/health` di luar grup `web` dan tidak mengubah state | Konfigurasi `bootstrap/app.php` (tanpa `validateCsrfTokens(except:)`) | — |
| XSS | Blade escaping; satu-satunya output mentah adalah SVG QR 2FA dari server (`#[Locked]`); CSP same-origin, `object-src 'none'`, `frame-ancestors 'self'`, `base-uri 'self'` | `HardeningTest` (header) | CSP masih mengizinkan `'unsafe-inline'` dan `'unsafe-eval'` untuk Livewire/Alpine; versi berbasis nonce perlu build CSP Livewire dan uji UI menyeluruh |
| SQL injection | Query builder/Eloquent dengan binding; `whereRaw`/`selectRaw` hanya dengan placeholder; skrip ops memakai `format('%I')` | Review kode (grep `*Raw(`) | — |
| Mass assignment | Model hanya ditulis oleh service domain dengan daftar kolom eksplisit; komponen Livewire tidak meneruskan input mentah ke `create/fill`; ID dan konteks di komponen dikunci (`#[Locked]`) | Test "forged action" per modul | Model memakai `$guarded = []`; aman selama aturan "hanya service yang menulis" dijaga (ArchTest belum memaksa) |
| SSRF | Aplikasi tidak melakukan request HTTP keluar; dompdf dengan resource remote, PHP, dan JavaScript dimatikan | Grep `Http::`/curl kosong; `PdfExportWriter` | Connector API masa depan wajib allowlist host (DEC-008) |
| Upload file | Bukti ke disk privat, nama file tidak pernah menjadi kunci storage, batas ukuran/MIME, karantina sampai dipindai; tanpa scanner tetap karantina di luar local/test | `EvidenceTest` | Produk antivirus belum dipilih: bukti produksi tetap karantina |
| Object storage | Bucket privat; rute publik `storage/{path}` **dimatikan Step 18** (`serve => false`); unduhan hanya lewat rute bertanda tangan berumur pendek yang terikat akun dan dicek ulang saat unduh; throttle `downloads` 60/menit per akun | `HardeningTest`, `EvidenceTest`, `ReportExportTest` | Enkripsi at-rest bucket bergantung penyedia (DEC-014) |
| Export | Scope pemohon dibekukan saat dibuat dan dicek ulang di worker dan saat unduh; sanitasi formula spreadsheet; file privat kedaluwarsa 72 jam; audit unduhan | `ReportExportTest` | — |
| Queue | Payload berisi ID, bukan data personal; retry terbatas, dead-letter outbox dengan pemulihan manual teraudit; event `queue.job_failed`; metrik antrean dan job gagal | `OutboxTest`, `ObservabilityTest` | Redis tanpa password/TLS di Compose lokal; produksi mengikuti DEC-014 |
| Webhook | **N/A**: tidak ada endpoint webhook. Integrasi inbound berupa file dengan manifest HMAC-SHA256, pemeriksaan checksum, penolakan replay dan ekstrak lama | `InboundPipelineTest` | — |
| Kredensial service-to-service | **Ditambahkan Step 18:** rotasi kunci manifest tanpa jeda (`INTEGRATION_<KONEKSI>_HMAC_KEY_PREVIOUS` diterima selama masa transisi; kunci lama juga wajib ≥32 karakter) | `InboundPipelineTest` (rotasi) | Prosedur rotasi: `docs/operations/BACKUP_RESTORE.md` §6. Media transfer (SFTP/share) belum dipilih (DEC-008) |
| Import | Staged → validasi → commit atomik; idempotensi hash; batas ukuran; rekonsiliasi | Test import tiap modul | — |
| Secret handling | Secret hanya dari environment; `.env.example` tanpa nilai; setiap channel log yang menulis disaring `RedactSensitiveData`: kunci sensitif dengan variasi `_`/`-`/camelCase, `key=value` dan bentuk JSON, header Authorization/Cookie, kredensial di URL, serta **exception** yang diringkas (error database hanya SQLSTATE + SQL tanpa nilai binding); gerbang `ops:security-check` tidak pernah mencetak nilai; kunci backup wajib di luar repository | `HardeningTest` (redaksi, gerbang), `IntegrationAccessTest` | Secret manager produksi: DEC-014 |
| Header, host & TLS | `SecurityHeaders`: CSP, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP; HSTS (`max-age` 1 tahun, tanpa `includeSubDomains` sampai domain diputuskan) hanya bila request HTTPS. **Host header poisoning ditutup:** di luar local/test hanya host `APP_URL` yang dijawab (`TrustApplicationHost`, host lain 400; `/health` dikecualikan), tautan reset password selalu dari `APP_URL`, dan proxy hanya dipercaya untuk alamat klien, port, dan skema (tidak untuk host/prefix; nginx juga membuang header tersebut). Proxy TLS dipercaya hanya lewat `TRUSTED_PROXIES` (IP/CIDR load balancer; `*` diperingatkan) | `HardeningTest`; smoke CI (Host asing → 400) | Terminasi TLS, sertifikat, WAF: DEC-014 |
| Backup | GnuPG AES-256 dengan pemeriksaan integritas (file yang diubah tidak dapat didekripsi), kunci satu baris di luar repository, snapshot database konsisten, restore rehearsal di jaringan terisolasi termasuk skenario manipulasi | `docs/operations/BACKUP_RESTORE.md` | Lokasi offsite, retention, dan target RPO/RTO: DEC-010/DEC-014 |
| Dependency/container | `composer audit`, `npm audit`, Trivy memblokir CRITICAL/HIGH fixable; laporan non-blocking termasuk unfixed untuk triage | `docs/security/VULNERABILITY_EXCEPTIONS.md` | — |

### Perubahan status threat register

- T-004 (session/account takeover): enumerasi akun lewat reset password ditutup; MFA/SSO tetap blocked (DEC-013).
- T-008 (signed URL leakage): rute storage publik dihapus, unduhan di-throttle.
- T-014 (secret leakage): redaksi log terpasang di semua channel; gerbang konfigurasi produksi.
- T-015 (spoofing): rotasi kunci manifest; webhook tetap N/A.
- T-018 (backup): backup terenkripsi dan restore rehearsal terbukti lokal; target dan offsite blocked (DEC-010/DEC-014).
- T-019 (dependency): register exception dengan pemilik dan tanggal kedaluwarsa.
