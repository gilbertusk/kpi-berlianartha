# Prompt Step 04 — Fondasi Data, Audit, dan Transaksi

Anda bertindak sebagai Senior Backend/Data Engineer. Implementasikan fondasi data lintas domain sebelum membuat fitur KPI.

## Prasyarat

Step 02 dan 03 harus lulus. Baca ADR identifier, decimal, timezone, audit, outbox, migration policy, dan `PROJECT_PROGRESS.md`.

## Pekerjaan

1. Implementasikan base conventions untuk ID, timestamp, soft-delete hanya bila sah, effective date, actor, reason, source reference, dan version/concurrency token.
2. Simpan timestamp secara konsisten; presentasikan ke pengguna dengan timezone Asia/Jakarta. Tambahkan boundary test terhadap pergantian hari/periode.
3. Implementasikan business audit trail yang append-only secara logis. Audit minimal mencatat actor, role/scope saat aksi, action, entity, entity ID, before/after yang aman, reason, timestamp, request/correlation ID, dan sumber.
4. Pastikan field rahasia, password, token, dan isi file sensitif tidak masuk audit/log.
5. Implementasikan transactional outbox untuk notification/integration event penting. Worker harus idempotent dan mendukung retry terkontrol.
6. Buat reusable transaction boundary, state-transition guard, idempotency key, dan optimistic concurrency mechanism sesuai ADR.
7. Buat abstraction object storage metadata tanpa mengunggah evidence nyata pada step ini.
8. Buat error taxonomy dan mapping ke pesan pengguna/log. Jangan menampilkan stack trace di production.
9. Tambahkan migration, factory, test fixture, unit test, transaction rollback test, idempotency test, concurrency test, serta audit immutability test.
10. Dokumentasikan data convention dan cara modul berikutnya menggunakannya.
11. Perbarui `PROJECT_PROGRESS.md`.

## Guardrail

- Application log bukan audit trail.
- Audit tidak boleh bergantung hanya pada observer yang mudah dilewati bulk update.
- Jangan memakai floating point untuk skor, persen, kuantitas presisi, atau nilai finansial.
- Jangan membuat generic repository/service abstraction tanpa use case konkret.
- Jangan mengaktifkan database extension tanpa alasan, compatibility check, dan ADR.

## Acceptance criteria

- Transaksi yang gagal tidak meninggalkan state parsial, audit palsu, atau outbox event yatim.
- Retry dengan idempotency key tidak menggandakan perubahan.
- Concurrent update menghasilkan penolakan yang jelas atau resolusi yang terdokumentasi.
- Audit dapat ditelusuri tetapi tidak mengekspos secret.
- Seluruh migration dapat dijalankan pada database kosong dan test database.

## Laporan akhir

Laporkan schema/fondasi yang dibuat, contoh event dan audit, hasil test transaksi/concurrency, risiko, serta status `PASS/PARTIAL/BLOCKED`.
