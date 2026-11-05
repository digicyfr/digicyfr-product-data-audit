<?php
/** Exercise the exact installed ZIP against a synthetic WooCommerce catalog. */
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = getenv('DIGICYFR_TEST_PORT');
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function keys(WC_Product $p, array $selected, int $count=0): array { return DPDA_Rules::evaluate($p, DPDA_Rules::normalize($selected), $count); }

$plugin = 'digicyfr-product-data-audit/digicyfr-product-data-audit.php';
check(is_plugin_active($plugin) && '0.1.0' === DIGICYFR_PRODUCT_DATA_AUDIT_VERSION, 'exact ZIP installed and active');
check(DPDA_Scan::ready() && DPDA_Storage::transactional(), 'WooCommerce and InnoDB tables ready');
$expected_hpos = getenv('DIGICYFR_HPOS_MODE');
if (in_array($expected_hpos, array('yes','no'), true)) {
    $order_util = \Automattic\WooCommerce\Utilities\OrderUtil::class;
    check(class_exists($order_util) && ($order_util::custom_orders_table_usage_is_enabled() === ('yes' === $expected_hpos)) && 'no' === get_option('woocommerce_custom_orders_table_data_sync_enabled'), 'HPOS authoritative mode and sync-off verified');
}
$admin = get_user_by('login', 'digicyfr_test_admin');
check($admin instanceof WP_User, 'admin exists');
wp_set_current_user($admin->ID);
$polish_locale = static fn(): string => 'pl_PL';
add_filter('locale', $polish_locale);
check('Audyt danych produktów' === __( 'Product Data Audit', 'digicyfr-product-data-audit' ), 'Polish menu string loads automatically from bundled catalog');
remove_filter('locale', $polish_locale);
unload_textdomain('digicyfr-product-data-audit', true);
$fx = get_option('digicyfr_pda_fixture_manifest');
check(is_array($fx) && count($fx['variation_51_ids']) === 51 && count($fx['filler_simple_ids']) === 26, '51-child and 26-filler fixtures exist');
class DPDA_Test_Custom_Product extends WC_Product_Simple { public function get_type() { return 'custom_audit'; } }
$custom_types = static function(array $types): array { $types['custom_audit'] = 'Synthetic custom type'; return $types; };
$custom_class = static function(string $class, string $type): string { return 'custom_audit' === $type ? DPDA_Test_Custom_Product::class : $class; };
add_filter('product_type_selector', $custom_types);
add_filter('woocommerce_product_class', $custom_class, 10, 2);
$custom_id = (int) get_option('dpda_test_custom_id', 0);
if (!$custom_id) {
    $custom = new DPDA_Test_Custom_Product();
    $custom->set_name('Unsupported registered custom');
    $custom->set_status('publish');
    $custom_id = $custom->save();
    update_option('dpda_test_custom_id', $custom_id, false);
}
check($custom_id > 0 && wc_get_product($custom_id)->get_type() === 'custom_audit', 'registered custom product created via Woo CRUD');
check(0 === DPDA_Rules::count_selected(DPDA_Rules::selected()), 'all rules off by default');
check(is_wp_error(DPDA_Scan::start()), 'cannot scan empty selection');
$all = array_fill_keys(array_keys(DPDA_Rules::definitions()), true);
check(count($all) === 20, '20 independent policy cells');
check(in_array('simple_price', keys(wc_get_product($fx['simple_blank']), $all), true), 'blank simple price missing');
check(!in_array('simple_price', keys(wc_get_product($fx['simple_zero_complete']), $all), true), 'zero price valid');
check(!in_array('simple_stock', keys(wc_get_product($fx['simple_zero_complete']), $all), true), 'zero stock quantity valid');
check(!in_array('simple_stock', keys(wc_get_product($fx['simple_blank']), $all), true), 'stock disabled excludes quantity');
check(!in_array('simple_weight', keys(wc_get_product($fx['simple_virtual']), $all), true) && !in_array('simple_dimensions', keys(wc_get_product($fx['simple_virtual']), $all), true), 'virtual excludes shipping fields');
check(in_array('simple_download', keys(wc_get_product($fx['simple_downloadable_physical_missing_file']), $all), true) && in_array('simple_weight', keys(wc_get_product($fx['simple_downloadable_physical_missing_file']), $all), true), 'downloadable physical still qualifies for shipping and file');
check(!in_array('simple_download', keys(wc_get_product($fx['simple_downloadable_with_file']), $all), true), 'download file present');
check(!in_array('variation_weight', keys(wc_get_product($fx['variation_inherited']), $all), true) && !in_array('variation_dimensions', keys(wc_get_product($fx['variation_inherited']), $all), true) && !in_array('variation_stock', keys(wc_get_product($fx['variation_inherited']), $all), true), 'variation inherits weight dimensions and managed stock');
check(in_array('variation_own_sku', keys(wc_get_product($fx['variation_inherited']), $all), true) && !in_array('variation_own_sku', keys(wc_get_product($fx['variation_own_sku']), $all), true), 'own SKU ignores parent inheritance');
check(in_array('variation_price', keys(wc_get_product($fx['variation_blank_price']), $all), true), 'blank variation price missing');
check(!in_array('variation_weight', keys(wc_get_product($fx['variation_virtual']), $all), true) && in_array('variation_download', keys(wc_get_product($fx['variation_downloadable_missing_file']), $all), true), 'virtual and downloadable variation applicability');
check(in_array('variable_has_published_variation', keys(wc_get_product($fx['variable_no_published_children']), $all, 0), true) && !in_array('variable_has_published_variation', keys(wc_get_product($fx['variable_parent']), $all, 5), true), 'published child count rule');
check(!array_key_exists('variable_price', DPDA_Rules::definitions()) && !array_key_exists('variation_image', DPDA_Rules::definitions()), 'no variable-parent price or variation-image rule');
$fake = new WC_Product_Simple();
$fake->set_description('<img src="example.png" alt="">');
check(!in_array('simple_description', keys($fake, $all), true), 'nonempty HTML description counts as present');
$null_stock = new WC_Product_Simple();
$null_stock->set_manage_stock(true);
$force_null_stock = static function($value, $product) use ($null_stock) { return $product === $null_stock ? null : $value; };
add_filter('woocommerce_product_get_stock_quantity', $force_null_stock, 10, 2);
check(in_array('simple_stock', keys($null_stock, $all), true), 'null quantity evaluator flags when Woo object retains null');
remove_filter('woocommerce_product_get_stock_quantity', $force_null_stock, 10);

