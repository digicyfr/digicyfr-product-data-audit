# QA — 0.1.0

## Current GitHub release artifact (2026-11-05)

- ZIP: `dist/digicyfr-product-data-audit-0.1.0.zip`
- SHA-256: `8268f9d137fe5dfe0574110b3d8441049a5e22d7528df08aec5fe0bfc541f25e`
- The new repository snapshot adds a per-plugin GitHub `Plugin URI`, updates packaged README instructions, and adds standalone build/verification tools, Docker harness, CI, contribution/security policies and release notes outside the ZIP. Scanner, storage, authorization and admin behavior are unchanged.
- Both standalone and workspace `verify_zip.py --against-source` passed on this ZIP; its `.sha256` sidecar passed. PHP and JavaScript syntax checks passed.
- Fresh disposable WordPress 7.1/PHP 8.3.33 exact-ZIP install, activation/deactivation/reactivation, and catalog integration passed with WooCommerce 8.9.0/legacy storage and 11.2.2/HPOS. The intentional missing-table fault printed a WordPress database error before the test passed. GitHub [release-branch CI](https://github.com/digicyfr/digicyfr-product-data-audit/actions/runs/41985999725) passed the 8.9.0 and 11.2.2 × legacy/HPOS matrix. Manual screen-reader and real-merchant validation remain outstanding.
- The older browser and Plugin Check results below were collected on the earlier candidate hash; the current GitHub ZIP received the post-tag browser check recorded below. Plugin Check was not rerun on the final packaged README/header revision.

## Earlier candidate evidence (ZIP SHA-256 8eb8f9022db9b675383a46dba825508a08af807ed17409c832c46402621561e2)

## Exact candidate

- ZIP: `dist/digicyfr-product-data-audit-0.1.0.zip`
- SHA-256: `8eb8f9022db9b675383a46dba825508a08af807ed17409c832c46402621561e2`
- `tools/verify_zip.py --against-source`: all 14 allowlisted release files match source byte for byte. Tests, release manifest, and development docs are excluded.
- Tested stack: WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13, WooCommerce 8.9.0 and 11.2.2, each with HPOS authoritative on and off. These are tested versions, not a claim about every newer version.

## Automated gates

- PHP lint, JavaScript syntax check, and WordPress Coding Standards on runtime PHP: passed; WPCS reported zero errors and zero warnings.
- Fresh exact-ZIP WordPress installs, activation, deactivation, and reactivation: passed in all four WooCommerce/version and HPOS combinations. The integration setup verified HPOS authoritative state and disabled order-table synchronization before creating products.
- Real WordPress exact-ZIP integration: passed in all four combinations. A synthetic catalog covers 36 published supported parents, a 51-child variable parent plus five other published variations, 26 filler simple products, private variations, and registered grouped, external, and custom unsupported parent types. It checks every rule's applicability, own versus inherited variation fields, zero price/stock, null-stock evaluator behavior, HTML-only description presence, two bounded child pages, ledger replacement after a simulated interrupted write, 50-row report paging, SKU and parent edit links, CSV formula protection and permission-filtered URLs, role/nonce/method denials at the direct admin-post endpoint, policy change invalidation, incomplete-run recovery, an injected SQL failure with zero child ledger rows, stale-source labels, state pointer repair, deactivation retention, and uninstall erasure without changing products.
- Plugin Check on the exact ZIP completed with one `plugin_updater_detected` ERROR for the intentional Digicyfr `Update URI`, which WordPress.org does not allow; this is a self-hosted manual-update ZIP. It reported one `load_plugin_textdomainFound` WARNING for the bundled Polish catalog, plus 14 `DirectDB.UnescapedDBParameter` warnings at SQL using plugin-owned table names formed only from `$wpdb->prefix` and fixed suffixes. The queries use placeholders for data values. The WordPress.org-specific findings and static identifier warnings remain visible; this is not a WordPress.org submission or a clean Plugin Check result.

## Browser and accessibility

- Root's Chromium check on this exact ZIP hash passed: keyboard selection of suggested rules, seven bounded manual batches to Complete, current findings, CSV download with formula protection, old-policy invalidation after rule changes, and no page overflow at a 390px viewport. The installed ZIP inside the disposable container matched the SHA-256 above.
- Controls use native buttons, links, labels, notices, and keyboard focus handling. A screen-reader session has not been run.

## Post-tag browser check on the exact GitHub ZIP

On 2026-11-05, the public `v0.1.0` ZIP and sidecar were downloaded from GitHub and passed `sha256sum --check` at the current hash above. The ZIP installed, activated, deactivated and reactivated on a new disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site with WooCommerce 11.2.2 and HPOS authoritative. Chromium 156.0.8078.4 exercised the synthetic catalog fixture:

- Accepted the optional suggested rules, started a scan and completed it in seven bounded batches. The current report showed 69 selected-field findings, including the known blank physical product. [Current report](evidence/0.1.0/current-report.png).
- Downloaded the guarded CSV and found the expected blank product and formula-shaped synthetic product name. Changed one rule; the complete report became historical and the current CSV action disappeared. [Historical report](evidence/0.1.0/historical-report.png).
- At 390px, the document width equalled the viewport width. A fresh targeted mobile run completed without page errors. [390px view](evidence/0.1.0/390px-view.png).

Only synthetic catalog data was used. The disposable containers and volumes were removed. A human screen-reader pass and merchant-approved staging catalog check remain open; the browser run does not establish behavior on the merchant's theme or extensions.

## Scope and release limits

- All rules are merchant selected and off by default. A complete report is a best-effort snapshot, not proof that the catalog stayed unchanged. Unsupported registered product types are counted, not inspected; unregistered types are outside that count. Findings are never auto-fixed.
- A scan is manual and bounded. The integration fixture exercises retry replacement and sequential resumes; it does not reproduce two simultaneous HTTP Continue requests. Runtime concurrency uses an InnoDB run-row lock and a database advisory lock for Start.
- The plugin does not access orders or customers, call a remote service, or provide a storefront, feed, SEO, shipping, tax, or sales verdict. Reports may contain product names and SKUs; only users with `manage_woocommerce` can access them.
- A public GitHub stable release exists for self-hosted installation. No digicyfr.com production publication, WordPress.org submission or live customer-site installation was performed.

## Automated accessibility audit on the public ZIP — 2026-11-05

The exact public ZIP and SHA sidecar above were verified again and installed on a fresh disposable WordPress 7.1 / PHP 8.3.33 / WooCommerce 11.2.2 site with synthetic catalog products. Chromium 156.0.8078.4 and axe-core 4.10.3 ran WCAG 2/2.1/2.2 A/AA rule tags on the initial rules screen and a completed 71-finding report. Both plugin regions and the full completed report had **zero axe violations**, matching the WordPress Dashboard baseline. At 390px the report document width was 390px. Keyboard Tab moved between adjacent rule checkboxes, whose names were present in Chromium's accessibility tree. See [machine-readable audit evidence](evidence/0.1.0/axe-2026-11-05.json).

This automated check did not find a plugin-owned defect, so the `v0.1.0` ZIP, tag, and release branch were not changed. A human screen-reader pass and representative merchant catalog review remain open.

## Stable self-hosted release decision — 2026-11-05

The exact public ZIP passed the published SHA-256 sidecar check (`8268f9d137fe5dfe0574110b3d8441049a5e22d7528df08aec5fe0bfc541f25e`) and source-byte comparison. The protected `release/0.1.0` branch and annotated `v0.1.0` tag point to the same frozen release commit, and their CI passed. Main-branch CI and the automated browser and axe checks passed before promotion. This supports a stable self-hosted GitHub release. The ZIP, tag, release branch and attached assets were preserved. Human screen-reader testing and review on a representative merchant catalog remain unperformed; axe and browser checks do not establish WCAG conformance.
