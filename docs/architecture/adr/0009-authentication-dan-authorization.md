# ADR-0009: Authentication Adapter dan Scoped Authorization

- Status: Proposed, identity provider masih OPEN
- Date: 2026-10-12

## Decision

Pisahkan authentication dari authorization domain. SSO/MFA menggunakan provider perusahaan bila disetujui; local authentication hanya untuk development atau fallback yang dikontrol. Authorization menggabungkan role, organization scope, effective assignment, workflow state, ownership, delegation, dan segregation of duties.

Gunakan Laravel Policies/Gates pada server untuk route, Livewire action, download, export, job, dan transition. Menyembunyikan tombol bukan authorization.

## Consequences

- Penggantian identity provider tidak mengubah role/scope domain.
- Setiap fitur memerlukan positive dan negative authorization tests.
- Delegation mempunyai waktu, alasan, scope, actor, dan audit.
- System Admin tidak memperoleh hak mengubah KPI hanya karena memiliki akses teknis.