$parents = DPDA_Scan::parents(1);
$children = DPDA_Scan::children($fx['variable_51_parent'],1);
check(!is_wp_error($parents) && $parents['total'] >= 36 && $parents['pages'] >= 2 && count($parents['ids']) <= 25, 'bounded parent query');
check(!is_wp_error($children) && 51 === $children['total'] && 2 === $children['pages'] && count($children['ids']) === 50, 'bounded child query');
$unsupported_count = DPDA_Scan::unsupported_count();
check(is_int($unsupported_count) && $unsupported_count >= 3, 'registered published grouped, external, and custom counted unsupported');
update_option(DPDA_Rules::OPTION, DPDA_Rules::normalize($all), false);
$started = DPDA_Scan::start();
check(is_array($started) && 'queued' === $started['status'] && $unsupported_count === (int)$started['unsupported_total'], 'run snapshots rules and unsupported count');
$run_id = $started['run_id'];
check(is_wp_error(DPDA_Scan::start()), 'concurrent start prevented');

// Simulate an interrupted subject write before its page advances; the next batch must replace it, not duplicate it.
global $wpdb;
check(false !== $wpdb->query('START TRANSACTION'), 'test transaction starts');
$first = wc_get_product($parents['ids'][0]);
DPDA_Storage::replace_subject($run_id, $first, keys($first, $all));
check(false !== $wpdb->query('COMMIT'), 'test subject prewrite commits');
$seen_child_pages = array();
for ($i=0; $i<30; $i++) {
    $before = DPDA_Scan::get($run_id);
    if ('children' === $before['phase']) { $seen_child_pages[] = (int)$before['child_page']; }
    $next = DPDA_Scan::resume($run_id);
    check(!is_wp_error($next), 'bounded resume step '.$i.': '.(is_wp_error($next)?$next->get_error_code():'ok'));
    if ('complete' === $next['status']) { break; }
}
$complete = DPDA_Scan::get($run_id);
check('complete' === $complete['status'] && 'done' === $complete['phase'] && in_array(2,$seen_child_pages,true), 'scan completes after two 51-child pages');
$t = DPDA_Storage::tables();
$parent_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id=%s AND subject_type IN ('simple','variable')", $run_id));
$child_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id=%s AND subject_type='variation'", $run_id));
check($parent_count === (int)$complete['parent_total'] && 56 === $child_count, 'ledger counts exactly 36+ parents and 56 published children');
check(1 === (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id=%s AND subject_id=%d", $run_id, $first->get_id())), 'interrupted subject write replaced without duplication');
check(0 === (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id=%s AND subject_id=%d", $run_id, $fx['variation_private'])), 'private variation skipped');
check(0 === (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['findings']} WHERE run_id=%s AND subject_id=%d AND rule_key='simple_price'", $run_id, $fx['simple_zero_complete'])), 'zero price absent from findings');
check(1 === (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['findings']} WHERE run_id=%s AND subject_id=%d AND rule_key='variable_has_published_variation'", $run_id, $fx['variable_no_published_children'])), 'no-published-child finding');
$own_sku_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['findings']} WHERE run_id=%s AND subject_id=%d AND rule_key='variation_own_sku'", $run_id, $fx['variation_inherited']), ARRAY_A);
check(is_array($own_sku_row) && '' === $own_sku_row['sku'] && (int)$own_sku_row['parent_id'] === $fx['variable_parent'], 'variation finding stores own blank SKU and parent ID');
check(str_contains(DPDA_Admin::edit_url($own_sku_row), 'post='.$fx['variable_parent']), 'variation edit URL targets parent editor');
check('As scanned' === DPDA_Admin::source_status(array_merge($own_sku_row, array('modified_gmt' => $wpdb->get_var($wpdb->prepare("SELECT modified_gmt FROM {$t['ledger']} WHERE run_id=%s AND subject_id=%d",$run_id,$fx['variation_inherited']))))), 'unchanged variation source labelled as scanned');

$report = DPDA_Admin::report_page($run_id, 1);
check(!is_wp_error($report) && $report['total'] > 0 && count($report['rows']) === 50, 'paged report 50 rows');
$csv_stream = fopen('php://temp','w+');
check(true === DPDA_Admin::stream_csv($csv_stream,$complete), 'current complete report exports');
rewind($csv_stream); $csv = stream_get_contents($csv_stream); fclose($csv_stream);
check(str_contains($csv, "'=HYPERLINK(") && str_contains($csv, 'scan_finished_at_utc') && str_contains($csv, 'variation_own_sku'), 'CSV fixed columns and formula guard');
check("'  +1" === DPDA_Admin::csv_safe('  +1') && "'\t@x" === DPDA_Admin::csv_safe("\t@x"), 'formula guard whitespace');

$limited_id = wp_create_user('dpda_limited','test-password-only','limited@example.invalid');
$limited = new WP_User($limited_id); $limited->set_role('subscriber'); $limited->add_cap('manage_woocommerce');
wp_set_current_user($limited_id);
check('' === DPDA_Admin::edit_url($own_sku_row), 'CSV edit URL blank for viewer without product edit capability');
$limited_csv = fopen('php://temp','w+');
check(true === DPDA_Admin::stream_csv($limited_csv,$complete), 'manage_woocommerce-only user can export');
rewind($limited_csv); $limited_data = stream_get_contents($limited_csv); fclose($limited_csv);
check(str_contains($limited_data,'variation_own_sku') && !str_contains($limited_data,'post.php?post='), 'limited export omits edit URL');
wp_set_current_user($admin->ID);
$manager_id = wp_create_user('dpda_manager','test-password-only','manager@example.invalid');
(new WP_User($manager_id))->set_role('shop_manager');
wp_set_current_user($manager_id);
check(current_user_can('manage_woocommerce') && !is_wp_error(DPDA_Admin::report_page($run_id,1)), 'Shop Manager can view report');
wp_set_current_user($admin->ID);
$editor_id = wp_create_user('dpda_editor','test-password-only','editor@example.invalid');
(new WP_User($editor_id))->set_role('editor');
wp_set_current_user($editor_id);
check(!current_user_can('manage_woocommerce') && is_wp_error(DPDA_Scan::start()) && is_wp_error(DPDA_Scan::resume($run_id)) && is_wp_error(DPDA_Admin::report_page($run_id,1)), 'editor cannot view or start/resume');
$denied = fopen('php://temp','w+'); check(is_wp_error(DPDA_Admin::stream_csv($denied,$complete)), 'editor cannot export'); fclose($denied);
function dpda_test_die($message): void { throw new RuntimeException(wp_strip_all_tags((string)$message)); }
$die_filter = static fn(): string => 'dpda_test_die';
add_filter('wp_die_handler',$die_filter);
$_SERVER['REQUEST_METHOD']='POST';
$_POST=array('action'=>'dpda_action','dpda_task'=>'start','_wpnonce'=>wp_create_nonce('dpda_start'));
$editor_denied=false;
try { DPDA_Admin::handle(); } catch (RuntimeException $error) { $editor_denied=true; }
check($editor_denied, 'direct admin-post start denies editor despite valid nonce');
wp_set_current_user($manager_id);
$_POST['_wpnonce']='invalid';
$nonce_denied=false;
try { DPDA_Admin::handle(); } catch (RuntimeException $error) { $nonce_denied=true; }
check($nonce_denied, 'direct admin-post start denies manager with invalid nonce');
$_POST['_wpnonce']=wp_create_nonce('dpda_start');
$_SERVER['REQUEST_METHOD']='GET';
$method_denied=false;
try { DPDA_Admin::handle(); } catch (RuntimeException $error) { $method_denied=true; }
check($method_denied, 'direct admin-post rejects GET even with valid nonce');
check(DPDA_Scan::reconcile()['latest']['run_id'] === $run_id, 'denied admin-post attempts do not start a new run');
remove_filter('wp_die_handler',$die_filter);
$_POST=array();
$_SERVER['REQUEST_METHOD']='GET';
wp_set_current_user($admin->ID);

// Rule changes immediately turn the complete report historical and block current CSV.
$changed = $all; $changed['simple_image'] = false;
update_option(DPDA_Rules::OPTION, DPDA_Rules::normalize($changed), false);
$old = fopen('php://temp','w+'); check(is_wp_error(DPDA_Admin::stream_csv($old,$complete)), 'old-policy report cannot export'); fclose($old);
$replacement = DPDA_Scan::start(); check(is_array($replacement), 'new run allowed after completion');
$replacement_id = $replacement['run_id'];
$advanced = DPDA_Scan::resume($replacement_id); check(is_array($advanced), 'replacement started');
$changed['simple_sku'] = false; update_option(DPDA_Rules::OPTION, DPDA_Rules::normalize($changed), false);
check(is_wp_error(DPDA_Scan::resume($replacement_id)), 'mid-scan policy change marks incomplete');
$incomplete = DPDA_Scan::get($replacement_id);
check('incomplete' === $incomplete['status'] && 'rules_changed' === $incomplete['last_error'], 'incomplete reason persisted');
$state = DPDA_Scan::reconcile();
check($state['complete']['run_id'] === $run_id && $state['latest']['run_id'] === $replacement_id, 'previous complete retained after incomplete newer scan');
$partial_csv = fopen('php://temp','w+'); check(is_wp_error(DPDA_Admin::stream_csv($partial_csv,$incomplete)), 'partial run cannot export'); fclose($partial_csv);

// Remove variable parents so the new run has zero child-ledger rows. This reproduces the false-clean SUM/COUNT edge.
foreach (array($fx['variable_parent'], $fx['variable_no_published_children'], $fx['variable_51_parent']) as $variable_id) {
    $variable = wc_get_product($variable_id);
    $variable->set_status('draft');
    $variable->save();
}
// Inject the first verification aggregate SQL failure on a zero-child policy run.
update_option(DPDA_Rules::OPTION, DPDA_Rules::normalize(array('variable_has_published_variation'=>true)), false);
$failure_run = DPDA_Scan::start(); check(is_array($failure_run), 'verification failure run starts');
for ($i=0;$i<30;$i++) {
    $row = DPDA_Scan::get($failure_run['run_id']);
    if ('verify' === $row['phase']) { break; }
    $step = DPDA_Scan::resume($failure_run['run_id']);
    check(is_array($step), 'reach verify phase');
}
$bad_sum = static function(string $sql): string { return str_contains($sql, 'SUM(expected_children)') ? 'SELECT * FROM dpda_missing_table' : $sql; };
add_filter('query',$bad_sum);
$step = DPDA_Scan::resume($failure_run['run_id']);
remove_filter('query',$bad_sum);
check(is_wp_error($step) && 'incomplete' === DPDA_Scan::get($failure_run['run_id'])['status'], 'failed first aggregate cannot false-complete');
check(DPDA_Scan::reconcile()['complete']['run_id'] === $run_id, 'SQL failure preserves prior complete report');

// Source changes are labelled; records are retained without editing products.
$source = wc_get_product($fx['simple_blank']);
$source->set_name('Changed after scan'); $source->save();
$row = $wpdb->get_row($wpdb->prepare("SELECT f.*,l.modified_gmt FROM {$t['findings']} f JOIN {$t['ledger']} l ON l.run_id=f.run_id AND l.subject_id=f.subject_id WHERE f.run_id=%s AND f.subject_id=%d LIMIT 1",$run_id,$fx['simple_blank']), ARRAY_A);
check(is_array($row) && 'Changed since scan' === DPDA_Admin::source_status($row), 'changed source labelled stale');
$before = get_option('dpda_state_v1'); update_option('dpda_state_v1',array('latest_id'=>'stale'),false);
check(DPDA_Scan::reconcile()['state']['latest_id'] === $failure_run['run_id'], 'post-commit pointer repair reconstructs non-autoloaded state');

// Uninstall removes only plugin-owned tables/options; products remain.
deactivate_plugins($plugin);
check($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['runs'])) === $t['runs'], 'deactivation retains scan tables');
activate_plugin($plugin);
define('WP_UNINSTALL_PLUGIN',$plugin);
require WP_PLUGIN_DIR . '/digicyfr-product-data-audit/uninstall.php';
check(null === $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['runs'])) && false === get_option(DPDA_Rules::OPTION,false) && wc_get_product($fx['simple_blank']) instanceof WC_Product, 'uninstall removes own tables/options and retains products');
printf("PASS exact ZIP product audit integration: WP %s, PHP %s, Woo %s, parents %d, children %d\n",get_bloginfo('version'),PHP_VERSION,WC_VERSION,$parent_count,$child_count);
