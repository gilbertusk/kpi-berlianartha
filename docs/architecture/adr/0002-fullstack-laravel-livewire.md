# ADR-0002: Full-stack Laravel dengan Livewire

- Status: Accepted
- Date: 2026-10-12

## Context

Produk adalah aplikasi internal dengan form, table, approval, dashboard, export, dan role-scoped workflow. Project Owner telah memilih full-stack Laravel.

## Decision

Gunakan Laravel 13, PHP 8.4, Blade, Livewire, Alpine.js, Tailwind CSS, dan Vite. Frontend dan backend berada pada satu aplikasi. REST/API dibuat hanya untuk integrasi atau client yang benar-benar membutuhkan contract terpisah.

## Consequences

- Session, validation, policy, form error, dan deployment lebih sederhana.
- UI interaktif tetap dapat dibuat dengan Livewire/Alpine dan JavaScript chart library.
- Livewire action wajib melakukan authorization server-side.
- Komponen yang sangat kompleks boleh memakai JavaScript terisolasi tanpa mengubah aplikasi menjadi SPA terpisah.

## Rejected

- Next.js + Laravel API: dua deployment dan contract overhead belum diperlukan.
- Blade tanpa Livewire: terlalu banyak custom request/JavaScript untuk workflow interaktif.

