=== Digicyfr Product Data Audit ===
Contributors: digicyfr
Tags: woocommerce, products, audit, catalog
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Merchant-selected, read-only WooCommerce product field checklist with bounded manual scans and private CSV reports.

== Description ==

Choose field rules for published simple products, variable parents and published variations. Scan in bounded manual batches. See missing selected fields and the parent product edit location. Incomplete or old-policy scans are never exported as current. No product edits, remote calls, order/customer access, score or storefront output.

== Installation ==

1. Activate WooCommerce 8.9.0 or newer.
2. Upload this ZIP in Plugins > Add New > Upload Plugin.
3. Open WooCommerce > Product Data Audit, select rules, save, start and continue a scan.

== Frequently Asked Questions ==

= Does it change products? =

No. It only reads product fields and writes its own private scan tables.

= Does a finding mean the store is broken? =

No. A finding means a selected field was empty under your rule policy at scan time.

= What happens on uninstall? =

All plugin rules, run state, ledger and findings are deleted. Deactivation retains them.

== Changelog ==

= 0.1.0 =
* Initial local release candidate.
