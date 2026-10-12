# Data Conventions

**Status:** Baseline Step 04 — 12 Oktober 2026  
**Referensi:** ADR-0005 sampai ADR-0008, DOMAIN_BOUNDARIES.md, MIGRATION_POLICY.md

## 1. Identifier dan referensi sumber

- Primary key internal memakai bigint Laravel yang konsisten.
- Record yang boleh muncul pada URL, file reference, event, atau integrasi mempunyai public_id ULID terpisah.
- Identifier dari sistem sumber disimpan sebagai source_system dan source_reference. Identifier eksternal tidak menjadi primary key internal.
- ULID bukan kontrol akses. Semua lookup publik tetap harus melewati policy dan organization scope pada Step 05.

## 2. Waktu dan effective period

- Application timezone dan persistence timestamp adalah UTC.
- Deadline, period boundary, dan tampilan bisnis memakai APP_TIMEZONE, default Asia/Jakarta.
- BusinessTime adalah satu-satunya helper konversi boundary yang dipakai shared layer.
- Tanggal berlaku menggunakan interval setengah terbuka: effective_from inklusif dan effective_until eksklusif. Nilai null pada effective_until berarti belum mempunyai batas akhir.
- Tanggal operasional atau shift disimpan eksplisit oleh modul terkait jika tidak aman diturunkan dari timestamp.

## 3. Numeric dan rounding

- Float tidak boleh dipakai untuk skor resmi, persen, kuantitas presisi, ataupun nilai finansial.
- Modul pemilik data harus menetapkan precision dan scale PostgreSQL numeric secara eksplisit, urutan rounding, dan contoh rekonsiliasi sebelum migration dibuat.
- Step 04 belum membuat field skor atau finansial karena DEC-004 dan definisi KPI masih terbuka.

## 4. Lifecycle dan penghapusan

- Soft delete bukan default. Gunakan hanya untuk draft mutable yang memang membutuhkan recovery dan tidak menutupi histori resmi.
- Data final atau published membuat versi baru; tidak diubah in-place.
- Audit bersifat append-only. Outbox dan idempotency memakai retention/expiry eksplisit, bukan soft delete.
- Metadata object storage memakai status lifecycle dan lock_version; file evidence belum diunggah pada step ini.

## 5. Business audit

AuditRecorder hanya dapat dipanggil ketika transaction database aktif. Record audit menyimpan actor identifier/type, role dan scope snapshot, action, entity, before/after yang sudah disanitasi, reason, source reference, correlation/request ID, serta occurred_at.

Guard database PostgreSQL dan SQLite menolak UPDATE atau DELETE pada audit_logs, termasuk bulk query yang melewati event Eloquent. Password, token, cookie, private key, recovery code, credential, dan key sensitif lain selalu diganti dengan marker REDACTED.

Application log tidak menggantikan business audit dan tidak boleh berisi payload sensitif.

## 6. Transactional outbox

OutboxPublisher juga menolak pemanggilan di luar transaction. Event mempunyai nama dan versi, aggregate reference, occurred/available timestamp, payload aman, serta optional deduplication key yang disimpan sebagai hash.

DispatchOutboxCommand hanya mengantrekan pesan due. ProcessOutboxMessage mempunyai retry terkontrol. OutboxProcessor memakai row lock dan receipt unik per consumer agar pesan yang sudah selesai tidak diproses ulang. Consumer untuk efek eksternal wajib memakai messageId sebagai idempotency key karena crash setelah external side effect tidak dapat diselesaikan oleh transaction database lokal.

Pesan yang gagal lima kali diberi failed_at dan memerlukan recovery operasional; exception message mentah tidak disimpan.

## 7. Idempotency dan concurrency

IdempotencyService menjalankan callback dan perubahan idempotency dalam transaction yang sama. Key dan fingerprint disimpan sebagai SHA-256; response replay disimpan terenkripsi. Key yang sama dengan fingerprint berbeda ditolak.

Model mutable yang memakai optimistic concurrency mempunyai lock_version mulai dari 1. OptimisticLock hanya mengubah row bila versi yang diharapkan masih sama, kemudian menaikkan versi. Konflik menghasilkan HTTP 409 yang aman.

StateTransitionGuard adalah guard domain murni. Daftar transition tetap dimiliki modul/use case; shared layer tidak mengarang workflow bisnis.

## 8. Object storage metadata

ObjectMetadataStore hanya meregistrasikan metadata pending: disk/key, nama asli, MIME, ukuran, checksum, klasifikasi, owner/source reference, dan metadata aman. Step 04 tidak mengunggah evidence dan tidak membuat URL publik. Upload, malware scan, authorization, dan signed URL dilanjutkan pada Step 08.

## 9. Error taxonomy

SafeDomainException membawa code stabil, pesan pengguna yang aman, status HTTP, dan context yang sudah dibatasi. JSON response tidak memuat stack trace atau exception message internal. ExceptionMapper memisahkan concurrency, idempotency, transition, not-found, forbidden, validation, dan unexpected error.

## 10. Pola penggunaan modul berikutnya

1. Masuk melalui application service dan authorization entry check.
2. Jalankan use case melalui TransactionBoundary.
3. Lock atau validasi aggregate dan expected lock_version.
4. Terapkan state transition/domain rule.
5. Simpan perubahan domain.
6. Tulis AuditEntry dan OutboxEvent dalam transaction yang sama.
7. Commit; worker memproses side effect setelah commit.
8. Gunakan idempotency key pada submit/import/integration yang dapat diulang.

Jangan menaruh workflow pada controller, Livewire component, observer saja, atau application log.
