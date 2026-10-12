# ADR-0008: Identifier dan Numeric Precision

- Status: Proposed untuk dikonfirmasi saat schema design
- Date: 2026-10-12

## Decision

Gunakan identifier internal yang konsisten dan identifier eksternal/source reference terpisah. Default Laravel bigint dapat digunakan untuk aggregate internal; public/file reference yang tidak boleh mudah ditebak menggunakan UUID/ULID terpisah bila diperlukan. Jangan mencampur strategi tanpa alasan domain.

Skor, persentase, kuantitas presisi, dan nilai finansial menggunakan PostgreSQL `numeric`/decimal dengan scale eksplisit serta decimal arithmetic pada aplikasi. Floating point dilarang untuk hasil resmi.

## Consequences

- Schema Step 04 harus mendokumentasikan precision per field dan urutan rounding.
- Source system ID tidak dijadikan primary key internal tanpa contract stabil.
- Public URL/file access tetap membutuhkan authorization; identifier acak bukan kontrol akses.

