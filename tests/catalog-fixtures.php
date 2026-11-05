<?php
/** Synthetic WooCommerce catalog for Digicyfr product-audit ZIP verification. */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';

if (!class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce is not active');
}
wp_set_current_user(1);
$existing = get_option('digicyfr_pda_fixture_manifest');
if (is_array($existing)) {
    echo wp_json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function pda_add_simple(string $name, array $props = array()): int {
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_status('publish');
    foreach ($props as $method => $value) {
        $setter = 'set_' . $method;
        $p->$setter($value);
    }
    $id = $p->save();
    if (!$id) {
        throw new RuntimeException('Failed to create ' . $name);
    }
    return (int) $id;
}

function pda_add_variable(string $name, string $attribute_name, array $options, array $props = array()): int {
    $attribute = new WC_Product_Attribute();
    $attribute->set_id(0);
    $attribute->set_name($attribute_name);
    $attribute->set_options($options);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $p = new WC_Product_Variable();
    $p->set_name($name);
    $p->set_status('publish');
    $p->set_attributes(array($attribute));
    foreach ($props as $method => $value) {
        $setter = 'set_' . $method;
        $p->$setter($value);
    }
    $id = $p->save();
    if (!$id) {
        throw new RuntimeException('Failed to create variable ' . $name);
    }
    return (int) $id;
}

function pda_add_variation(int $parent_id, string $option, array $props = array()): int {
    $v = new WC_Product_Variation();
    $v->set_parent_id($parent_id);
    $v->set_status($props['status'] ?? 'publish');
    unset($props['status']);
    $v->set_attributes(array('size' => $option));
    foreach ($props as $method => $value) {
        $setter = 'set_' . $method;
        $v->$setter($value);
    }
    $id = $v->save();
    if (!$id) {
        throw new RuntimeException('Failed to create variation ' . $option);
    }
    return (int) $id;
}

$upload = wp_upload_dir();
$pixel_path = trailingslashit($upload['path']) . 'pda-fixture-pixel.png';
file_put_contents($pixel_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lK8AAAAASUVORK5CYII=', true));
$image_id = wp_insert_attachment(array(
    'post_mime_type' => 'image/png',
    'post_title' => 'PDA synthetic pixel',
    'post_status' => 'inherit',
), $pixel_path);
if (is_wp_error($image_id) || !$image_id) {
    throw new RuntimeException('Failed to create fixture image');
}

$manifest = array('woo_version' => WC()->version, 'image_attachment_id' => (int) $image_id);
$manifest['simple_blank'] = pda_add_simple('Simple physical blank fields', array(
    'regular_price' => '', 'price' => '', 'manage_stock' => false,
));
$manifest['simple_zero_complete'] = pda_add_simple('Simple free complete', array(
    'regular_price' => '0', 'price' => '0', 'sku' => 'FREE-0',
    'description' => 'Full description', 'short_description' => 'Short description',
    'image_id' => (int) $image_id, 'weight' => '1.5',
    'length' => '10', 'width' => '11', 'height' => '12',
    'manage_stock' => true, 'stock_quantity' => 0,
));
$manifest['simple_virtual'] = pda_add_simple('Simple virtual blank shipping', array(
    'regular_price' => '10', 'price' => '10', 'virtual' => true,
));
$manifest['simple_downloadable_physical_missing_file'] = pda_add_simple('Downloadable physical missing file', array(
    'regular_price' => '5', 'price' => '5', 'downloadable' => true,
));
$manifest['simple_stock_null'] = pda_add_simple('Managed stock null', array(
    'regular_price' => '3', 'price' => '3', 'manage_stock' => true, 'stock_quantity' => null,
));
$download_path = trailingslashit($upload['path']) . 'pda-download.txt';
file_put_contents($download_path, "Synthetic test download\n");
$download = new WC_Product_Download();
$download->set_id('synthetic-file');
$download->set_name('Synthetic file');
$download->set_file($download_path);
$manifest['simple_downloadable_with_file'] = pda_add_simple('Downloadable with file', array(
    'regular_price' => '7', 'price' => '7', 'downloadable' => true,
    'downloads' => array($download),
));
$manifest['simple_formula_name'] = pda_add_simple('=HYPERLINK("https://example.invalid","click")', array(
    'regular_price' => '2', 'price' => '2',
));

$manifest['variable_parent'] = pda_add_variable('Variable inheritance parent', 'Size', array('Small', 'Medium', 'Large', 'Private', 'Virtual', 'Download'), array(
    'sku' => 'PARENT-SKU', 'weight' => '2.5', 'length' => '20', 'width' => '21', 'height' => '22',
    'manage_stock' => true, 'stock_quantity' => 5, 'image_id' => (int) $image_id,
));
$manifest['variation_inherited'] = pda_add_variation($manifest['variable_parent'], 'Small', array(
    'regular_price' => '12', 'price' => '12',
));
$manifest['variation_blank_price'] = pda_add_variation($manifest['variable_parent'], 'Medium');
$manifest['variation_own_sku'] = pda_add_variation($manifest['variable_parent'], 'Large', array(
    'regular_price' => '15', 'price' => '15', 'sku' => 'CHILD-SKU',
));
$manifest['variation_private'] = pda_add_variation($manifest['variable_parent'], 'Private', array(
    'regular_price' => '20', 'price' => '20', 'status' => 'private',
));
$manifest['variation_virtual'] = pda_add_variation($manifest['variable_parent'], 'Virtual', array(
    'regular_price' => '9', 'price' => '9', 'virtual' => true,
));
$manifest['variation_downloadable_missing_file'] = pda_add_variation($manifest['variable_parent'], 'Download', array(
    'regular_price' => '8', 'price' => '8', 'downloadable' => true,
));

$manifest['variable_no_published_children'] = pda_add_variable('Variable no published child', 'Size', array('Private'));
$manifest['only_private_variation'] = pda_add_variation($manifest['variable_no_published_children'], 'Private', array(
    'regular_price' => '1', 'price' => '1', 'status' => 'private',
));

$options = array();
for ($n = 1; $n <= 51; $n++) {
    $options[] = sprintf('V%02d', $n);
}
$manifest['variable_51_parent'] = pda_add_variable('Variable fifty-one children', 'Size', $options);
$manifest['variation_51_ids'] = array();
foreach ($options as $option) {
    $manifest['variation_51_ids'][] = pda_add_variation($manifest['variable_51_parent'], $option, array(
        'regular_price' => '1', 'price' => '1', 'sku' => 'PAG-' . $option,
    ));
}

$manifest['filler_simple_ids'] = array();
for ($n = 1; $n <= 26; $n++) {
    $manifest['filler_simple_ids'][] = pda_add_simple(sprintf('Filler simple %02d', $n), array(
        'regular_price' => '1', 'price' => '1', 'sku' => sprintf('FILL-%02d', $n),
    ));
}

$external = new WC_Product_External();
$external->set_name('Unsupported external');
$external->set_status('publish');
$external->set_product_url('https://example.invalid/');
$external->set_button_text('Visit');
$manifest['unsupported_external'] = (int) $external->save();
$grouped = new WC_Product_Grouped();
$grouped->set_name('Unsupported grouped');
$grouped->set_status('publish');
$manifest['unsupported_grouped'] = (int) $grouped->save();

update_option('digicyfr_pda_fixture_manifest', $manifest, false);

$parents = wc_get_products(array(
    'status' => 'publish', 'type' => array('simple', 'variable'),
    'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids',
    'limit' => 25, 'page' => 1, 'paginate' => true,
));
$children = wc_get_products(array(
    'status' => 'publish', 'type' => 'variation',
    'parent' => $manifest['variable_51_parent'], 'orderby' => 'ID', 'order' => 'ASC',
    'return' => 'ids', 'limit' => 50, 'page' => 1, 'paginate' => true,
));
$inherited = wc_get_product($manifest['variation_inherited']);
$manifest['verification'] = array(
    'supported_parent_total' => $parents->total,
    'supported_parent_pages_25' => $parents->max_num_pages,
    'published_paginated_child_total' => $children->total,
    'published_paginated_child_pages_50' => $children->max_num_pages,
    'inherited_view_sku' => $inherited->get_sku('view'),
    'inherited_own_sku' => $inherited->get_sku('edit'),
    'inherited_view_weight' => $inherited->get_weight('view'),
    'inherited_stock_mode' => $inherited->get_manage_stock('view'),
    'inherited_stock_quantity' => $inherited->get_stock_quantity('view'),
    'private_variation_status' => wc_get_product($manifest['variation_private'])->get_status(),
    'simple_zero_price' => wc_get_product($manifest['simple_zero_complete'])->get_price(),
    'simple_stock_null' => wc_get_product($manifest['simple_stock_null'])->get_stock_quantity(),
);
update_option('digicyfr_pda_fixture_manifest', $manifest, false);
$manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents('/tmp/digicyfr-pda-fixtures.json', $manifest_json . PHP_EOL);
echo $manifest_json . PHP_EOL;
