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
final class DPDA_Scan {
	// phpcs:disable WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned run rows need transactions and row locks.
	public const PARENT_BATCH = 25;
	public const CHILD_BATCH  = 50;

	/** Ready. */
	public static function ready(): bool {
		return function_exists( 'wc_get_products' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.9.0', '>=' );
	}

	/**
	 * WooCommerce pagination, validated before interpreting an empty page.
	 *
	 * @param array $args Product query arguments.
	 */
	public static function query( array $args ): array|WP_Error {
		global $wpdb;
		if ( ! self::ready() ) {
			return new WP_Error( 'woocommerce_unavailable', __( 'WooCommerce 8.9.0 or newer is required.', 'digicyfr-product-data-audit' ) );
		}
		$args             = array_merge(
			array(
				'status'   => 'publish',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'return'   => 'ids',
				'paginate' => true,
			),
			$args
		);
		$wpdb->last_error = '';
		try {
			$result = wc_get_products( $args );
		} catch ( Throwable $error ) {
			return new WP_Error( 'query_failed', __( 'WooCommerce product query failed. The scan is incomplete.', 'digicyfr-product-data-audit' ) );
		}
		if ( '' !== $wpdb->last_error || ! is_object( $result ) || ! isset( $result->products, $result->total, $result->max_num_pages ) || ! is_array( $result->products ) || ! is_numeric( $result->total ) || ! is_numeric( $result->max_num_pages ) ) {
			return new WP_Error( 'query_failed', __( 'WooCommerce product query failed. The scan is incomplete.', 'digicyfr-product-data-audit' ) );
		}
		foreach ( $result->products as $id ) {
			if ( ! is_numeric( $id ) || (int) $id < 1 ) {
				return new WP_Error( 'query_failed', __( 'WooCommerce returned an invalid product ID.', 'digicyfr-product-data-audit' ) );
			}
		}
		return array(
			'ids'   => array_map( 'intval', $result->products ),
			'total' => (int) $result->total,
			'pages' => (int) $result->max_num_pages,
		);
	}

	/** Parents.
	 *
	 * @param int $page page input.
	 * @param int $limit limit input.
	 */
	public static function parents( int $page, int $limit = self::PARENT_BATCH ): array|WP_Error {
		return self::query(
			array(
				'type'  => array( 'simple', 'variable' ),
				'page'  => $page,
				'limit' => $limit,
			)
		);
	}

	/** Children.
	 *
	 * @param int $parent_id parent id input.
	 * @param int $page page input.
	 * @param int $limit limit input.
	 */
	public static function children( int $parent_id, int $page, int $limit = self::CHILD_BATCH ): array|WP_Error {
		return self::query(
			array(
				'type'   => 'variation',
				'parent' => $parent_id,
				'page'   => $page,
				'limit'  => $limit,
			)
		);
	}

	/** Unsupported count. */
	public static function unsupported_count(): int|WP_Error {
		if ( ! self::ready() ) {
			return new WP_Error( 'woocommerce_unavailable', __( 'WooCommerce is unavailable.', 'digicyfr-product-data-audit' ) );
		}
		$types = array_values( array_diff( array_keys( wc_get_product_types() ), array( 'simple', 'variable' ) ) );
		if ( array() === $types ) {
			return 0;
		}
		$query = self::query(
			array(
				'type'  => $types,
				'limit' => 1,
				'page'  => 1,
			)
		);
		return is_wp_error( $query ) ? $query : $query['total'];
	}

	/** Get.
	 *
	 * @param string $run_id run id input.
	 */
	public static function get( string $run_id ): ?array {
		global $wpdb;
		if ( ! wp_is_uuid( $run_id ) ) {
			return null;
		}
		$table = DPDA_Storage::tables()['runs'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE run_id = %s", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		return is_array( $row ) ? $row : null;
	}

	/** Rebuild the non-autoloaded status option after a post-commit crash. */
	public static function reconcile(): array|WP_Error {
		global $wpdb;
		$table  = DPDA_Storage::tables()['runs'];
		$latest = $wpdb->get_row( "SELECT * FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'storage_failed', __( 'Could not read the scan state. Existing reports were retained.', 'digicyfr-product-data-audit' ) );
		}
		$complete = $wpdb->get_row( "SELECT * FROM $table WHERE status = 'complete' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table and status.
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'storage_failed', __( 'Could not read the scan state. Existing reports were retained.', 'digicyfr-product-data-audit' ) );
		}
		$state = array(
			'latest_id'          => is_array( $latest ) ? $latest['run_id'] : '',
			'latest_status'      => is_array( $latest ) ? $latest['status'] : '',
			'latest_phase'       => is_array( $latest ) ? $latest['phase'] : '',
			'latest_complete_id' => is_array( $complete ) ? $complete['run_id'] : '',
			'latest_rules_hash'  => is_array( $latest ) ? $latest['rules_hash'] : '',
			'parent_page'        => is_array( $latest ) ? (int) $latest['parent_page'] : 1,
			'pending_ids'        => is_array( $latest ) ? json_decode( $latest['pending_json'], true ) : array(),
			'pending_index'      => is_array( $latest ) ? (int) $latest['pending_index'] : 0,
			'child_page'         => is_array( $latest ) ? (int) $latest['child_page'] : 1,
			'parent_total'       => is_array( $latest ) ? (int) $latest['parent_total'] : 0,
			'unsupported_total'  => is_array( $latest ) ? (int) $latest['unsupported_total'] : 0,
			'started_at'         => is_array( $latest ) ? $latest['started_at'] : '',
		);
		update_option( 'dpda_state_v1', $state, false );
		return array(
			'latest'   => $latest,
			'complete' => $complete,
			'state'    => $state,
		);
	}

	/** A non-autoloaded unique option serializes new-run creation. */
	public static function start(): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot start a product audit.', 'digicyfr-product-data-audit' ) );
		}
		if ( ! self::ready() || ! DPDA_Storage::transactional() ) {
			return new WP_Error( 'storage_unavailable', __( 'WooCommerce and InnoDB scan tables are required.', 'digicyfr-product-data-audit' ) );
		}
		$selection = DPDA_Rules::selected();
		if ( 0 === DPDA_Rules::count_selected( $selection ) ) {
			return new WP_Error( 'no_rules', __( 'Select and save at least one rule before scanning.', 'digicyfr-product-data-audit' ) );
		}
		$lock_name = 'dpda_start_' . substr( hash( 'sha256', $wpdb->prefix . home_url( '/' ) ), 0, 40 );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '' !== $wpdb->last_error || '1' !== (string) $acquired ) {
			return new WP_Error( 'busy', __( 'Another scan action is in progress. Try again.', 'digicyfr-product-data-audit' ) );
		}
		try {
			$reconciled = self::reconcile();
			if ( is_wp_error( $reconciled ) ) {
				return $reconciled;
			}
			$latest = $reconciled['latest'];
			if ( $latest && in_array( $latest['status'], array( 'queued', 'running' ), true ) ) {
				return new WP_Error( 'already_running', __( 'Finish or cancel the current scan first.', 'digicyfr-product-data-audit' ) );
			}
			$parents     = self::parents( 1, 1 );
			$unsupported = self::unsupported_count();
			if ( is_wp_error( $parents ) ) {
				return $parents;
			}
			if ( is_wp_error( $unsupported ) ) {
				return $unsupported;
			}
			$id    = wp_generate_uuid4();
			$now   = current_time( 'mysql', true );
			$table = DPDA_Storage::tables()['runs'];
			$ok    = $wpdb->insert(
				$table,
				array(
					'run_id'            => $id,
					'status'            => 'queued',
					'phase'             => 'parents',
					'rules_json'        => wp_json_encode( $selection ),
					'rules_hash'        => DPDA_Rules::hash( $selection ),
					'parent_page'       => 1,
					'pending_json'      => '[]',
					'pending_index'     => 0,
					'child_page'        => 1,
					'verify_page'       => 1,
					'parent_total'      => $parents['total'],
					'unsupported_total' => $unsupported,
					'started_at'        => $now,
					'updated_at'        => $now,
					'last_error'        => '',
				)
			);
			if ( false === $ok ) {
				return new WP_Error( 'storage_failed', __( 'Could not create the scan record.', 'digicyfr-product-data-audit' ) );
			}
			self::reconcile();
			DPDA_Storage::prune();
			$row = self::get( $id );
			return $row ? $row : new WP_Error( 'storage_failed', __( 'The scan was created but could not be read. Reload this page.', 'digicyfr-product-data-audit' ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** Resume.
	 *
	 * @param string $run_id run id input.
	 * @throws RuntimeException When an internal batch operation fails; handled as incomplete.
	 */
	public static function resume( string $run_id ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot continue this product audit.', 'digicyfr-product-data-audit' ) );
		}
		if ( ! self::ready() || ! DPDA_Storage::transactional() || ! wp_is_uuid( $run_id ) ) {
			return new WP_Error( 'storage_unavailable', __( 'WooCommerce and InnoDB scan tables are required.', 'digicyfr-product-data-audit' ) );
		}
		$t = DPDA_Storage::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional scan.
			return new WP_Error( 'storage_failed', __( 'Could not start a safe scan transaction.', 'digicyfr-product-data-audit' ) );
		}
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['runs']} WHERE run_id = %s FOR UPDATE", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'run_read_failed' );
			}
			if ( ! is_array( $row ) || ! in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No changes.
				return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-product-data-audit' ) );
			}
			if ( ! hash_equals( $row['rules_hash'], DPDA_Rules::hash( DPDA_Rules::selected() ) ) ) {
				throw new RuntimeException( 'rules_changed' );
			}
			$rules = json_decode( $row['rules_json'], true );
			if ( ! is_array( $rules ) || ! hash_equals( $row['rules_hash'], DPDA_Rules::hash( $rules ) ) ) {
				throw new RuntimeException( 'rules_corrupt' );
			}
			$phase = $row['phase'];
			if ( 'parents' === $phase ) {
				self::process_parents( $row, $rules );
			} elseif ( 'children' === $phase ) {
				self::process_children( $row, $rules );
			} elseif ( 'verify' === $phase ) {
				self::verify( $row );
			} else {
				throw new RuntimeException( 'invalid_phase' );
			}
			$row['updated_at'] = current_time( 'mysql', true );
			$row['status']     = 'complete' === $row['status'] ? 'complete' : 'running';
			$ok                = $wpdb->update(
				$t['runs'],
				array(
					'status'        => $row['status'],
					'phase'         => $row['phase'],
					'parent_page'   => $row['parent_page'],
					'pending_json'  => $row['pending_json'],
					'pending_index' => $row['pending_index'],
					'child_page'    => $row['child_page'],
					'verify_page'   => $row['verify_page'],
					'updated_at'    => $row['updated_at'],
					'completed_at'  => $row['completed_at'],
					'last_error'    => $row['last_error'],
				),
				array( 'run_id' => $run_id )
			);
			if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit findings/ledger and cursor together.
				throw new RuntimeException( 'commit_failed' );
			}
			self::reconcile();
			if ( 'complete' === $row['status'] ) {
				DPDA_Storage::prune();
			}
			$fresh = self::get( $run_id );
			return $fresh ? $fresh : new WP_Error( 'storage_failed', __( 'The scan committed but could not be read. Reload this page.', 'digicyfr-product-data-audit' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Revert partial subject writes and cursor.
			$wpdb->update(
				$t['runs'],
				array(
					'status'       => 'incomplete',
					'completed_at' => current_time( 'mysql', true ),
					'updated_at'   => current_time( 'mysql', true ),
					'last_error'   => self::error_code( $error->getMessage() ),
				),
				array(
					'run_id' => $run_id,
					'status' => 'queued',
				)
			);
			$wpdb->update(
				$t['runs'],
				array(
					'status'       => 'incomplete',
					'completed_at' => current_time( 'mysql', true ),
					'updated_at'   => current_time( 'mysql', true ),
					'last_error'   => self::error_code( $error->getMessage() ),
				),
				array(
					'run_id' => $run_id,
					'status' => 'running',
				)
			);
			self::reconcile();
			return new WP_Error( 'scan_incomplete', __( 'The scan is incomplete. The previous complete report remains available; start a fresh scan.', 'digicyfr-product-data-audit' ) );
		}
	}

	/** Process parents.
	 *
	 * @param array $row row input.
	 * @param array $rules rules input.
	 * @throws RuntimeException When a product or owned-table read/write fails.
	 */
	private static function process_parents( array &$row, array $rules ): void {
		$result = self::parents( (int) $row['parent_page'] );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'parent_query_failed' );
		}
		if ( array() === $result['ids'] ) {
			$expected_pages = (int) ceil( (int) $row['parent_total'] / self::PARENT_BATCH );
			if ( (int) $row['parent_page'] <= $expected_pages ) {
				throw new RuntimeException( 'parent_page_empty' );
			}
			// WooCommerce can report total=0 for an out-of-range page; requery page one before finishing.
			$fresh = self::parents( 1, 1 );
			if ( is_wp_error( $fresh ) || $fresh['total'] !== (int) $row['parent_total'] ) {
				throw new RuntimeException( 'parent_total_changed' );
			}
			$row['phase'] = 'verify';
			return;
		}
		if ( $result['total'] !== (int) $row['parent_total'] ) {
			throw new RuntimeException( 'parent_total_changed' );
		}
		$pending = array();
		foreach ( $result['ids'] as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) || 'publish' !== $product->get_status() ) {
				throw new RuntimeException( 'parent_changed' );
			}
			$count = 0;
			if ( 'variable' === $product->get_type() ) {
				$children = self::children( $id, 1, 1 );
				if ( is_wp_error( $children ) ) {
					throw new RuntimeException( 'child_query_failed' );
				}
				$count = $children['total'];
				if ( $count > 0 ) {
					$pending[] = array( $id, $count );
				}
			}
			DPDA_Storage::replace_subject( $row['run_id'], $product, DPDA_Rules::evaluate( $product, $rules, $count ), $count );
		}
		if ( array() !== $pending ) {
			$row['phase']         = 'children';
			$row['pending_json']  = wp_json_encode( $pending );
			$row['pending_index'] = 0;
			$row['child_page']    = 1;
		} else {
			++$row['parent_page'];
		}
	}

	/** Process children.
	 *
	 * @param array $row row input.
	 * @param array $rules rules input.
	 * @throws RuntimeException When a product or owned-table read/write fails.
	 */
	private static function process_children( array &$row, array $rules ): void {
		$pending = json_decode( $row['pending_json'], true );
		$index   = (int) $row['pending_index'];
		if ( ! is_array( $pending ) || ! isset( $pending[ $index ] ) || count( $pending ) > self::PARENT_BATCH ) {
			throw new RuntimeException( 'pending_corrupt' );
		}
		$parent_id = (int) $pending[ $index ][0];
		$expected  = (int) $pending[ $index ][1];
		$result    = self::children( $parent_id, (int) $row['child_page'] );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'child_query_failed' );
		}
		if ( $expected !== $result['total'] || array() === $result['ids'] ) {
			throw new RuntimeException( 'child_total_changed' );
		}
		foreach ( $result['ids'] as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product_Variation || 'publish' !== $product->get_status() || $parent_id !== $product->get_parent_id() ) {
				throw new RuntimeException( 'child_changed' );
			}
			DPDA_Storage::replace_subject( $row['run_id'], $product, DPDA_Rules::evaluate( $product, $rules ) );
		}
		if ( (int) $row['child_page'] < $result['pages'] ) {
			++$row['child_page'];
			return;
		}
		++$row['pending_index'];
		$row['child_page'] = 1;
		if ( $row['pending_index'] >= count( $pending ) ) {
			$row['phase']         = 'parents';
			$row['pending_json']  = '[]';
			$row['pending_index'] = 0;
			++$row['parent_page'];
		}
	}

	/** Verify.
	 *
	 * @param array $row row input.
	 * @throws RuntimeException When catalog totals or scan records cannot be verified.
	 */
	private static function verify( array &$row ): void {
		global $wpdb;
		$parents     = self::parents( 1, 1 );
		$unsupported = self::unsupported_count();
		if ( is_wp_error( $parents ) || is_wp_error( $unsupported ) || $parents['total'] !== (int) $row['parent_total'] || $unsupported !== (int) $row['unsupported_total'] ) {
			throw new RuntimeException( 'catalog_total_changed' );
		}
		$t         = DPDA_Storage::tables();
		$offset    = ( (int) $row['verify_page'] - 1 ) * self::PARENT_BATCH;
		$variables = $wpdb->get_results( $wpdb->prepare( "SELECT subject_id,expected_children FROM {$t['ledger']} WHERE run_id = %s AND subject_type = 'variable' ORDER BY id ASC LIMIT %d OFFSET %d", $row['run_id'], self::PARENT_BATCH, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || ! is_array( $variables ) ) {
			throw new RuntimeException( 'verify_read_failed' );
		}
		foreach ( $variables as $variable ) {
			$children = self::children( (int) $variable['subject_id'], 1, 1 );
			if ( is_wp_error( $children ) || $children['total'] !== (int) $variable['expected_children'] ) {
				throw new RuntimeException( 'child_total_changed' );
			}
		}
		if ( count( $variables ) === self::PARENT_BATCH ) {
			++$row['verify_page'];
			return;
		}
		$parent_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id = %s AND subject_type IN ('simple','variable')", $row['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || $parent_count !== (int) $row['parent_total'] ) {
			throw new RuntimeException( 'parent_ledger_mismatch' );
		}
		$expected_children = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(expected_children),0) FROM {$t['ledger']} WHERE run_id = %s AND subject_type = 'variable'", $row['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || null === $expected_children ) {
			throw new RuntimeException( 'child_ledger_mismatch' );
		}
		$actual_children = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['ledger']} WHERE run_id = %s AND subject_type = 'variation'", $row['run_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || null === $actual_children || (int) $expected_children !== (int) $actual_children ) {
			throw new RuntimeException( 'child_ledger_mismatch' );
		}
		$row['phase']        = 'done';
		$row['status']       = 'complete';
		$row['completed_at'] = current_time( 'mysql', true );
	}

	/** Error code.
	 *
	 * @param string $message message input.
	 */
	private static function error_code( string $message ): string {
		$allowed = array( 'rules_changed', 'rules_corrupt', 'invalid_phase', 'parent_query_failed', 'parent_total_changed', 'parent_page_empty', 'parent_changed', 'child_query_failed', 'child_total_changed', 'child_changed', 'pending_corrupt', 'catalog_total_changed', 'verify_read_failed', 'parent_ledger_mismatch', 'child_ledger_mismatch', 'commit_failed', 'run_read_failed', 'ledger_write_failed', 'finding_write_failed', 'finding_delete_failed' );
		return in_array( $message, $allowed, true ) ? $message : 'scan_failed';
	}

	/** Cancel.
	 *
	 * @param string $run_id run id input.
	 */
	public static function cancel( string $run_id ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_is_uuid( $run_id ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot cancel this scan.', 'digicyfr-product-data-audit' ) );
		}
		if ( ! DPDA_Storage::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Serialized with resume.
			return new WP_Error( 'storage_failed', __( 'Could not start a safe scan transaction.', 'digicyfr-product-data-audit' ) );
		}
		$table = DPDA_Storage::tables()['runs'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE run_id = %s FOR UPDATE", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( '' !== $wpdb->last_error || ! is_array( $row ) || ! in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No changes.
			return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-product-data-audit' ) );
		}
		$ok = $wpdb->update(
			$table,
			array(
				'status'       => 'incomplete',
				'last_error'   => 'cancelled',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'run_id' => $run_id )
		);
		if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit cancellation.
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Revert failed cancellation.
			return new WP_Error( 'storage_failed', __( 'Could not cancel this scan safely.', 'digicyfr-product-data-audit' ) );
		}
		self::reconcile();
		$fresh = self::get( $run_id );
		return $fresh ? $fresh : new WP_Error( 'storage_failed', __( 'Cancellation committed but could not be read. Reload this page.', 'digicyfr-product-data-audit' ) );
	}
}
