# Changelog

All notable changes to this extension are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - Initial release

### Added

- **Manage Redirects grid** at *Panth Infotech > Manage Redirects*. Literal,
  regex and maintenance (503) match types with configurable priority and
  optional Active-From / Active-Until scheduling. Hit counter with atomic
  increments. CSV import (with loop detection) and CSV export.
- **Auto-redirect observers** for `catalog_product_delete`,
  `catalog_category_delete` and `cms_page_delete`. When an entity is
  deleted, a 301 is inserted pointing to the parent category / homepage /
  custom URL (admin-configurable per store).
- **404 logger** at *Panth Infotech > 404 Log*, with per-IP rate limiting
  (APCu when available, per-worker fallback when not) so a flood of 404s
  can never saturate the log table. Stores request path, referer and user
  agent, and escapes every cell when rendered in the admin grid.
- **404 cluster cron** (`panth_redirects_404_cluster`) that aggregates the
  last seven days of 404 hits by normalised pattern and writes the top 500
  to `panth_seo_404_cluster` for admin review.
- **Redirect cleanup cron** (`panth_redirects_redirect_cleanup`) that
  deletes expired scheduled redirects and auto-generated rows older than
  `expiry_days` that have never been hit. Admin-curated rows are never
  removed.
- **CLI import** via `bin/magento panth:redirects:import <file> [--dry-run]`.
- **Homepage / lowercase / trailing-slash redirect plugins**, frontend-only,
  routed through a central `RedirectGuard` that drops any request that is
  AJAX, non-GET, admin, API or on a known asset prefix.

### Notes

- Extracted from `Panth_AdvancedSEO` 1.0.x. Config paths moved from
  `panth_seo/auto_redirect/*` and `panth_seo/advanced/log_404` to
  `panth_redirects/general/*` and `panth_redirects/logging/*`.
- Table names (`panth_seo_redirect`, `panth_seo_404_log`,
  `panth_seo_404_cluster`) are preserved so existing data carries over on
  upgrade.
