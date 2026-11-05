# Digicyfr Product Data Audit

Free GPL WordPress plugin for a **merchant-selected, read-only** check of empty WooCommerce product fields. Choose each rule yourself, scan the catalog in bounded manual batches, review one row per missing selected field, and download a guarded CSV. The plugin never edits products, reads orders or customers, sends data away, scores the catalog, or claims a feed, SEO, tax, shipping, or sales verdict.

## Website guides and stable release

- [Product Data Audit for WooCommerce: English overview and installation](https://www.digicyfr.com/en/wordpress-plugins/digicyfr-product-data-audit)
- [Przegląd danych produktów WooCommerce: opis i instalacja po polsku](https://www.digicyfr.com/pl/wordpress-plugins/digicyfr-product-data-audit)
- [Stable v0.1.0 release, ZIP and checksum](https://github.com/digicyfr/digicyfr-product-data-audit/releases/tag/v0.1.0)

<a href="https://www.digicyfr.com/en/wordpress-plugins/digicyfr-product-data-audit"><img src="https://www.digicyfr.com/images/wordpress-plugins/digicyfr-product-data-audit.png" alt="WooCommerce product data audit report with selected findings" width="280"></a>

_Example screenshot from a disposable test site._

## Install and use

Upload the versioned ZIP at **Plugins → Add New → Upload Plugin** with WooCommerce active. The plugin declares WordPress 7.1+, PHP 8.3+, and WooCommerce 8.9.0+; see the QA record for exact tested versions. A dependency notice appears if WooCommerce is inactive or below the tested floor.

1. In **WooCommerce → Product Data Audit**, choose the field rules you expect and save. All rules start off. You can explicitly accept the suggested starter set, then review it. Selection is stored in `dpda_rules_v1` and each scan snapshots it.
2. Select **Start a fresh scan**, then **Continue one batch** until Complete. A request processes at most 25 published simple/variable parents or 50 published variations of one variable parent. Scanning is manual, resumable, and serialized. A scan that detects changed totals, changed rules, or query/storage errors is Incomplete; start afresh. No cron or background job runs.
3. Read the private report. It shows the exact selected missing field, why the product qualified, source freshness, variation ID/SKU and parent editor link when permitted. The report is an as-scanned, best-effort snapshot: equal totals do not prove an unchanged catalog. Unsupported registered published parent types are counted, not inspected. Unknown/unregistered types are outside that count.
4. Export only the active Complete report under the current selected rules. CSV columns are `scan_finished_at_utc,parent_id,subject_id,product_type,product_name,sku,rule_key,reason,edit_url`. It rejects more than 20,000 findings rather than truncating. Every text cell is protected against spreadsheet formulas; edit URLs are blank unless you can edit that product.

## Rule semantics

Parent descriptions and main images are separate optional policies. A variable parent is never required to have a price. Variation images are never required; variations may inherit an image. A variation SKU rule checks its **own** SKU (`edit` context), while variation weight, dimensions, and managed stock use effective view-context values, including inheritance. Blank price is missing; `0` is valid. Weight and dimensions are skipped only for virtual products; downloadable physical products still qualify. Download files are required only when a selected rule applies to a downloadable product. Stock quantity is checked only when stock management is enabled; `0` is valid. “Published variations” counts status only and says nothing about storefront availability or purchasability. Extension-managed prices or fields may need their rules disabled.

## Privacy and lifecycle

Access to settings, scan, report and CSV requires `manage_woocommerce`; actions and export also require a nonce. Data is stored in three plugin-owned InnoDB tables: run state, processed-subject ledger and missing-field findings. A non-autoloaded option mirrors the latest scan state for recovery. Product names and SKUs are retained as report snapshots, not refreshed silently. No orders, customers, remote calls or front-end output are involved. Deactivation retains settings and reports; uninstall drops the three tables and deletes plugin options. Manual ZIP updates are used; the unique Digicyfr `Update URI` prevents an unrelated directory update. There is no automatic update endpoint.

## Development

```bash
find . -type f -name "*.php" -not -path "./dist/*" -print0 | xargs -0 -r -n1 php -l
find assets -type f -name "*.js" -print0 | xargs -0 -r -n1 node --check
python3 tools/build_plugin.py digicyfr-product-data-audit
python3 tools/verify_zip.py dist/digicyfr-product-data-audit-0.1.0.zip --slug digicyfr-product-data-audit --against-source
(cd dist && sha256sum --check digicyfr-product-data-audit-0.1.0.zip.sha256)
bash tests/run-wordpress-integration.sh dist/digicyfr-product-data-audit-0.1.0.zip 8.9.0 legacy
bash tests/run-wordpress-integration.sh dist/digicyfr-product-data-audit-0.1.0.zip 11.2.2 hpos
```

License: GPL-2.0-or-later; see [LICENSE](LICENSE).

The integration command requires Docker Compose and a working Docker daemon. It installs the built ZIP on a disposable WordPress site and removes its volumes afterward.

## Source, support and security

Source and ordinary bug reports: https://github.com/digicyfr/digicyfr-product-data-audit and its Issues tab. For sensitive reports, follow [SECURITY.md](SECURITY.md). Contributions are welcome under [CONTRIBUTING.md](CONTRIBUTING.md). The public release snapshot is recorded on `release/0.1.0`; `main` is for ongoing work. See [RELEASE_NOTES.md](RELEASE_NOTES.md) and [docs/QA-0.1.0.md](docs/QA-0.1.0.md) before distribution.
