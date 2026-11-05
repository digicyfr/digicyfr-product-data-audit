<?php
/** Set disposable test site's order storage before any order exists. */
declare(strict_types=1);
$_SERVER['HTTP_HOST']='127.0.0.1:'.getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME']='127.0.0.1';
$_SERVER['REQUEST_URI']='/';
require '/var/www/html/wp-load.php';
$mode = getenv('DIGICYFR_HPOS_MODE');
if (!in_array($mode, array('yes','no'), true)) { throw new RuntimeException('Invalid HPOS mode'); }
update_option('woocommerce_custom_orders_table_enabled', $mode, false);
update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no', false);
echo "SET HPOS $mode\n";
