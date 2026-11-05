<?php
/**
 * Plugin Name: Digicyfr Product Data Audit
 * Plugin URI: https://github.com/digicyfr/digicyfr-product-data-audit
 * Description: Merchant-selected, read-only WooCommerce catalog field checks with a resumable private report.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9.0
 * Author: Digicyfr
 * Author URI: https://digicyfr.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-product-data-audit
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-product-data-audit/
 *
 * @package Digicyfr_Product_Data_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGICYFR_PRODUCT_DATA_AUDIT_VERSION', '0.1.0' );
define( 'DIGICYFR_PRODUCT_DATA_AUDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGICYFR_PRODUCT_DATA_AUDIT_URL', plugin_dir_url( __FILE__ ) );
require_once DIGICYFR_PRODUCT_DATA_AUDIT_PATH . 'includes/class-dpda-rules.php';
require_once DIGICYFR_PRODUCT_DATA_AUDIT_PATH . 'includes/class-dpda-storage.php';
require_once DIGICYFR_PRODUCT_DATA_AUDIT_PATH . 'includes/class-dpda-scan.php';
require_once DIGICYFR_PRODUCT_DATA_AUDIT_PATH . 'includes/class-dpda-admin.php';

register_activation_hook( __FILE__, array( 'DPDA_Storage', 'install' ) );
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'digicyfr-product-data-audit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
DPDA_Admin::boot();
