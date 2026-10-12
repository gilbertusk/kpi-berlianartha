# KPI PT Berlian Artha Label

Repository untuk pengembangan Sistem Manajemen KPI PT Berlian Artha Label.

## Status saat ini

Project berada pada tahap perencanaan dan validasi kebutuhan. Skeleton Laravel belum dibuat.

Artefak yang tersedia:

- PRD: `output/PRD_Sistem_KPI_PT_Berlian_Artha_Label.docx`
- Sumber pembuat PRD: `scripts/build_prd.py`
- Paket prompt implementasi: `prompts/ai-development/README.md`

## Stack yang disepakati

- Laravel 13 dan PHP 8.4
- Blade, Livewire, Alpine.js, dan Tailwind CSS
- PostgreSQL 18
- Redis dan Laravel Horizon
- S3-compatible object storage
- Pest dan Playwright
- Docker, Docker Compose, Nginx, dan PHP-FPM
- GitHub Actions

## Alur implementasi

Jalankan prompt pada `prompts/ai-development/` satu per satu sesuai nomor. Step berikutnya hanya boleh dimulai setelah exit criteria step aktif diperiksa dan statusnya dicatat dalam `PROJECT_PROGRESS.md`.

Setiap step yang menghasilkan perubahan harus mempunyai commit terpisah dan didorong ke remote GitHub. Detail aturan terdapat pada `AGENTS.md`.

## Batasan penting

PRD masih memuat keputusan bisnis terbuka. Bobot, formula, target, rating, approver, SLA, integrasi, dan aturan kompensasi tidak boleh diasumsikan oleh developer atau AI.

