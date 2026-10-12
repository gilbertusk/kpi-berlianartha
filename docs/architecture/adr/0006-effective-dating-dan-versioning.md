# ADR-0006: Effective Dating dan Versioning

- Status: Accepted for baseline
- Date: 2026-10-12

## Decision

Organization assignment, hierarchy, KPI definition, template, target revision, approval route, dan aturan yang berubah sepanjang waktu mempunyai effective period/version. Period assignment membuat snapshot referensi yang diperlukan untuk mempertahankan histori.

Published definition dan record final tidak diubah in-place. Perubahan membuat versi baru dengan actor, reason, approval, dan effective date.

## Consequences

- Scorecard lama dapat direproduksi.
- Query current state harus eksplisit terhadap tanggal.
- Overlapping effective range dicegah melalui validation/constraint yang sesuai.
- Perubahan organisasi tidak mengubah approver atau scope periode historis.

