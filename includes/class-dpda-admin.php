<?php
/**
 * Product data audit component.
 *
 * @package Digicyfr_Product_Data_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin component. */
final class DPDA_Admin {
	// phpcs:disable WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET values only select read-only views; POST actions verify nonces.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Paged reads of plugin-owned report tables are necessary.
	private const PAGE       = 'digicyfr-product-data-audit';
	private const MAX_EXPORT = 20000;

	/** Boot. */
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_dpda_action', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Dependency notice. */
	public static function dependency_notice(): void {
		if ( current_user_can( 'manage_woocommerce' ) && ! DPDA_Scan::ready() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Product Data Audit needs active WooCommerce 8.9.0 or newer. No scan is available until then.', 'digicyfr-product-data-audit' ) . '</p></div>';
		}
	}

	/** Menu. */
	public static function menu(): void {
		if ( DPDA_Scan::ready() ) {
			add_submenu_page( 'woocommerce', __( 'Product Data Audit', 'digicyfr-product-data-audit' ), __( 'Product Data Audit', 'digicyfr-product-data-audit' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'screen' ) );
		}
	}

	/** Assets.
	 *
	 * @param string $hook hook input.
	 */
	public static function assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dpda-admin', DIGICYFR_PRODUCT_DATA_AUDIT_URL . 'assets/admin.css', array(), DIGICYFR_PRODUCT_DATA_AUDIT_VERSION );
		wp_enqueue_script( 'dpda-admin', DIGICYFR_PRODUCT_DATA_AUDIT_URL . 'assets/admin.js', array(), DIGICYFR_PRODUCT_DATA_AUDIT_VERSION, true );
	}

	/** Page url.
	 *
	 * @param array $args args input.
	 */
	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Notice text.
	 *
	 * @param string $code code input.
	 */
	private static function notice_text( string $code ): string {
		$messages = array(
			'rules_saved'     => __( 'Rule selection saved. Start a new scan to apply it.', 'digicyfr-product-data-audit' ),
			'suggested_saved' => __( 'Suggested selection saved. Review it before scanning.', 'digicyfr-product-data-audit' ),
			'scan_started'    => __( 'Scan started. Continue in bounded batches.', 'digicyfr-product-data-audit' ),
			'scan_advanced'   => __( 'One bounded batch completed.', 'digicyfr-product-data-audit' ),
			'scan_cancelled'  => __( 'Scan cancelled. Its partial findings are not a current report.', 'digicyfr-product-data-audit' ),
			'action_failed'   => __( 'The action failed. Review the scan status and try a fresh scan.', 'digicyfr-product-data-audit' ),
			'export_failed'   => __( 'CSV export failed before download. No partial file was sent.', 'digicyfr-product-data-audit' ),
		);
		return $messages[ $code ] ?? '';
	}

	/** Screen. */
	public static function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot view this audit.', 'digicyfr-product-data-audit' ) );
		}
		if ( ! DPDA_Scan::ready() ) {
			wp_die( esc_html__( 'WooCommerce 8.9.0 or newer is required.', 'digicyfr-product-data-audit' ) );
		}
		$state  = DPDA_Scan::reconcile();
		$rules  = DPDA_Rules::selected();
		$notice = isset( $_GET['dpda_notice'] ) && is_string( $_GET['dpda_notice'] ) ? sanitize_key( wp_unslash( $_GET['dpda_notice'] ) ) : '';
		echo '<div class="wrap dpda-wrap"><h1>' . esc_html__( 'Product Data Audit', 'digicyfr-product-data-audit' ) . '</h1>';
		echo '<p>' . esc_html__( 'A read-only, merchant-selected checklist of empty WooCommerce fields. A finding is not a feed, SEO, tax, or sales verdict. No product is changed.', 'digicyfr-product-data-audit' ) . '</p>';
		if ( '' !== self::notice_text( $notice ) ) {
			echo '<div class="notice notice-info dpda-result" role="status" tabindex="-1"><p>' . esc_html( self::notice_text( $notice ) ) . '</p></div>';
		}
		if ( is_wp_error( $state ) ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $state->get_error_message() ) . '</p></div></div>';
			return;
		}
		$latest     = $state['latest'];
		$complete   = $state['complete'];
		$is_current = is_array( $complete ) && is_array( $latest ) && hash_equals( $complete['run_id'], $latest['run_id'] ) && hash_equals( $complete['rules_hash'], DPDA_Rules::hash( $rules ) );
		self::rules_form( $rules );
		self::scan_panel( $latest, $complete, $is_current, DPDA_Rules::count_selected( $rules ) );
		if ( is_array( $complete ) ) {
			self::report( $complete, $is_current );
		}
		echo '</div>';
	}

	/** Form open.
	 *
	 * @param string $task task input.
	 * @param string $label label input.
	 * @param array  $hidden hidden input.
	 */
	private static function form_open( string $task, string $label, array $hidden = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dpda-action"><input type="hidden" name="action" value="dpda_action"><input type="hidden" name="dpda_task" value="' . esc_attr( $task ) . '">';
		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( 'dpda_' . $task );
		echo '<button type="submit" class="button' . ( 'resume' === $task || 'start' === $task ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Rules form.
	 *
	 * @param array $rules rules input.
	 */
	private static function rules_form( array $rules ): void {
		$definitions = DPDA_Rules::definitions();
		echo '<section aria-labelledby="dpda-rules-title"><h2 id="dpda-rules-title">' . esc_html__( 'Choose the fields you expect', 'digicyfr-product-data-audit' ) . '</h2>';
		echo '<p>' . esc_html__( 'All rules begin off. Choose each applicable policy cell yourself. The suggested set is optional and must be accepted explicitly. Changing rules makes the old report historical until a new scan finishes.', 'digicyfr-product-data-audit' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dpda_action"><input type="hidden" name="dpda_task" value="save_rules">';
		wp_nonce_field( 'dpda_save_rules' );
		foreach ( array(
			'simple'    => __( 'Simple products', 'digicyfr-product-data-audit' ),
			'variable'  => __( 'Variable parents', 'digicyfr-product-data-audit' ),
			'variation' => __( 'Published variations', 'digicyfr-product-data-audit' ),
		) as $type => $heading ) {
			echo '<fieldset class="dpda-rule-group"><legend>' . esc_html( $heading ) . '</legend>';
			foreach ( $definitions as $key => $definition ) {
				if ( $type !== $definition[0] ) {
					continue;
				}
				echo '<label><input type="checkbox" name="rules[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $rules[ $key ] ), true, false ) . '> <span>' . esc_html( $definition[1] ) . '</span></label>';
			}
			echo '</fieldset>';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save selected rules', 'digicyfr-product-data-audit' ) . '</button></p></form>';
		self::form_open( 'suggested', __( 'Accept suggested starter selection', 'digicyfr-product-data-audit' ) );
		echo '<p class="description">' . esc_html__( 'The starter set checks main images and descriptions on simple items, main images and published children on variable parents, and active prices on simple items and published variations.', 'digicyfr-product-data-audit' ) . '</p></section>';
	}

	/** Scan panel.
	 *
	 * @param ?array $latest latest input.
	 * @param ?array $complete complete input.
	 * @param bool   $is_current is current input.
	 * @param int    $selected_count selected count input.
	 */
	private static function scan_panel( ?array $latest, ?array $complete, bool $is_current, int $selected_count ): void {
		echo '<section aria-labelledby="dpda-scan-title"><h2 id="dpda-scan-title">' . esc_html__( 'Scan status', 'digicyfr-product-data-audit' ) . '</h2>';
		if ( 0 === $selected_count ) {
			echo '<p class="notice notice-warning dpda-inline" role="status">' . esc_html__( 'No rules selected. Save at least one rule before starting a scan.', 'digicyfr-product-data-audit' ) . '</p>';
		}
		if ( ! $latest ) {
			echo '<p>' . esc_html__( 'No scan has started. An empty rule selection produces no report.', 'digicyfr-product-data-audit' ) . '</p>';
		} else {
			$status = self::status_label( $latest['status'] );
			echo '<p><strong>' . esc_html( $status ) . '</strong> — ' . esc_html__( 'as-scanned, best-effort catalog snapshot', 'digicyfr-product-data-audit' ) . '. ' . esc_html__( 'Equal totals cannot detect every concurrent catalog edit.', 'digicyfr-product-data-audit' ) . '</p>';
			echo '<dl class="dpda-stats"><dt>' . esc_html__( 'Started (UTC)', 'digicyfr-product-data-audit' ) . '</dt><dd>' . esc_html( $latest['started_at'] ) . '</dd>';
			echo '<dt>' . esc_html__( 'Finished (UTC)', 'digicyfr-product-data-audit' ) . '</dt><dd>' . esc_html( '' !== (string) $latest['completed_at'] ? $latest['completed_at'] : '—' ) . '</dd>';
			echo '<dt>' . esc_html__( 'Supported published parents at start', 'digicyfr-product-data-audit' ) . '</dt><dd>' . esc_html( (string) $latest['parent_total'] ) . '</dd>';
			echo '<dt>' . esc_html__( 'Unsupported published parents of registered types', 'digicyfr-product-data-audit' ) . '</dt><dd>' . esc_html( (string) $latest['unsupported_total'] ) . '</dd>';
			echo '<dt>' . esc_html__( 'Phase / parent page / child page', 'digicyfr-product-data-audit' ) . '</dt><dd>' . esc_html( $latest['phase'] . ' / ' . $latest['parent_page'] . ' / ' . $latest['child_page'] ) . '</dd></dl>';
			if ( 'incomplete' === $latest['status'] ) {
				echo '<p class="notice notice-warning dpda-inline" role="status">' . esc_html__( 'This run is incomplete. Its findings are withheld from the current report and CSV. Reason:', 'digicyfr-product-data-audit' ) . ' ' . esc_html( self::error_label( $latest['last_error'] ) ) . '</p>';
			}
		}
		if ( $latest && in_array( $latest['status'], array( 'queued', 'running' ), true ) ) {
			self::form_open( 'resume', __( 'Continue one batch', 'digicyfr-product-data-audit' ), array( 'run_id' => $latest['run_id'] ) );
			self::form_open( 'cancel', __( 'Cancel incomplete scan', 'digicyfr-product-data-audit' ), array( 'run_id' => $latest['run_id'] ) );
		} elseif ( $selected_count > 0 ) {
			self::form_open( 'start', __( 'Start a fresh scan', 'digicyfr-product-data-audit' ) );
		}
		if ( $complete && ! $is_current ) {
			echo '<p class="notice notice-warning dpda-inline" role="status">' . esc_html__( 'The previous complete report remains visible below as historical or old-policy data. It is not the current report and cannot be exported as current.', 'digicyfr-product-data-audit' ) . '</p>';
		}
		echo '</section>';
	}

	/** Status label.
	 *
	 * @param string $status status input.
	 */
	private static function status_label( string $status ): string {
		return array(
			'queued'     => __( 'Queued', 'digicyfr-product-data-audit' ),
			'running'    => __( 'Running', 'digicyfr-product-data-audit' ),
			'complete'   => __( 'Complete', 'digicyfr-product-data-audit' ),
			'incomplete' => __( 'Incomplete', 'digicyfr-product-data-audit' ),
		)[ $status ] ?? __( 'Unknown', 'digicyfr-product-data-audit' );
	}

	/** Error label.
	 *
	 * @param string $code code input.
	 */
	private static function error_label( string $code ): string {
		return array(
			'rules_changed'         => __( 'Selected rules changed during the scan.', 'digicyfr-product-data-audit' ),
			'parent_total_changed'  => __( 'Supported parent count changed during the scan.', 'digicyfr-product-data-audit' ),
			'child_total_changed'   => __( 'Published variation count changed during the scan.', 'digicyfr-product-data-audit' ),
			'catalog_total_changed' => __( 'Catalog totals changed during verification.', 'digicyfr-product-data-audit' ),
			'cancelled'             => __( 'The merchant cancelled this scan.', 'digicyfr-product-data-audit' ),
		)[ $code ] ?? __( 'A product query, data change, or storage error stopped the scan.', 'digicyfr-product-data-audit' );
	}

	/**
	 * A prepared, bounded page. Both reads must succeed before rendering any clean-empty claim.
	 *
	 * @param string $run_id Run UUID.
	 * @param int    $page Page number.
	 * @param int    $limit Rows per page.
	 */
	public static function report_page( string $run_id, int $page, int $limit = 50 ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_is_uuid( $run_id ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot view this report.', 'digicyfr-product-data-audit' ) );
		}
		$t     = DPDA_Storage::tables();
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['findings']} WHERE run_id = %s", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || null === $total ) {
			return new WP_Error( 'storage_failed', __( 'Could not read findings. The report is unavailable.', 'digicyfr-product-data-audit' ) );
		}
		$offset = max( 0, $page - 1 ) * $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT f.*,l.modified_gmt FROM {$t['findings']} f LEFT JOIN {$t['ledger']} l ON l.run_id=f.run_id AND l.subject_id=f.subject_id WHERE f.run_id = %s ORDER BY f.id ASC LIMIT %d OFFSET %d", $run_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin tables.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'storage_failed', __( 'Could not read findings. The report is unavailable.', 'digicyfr-product-data-audit' ) );
		}
		return array(
			'total' => (int) $total,
			'rows'  => $rows,
		);
	}

	/** Report.
	 *
	 * @param array $run run input.
	 * @param bool  $is_current is current input.
	 */
	private static function report( array $run, bool $is_current ): void {
		$page = isset( $_GET['dpda_page'] ) && is_scalar( $_GET['dpda_page'] ) ? max( 1, absint( $_GET['dpda_page'] ) ) : 1;
		$data = self::report_page( $run['run_id'], $page );
		echo '<section aria-labelledby="dpda-report-title"><h2 id="dpda-report-title">' . esc_html( $is_current ? __( 'Current findings', 'digicyfr-product-data-audit' ) : __( 'Historical complete findings', 'digicyfr-product-data-audit' ) ) . '</h2>';
		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $data->get_error_message() ) . '</p></div></section>';
			return;
		}
		echo '<p>' . esc_html( sprintf( /* translators: 1: number of findings, 2: UTC scan finish */ __( '%1$d selected-field findings; scan finished %2$s UTC.', 'digicyfr-product-data-audit' ), $data['total'], $run['completed_at'] ) ) . '</p>';
		if ( $is_current ) {
			self::form_open( 'export', __( 'Download current CSV', 'digicyfr-product-data-audit' ) );
		}
		if ( 0 === $data['total'] ) {
			echo '<p>' . esc_html__( 'No selected-field gaps were recorded in this as-scanned snapshot.', 'digicyfr-product-data-audit' ) . '</p></section>';
			return;
		}
		$definitions = DPDA_Rules::definitions();
		echo '<div class="dpda-table-scroll"><table class="widefat striped"><caption>' . esc_html__( 'One row per selected missing field', 'digicyfr-product-data-audit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Product or variation', 'digicyfr-product-data-audit' ) . '</th><th scope="col">' . esc_html__( 'Missing field', 'digicyfr-product-data-audit' ) . '</th><th scope="col">' . esc_html__( 'Why this qualified', 'digicyfr-product-data-audit' ) . '</th><th scope="col">' . esc_html__( 'Current source', 'digicyfr-product-data-audit' ) . '</th><th scope="col">' . esc_html__( 'Edit location', 'digicyfr-product-data-audit' ) . '</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			$key        = $row['rule_key'];
			$definition = $definitions[ $key ] ?? array( '', __( 'Unknown field', 'digicyfr-product-data-audit' ), __( 'The saved rule is not recognized.', 'digicyfr-product-data-audit' ) );
			$status     = self::source_status( $row );
			$url        = self::edit_url( $row );
			echo '<tr><th scope="row">' . esc_html( $row['product_name'] ) . ' <span class="description">#' . esc_html( $row['subject_id'] ) . ( (int) $row['parent_id'] ? ' / ' . esc_html__( 'parent', 'digicyfr-product-data-audit' ) . ' #' . esc_html( $row['parent_id'] ) : '' ) . ( '' !== $row['sku'] ? ' / ' . esc_html__( 'SKU', 'digicyfr-product-data-audit' ) . ': ' . esc_html( $row['sku'] ) : '' ) . '</span></th>';
			echo '<td>' . esc_html( $definition[1] ) . '</td><td>' . esc_html( $definition[2] ) . '</td><td>' . esc_html( $status ) . '</td><td>';
			if ( '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open parent product editor', 'digicyfr-product-data-audit' ) . '</a>';
			} else {
				echo esc_html__( 'No edit permission or source removed', 'digicyfr-product-data-audit' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		$pages = (int) ceil( $data['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<nav class="dpda-pagination" aria-label="' . esc_attr__( 'Findings pages', 'digicyfr-product-data-audit' ) . '">';
			if ( $page > 1 ) {
				echo '<a class="button" href="' . esc_url( self::page_url( array( 'dpda_page' => $page - 1 ) ) ) . '">' . esc_html__( 'Previous', 'digicyfr-product-data-audit' ) . '</a>'; }
			echo '<span>' . esc_html( sprintf( /* translators: 1: page, 2: total pages */ __( 'Page %1$d of %2$d', 'digicyfr-product-data-audit' ), $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo '<a class="button" href="' . esc_url( self::page_url( array( 'dpda_page' => $page + 1 ) ) ) . '">' . esc_html__( 'Next', 'digicyfr-product-data-audit' ) . '</a>'; }
			echo '</nav>';
		}
		echo '</section>';
	}

	/** Source status.
	 *
	 * @param array $row row input.
	 */
	public static function source_status( array $row ): string {
		$product = DPDA_Scan::ready() ? wc_get_product( (int) $row['subject_id'] ) : false;
		if ( ! $product instanceof WC_Product ) {
			return __( 'Removed or unavailable', 'digicyfr-product-data-audit' );
		}
		$date     = $product->get_date_modified();
		$modified = $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : null;
		if ( 'publish' !== $product->get_status() || $product->get_type() !== $row['subject_type'] || (int) $product->get_parent_id() !== (int) $row['parent_id'] || $modified !== $row['modified_gmt'] ) {
			return __( 'Changed since scan', 'digicyfr-product-data-audit' );
		}
		return __( 'As scanned', 'digicyfr-product-data-audit' );
	}

	/** Edit url.
	 *
	 * @param array $row row input.
	 */
	public static function edit_url( array $row ): string {
		$id = (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : (int) $row['subject_id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return '';
		}
		$product = DPDA_Scan::ready() ? wc_get_product( $id ) : false;
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$url = get_edit_post_link( $id, '' );
		return is_string( $url ) ? $url : '';
	}

	/** Csv safe.
	 *
	 * @param string $value value input.
	 */
	public static function csv_safe( string $value ): string {
		return preg_match( '/^[\s\x{FEFF}]*[=+\-@]/u', $value ) ? "'" . $value : $value;
	}

	/**
	 * Spool a complete, current-policy CSV before HTTP headers are sent.
	 *
	 * @param resource $stream Writable temporary stream.
	 * @param array    $run Current run row.
	 */
	public static function stream_csv( $stream, array $run ): true|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot export this report.', 'digicyfr-product-data-audit' ) );
		}
		$state = DPDA_Scan::reconcile();
		if ( is_wp_error( $state ) || 'complete' !== $run['status'] || ! hash_equals( $run['rules_hash'], DPDA_Rules::hash( DPDA_Rules::selected() ) ) || ! is_array( $state['latest'] ) || ! hash_equals( $state['latest']['run_id'], $run['run_id'] ) ) {
			return new WP_Error( 'not_current', __( 'Only the active complete report under the current rules can be exported.', 'digicyfr-product-data-audit' ) );
		}
		$t     = DPDA_Storage::tables();
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['findings']} WHERE run_id = %s", $run['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || null === $total || (int) $total > self::MAX_EXPORT ) {
			return new WP_Error( 'export_unavailable', __( 'Export is unavailable or exceeds 20,000 findings. No partial CSV was sent.', 'digicyfr-product-data-audit' ) );
		}
		$headers = array( 'scan_finished_at_utc', 'parent_id', 'subject_id', 'product_type', 'product_name', 'sku', 'rule_key', 'reason', 'edit_url' );
		if ( false === fputcsv( $stream, $headers, ',', '"', '' ) ) {
			return new WP_Error( 'write_failed', __( 'CSV could not be written.', 'digicyfr-product-data-audit' ) );
		}
		$definitions = DPDA_Rules::definitions();
		$cursor      = 0;
		$count       = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['findings']} WHERE run_id = %s AND id > %d ORDER BY id ASC LIMIT 500", $run['run_id'], $cursor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table and bounded keyset read.
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				return new WP_Error( 'storage_failed', __( 'CSV source could not be read. No partial CSV was sent.', 'digicyfr-product-data-audit' ) );
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$reason = $definitions[ $row['rule_key'] ][2] ?? __( 'Saved rule is not recognized.', 'digicyfr-product-data-audit' );
				$values = array( $run['completed_at'], (string) $row['parent_id'], (string) $row['subject_id'], $row['subject_type'], $row['product_name'], $row['sku'], $row['rule_key'], $reason, self::edit_url( $row ) );
				if ( false === fputcsv( $stream, array_map( array( __CLASS__, 'csv_safe' ), $values ), ',', '"', '' ) ) {
					return new WP_Error( 'write_failed', __( 'CSV could not be written.', 'digicyfr-product-data-audit' ) );
				}
				++$count;
			}
			$batch_count = count( $rows );
		} while ( 500 === $batch_count );
		if ( $count !== (int) $total ) {
			return new WP_Error( 'catalog_changed', __( 'Report rows changed during export. No partial CSV was sent.', 'digicyfr-product-data-audit' ) );
		}
		return true;
	}

	/** Handle. */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Use the audit form to perform this action.', 'digicyfr-product-data-audit' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot perform this audit action.', 'digicyfr-product-data-audit' ) );
		}
		$task = isset( $_POST['dpda_task'] ) && is_string( $_POST['dpda_task'] ) ? sanitize_key( wp_unslash( $_POST['dpda_task'] ) ) : '';
		if ( ! in_array( $task, array( 'save_rules', 'suggested', 'start', 'resume', 'cancel', 'export' ), true ) ) {
			wp_die( esc_html__( 'Unknown audit action.', 'digicyfr-product-data-audit' ) );
		}
		check_admin_referer( 'dpda_' . $task );
		$result = null;
		$notice = 'action_failed';
		if ( 'save_rules' === $task ) {
			$raw      = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? map_deep( wp_unslash( $_POST['rules'] ), 'sanitize_text_field' ) : array();
			$expected = DPDA_Rules::normalize( $raw );
			update_option( DPDA_Rules::OPTION, $expected, false );
			$notice = DPDA_Rules::selected() === $expected ? 'rules_saved' : 'action_failed';
		} elseif ( 'suggested' === $task ) {
			$expected = DPDA_Rules::suggested();
			update_option( DPDA_Rules::OPTION, $expected, false );
			$notice = DPDA_Rules::selected() === $expected ? 'suggested_saved' : 'action_failed';
		} elseif ( 'start' === $task ) {
			$result = DPDA_Scan::start();
			$notice = is_wp_error( $result ) ? 'action_failed' : 'scan_started';
		} elseif ( 'resume' === $task || 'cancel' === $task ) {
			$id     = isset( $_POST['run_id'] ) && is_string( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$result = 'resume' === $task ? DPDA_Scan::resume( $id ) : DPDA_Scan::cancel( $id );
			$notice = is_wp_error( $result ) ? 'action_failed' : ( 'resume' === $task ? 'scan_advanced' : 'scan_cancelled' );
		} elseif ( 'export' === $task ) {
			$state  = DPDA_Scan::reconcile();
			$run    = ! is_wp_error( $state ) && is_array( $state['latest'] ) ? $state['latest'] : null;
			$stream = fopen( 'php://temp/maxmemory:2097152', 'w+' );
			$result = $run && is_resource( $stream ) ? self::stream_csv( $stream, $run ) : new WP_Error( 'export_unavailable', __( 'Export is unavailable.', 'digicyfr-product-data-audit' ) );
			if ( true === $result ) {
				rewind( $stream );
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="digicyfr-product-data-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );
				header( 'Cache-Control: no-store' );
				fpassthru( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru -- Streaming prevalidated temporary CSV to browser.
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close temporary CSV spool.
				exit;
			}
			if ( is_resource( $stream ) ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close temporary CSV spool.
			}
			$notice = 'export_failed';
		}
		wp_safe_redirect( self::page_url( array( 'dpda_notice' => $notice ) ) );
		exit;
	}
}
