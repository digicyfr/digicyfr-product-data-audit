# Product Data Audit — 0.1.0 release notes

## What this release does

Merchant-selected, read-only WooCommerce product-field checks; bounded manual scans; private findings and guarded CSV; English and Polish admin text.

## Requirements and tested stack

Requires WordPress 7.1+, PHP 8.3+ and InnoDB. WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13, WooCommerce 8.9.0 and 11.2.2 with HPOS on and off passed integration and GitHub release-branch CI. The exact GitHub ZIP passed a post-tag Chromium scan, current report, guarded CSV, rule-change invalidation and 390px check on WooCommerce 11.2.2/HPOS. Manual screen-reader and real-merchant validation are outstanding.

## Known limits

Rules are off by default. Only supported simple and variable parents and published variations are inspected. A finding is an empty selected field, not a sales, tax, feed, SEO or quality verdict. The scan is a best-effort high-water pass, not an immutable snapshot; rescan after content or catalog changes. See [README.md](README.md) for full scope and lifecycle details.

**Release ZIP SHA-256:** `8268f9d137fe5dfe0574110b3d8441049a5e22d7528df08aec5fe0bfc541f25e`. Verify downloaded bytes against the sidecar before installing.

## Install and update

Download the versioned ZIP and SHA-256 sidecar from the GitHub release for this repository. Verify the checksum, then use **Plugins → Add New → Upload Plugin** in WordPress. For updates, back up the site and database, upload the newer ZIP, and verify the plugin version and expected behavior. Version 0.1.0 has no automatic update endpoint; the `Update URI` header only prevents unrelated directory updates. Deactivation retains private scan data; uninstall deletes plugin-owned data.

## Source and support

Source: https://github.com/digicyfr/digicyfr-product-data-audit. Report ordinary bugs through Issues; use [SECURITY.md](SECURITY.md) for private vulnerability reports. The GPL-2.0-or-later license is in [LICENSE](LICENSE). Detailed local QA, including open gates and the exact ZIP hash, is in [docs/QA-0.1.0.md](docs/QA-0.1.0.md).

This release candidate targets Digicyfr/GitHub self-hosted distribution. WordPress.org Plugin Check reports an updater-policy error for the self-hosted `Update URI`, so this package is not a WordPress.org submission.
