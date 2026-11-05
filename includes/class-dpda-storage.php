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
final class DPDA_Storage {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned report tables require transactional rows and locks.
	/** Tables. */
	public static function tables(): array {
		global $wpdb;
		return array(
			'runs'     => $wpdb->prefix . 'dpda_runs',
			'ledger'   => $wpdb->prefix . 'dpda_ledger',
			'findings' => $wpdb->prefix . 'dpda_findings',
		);
	}

	/** Install. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::tables();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			array(
				"CREATE TABLE {$t['runs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				status varchar(20) NOT NULL,
				phase varchar(20) NOT NULL,
				rules_json longtext NOT NULL,
				rules_hash char(64) NOT NULL,
				parent_page bigint(20) unsigned NOT NULL DEFAULT 1,
				pending_json text NOT NULL,
				pending_index smallint(5) unsigned NOT NULL DEFAULT 0,
				child_page bigint(20) unsigned NOT NULL DEFAULT 1,
				verify_page bigint(20) unsigned NOT NULL DEFAULT 1,
				parent_total bigint(20) unsigned NOT NULL DEFAULT 0,
				unsupported_total bigint(20) unsigned NOT NULL DEFAULT 0,
				started_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				last_error varchar(80) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_id (run_id),
				KEY status (status)
			) ENGINE=InnoDB $c",
				"CREATE TABLE {$t['ledger']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				subject_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subject_type varchar(20) NOT NULL,
				modified_gmt datetime NULL,
				expected_children bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY subject (run_id,subject_id),
				KEY verify_parent (run_id,subject_type,id)
			) ENGINE=InnoDB $c",
				"CREATE TABLE {$t['findings']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				subject_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subject_type varchar(20) NOT NULL,
				rule_key varchar(80) NOT NULL,
				product_name varchar(255) NOT NULL,
				sku varchar(255) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY subject_rule (run_id,subject_id,rule_key),
				KEY report (run_id,id)
			) ENGINE=InnoDB $c",
			)
		);
		if ( ! self::transactional() ) {
			wp_die( esc_html__( 'Product Data Audit requires InnoDB tables for safe resumable scans.', 'digicyfr-product-data-audit' ) );
		}
		add_option( DPDA_Rules::OPTION, array(), '', false );
		add_option( 'dpda_state_v1', array(), '', false );
		update_option( 'dpda_schema_version', DIGICYFR_PRODUCT_DATA_AUDIT_VERSION, false );
	}

	/** Transactional. */
	public static function transactional(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( '' !== $wpdb->last_error || 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace one subject, including a zero-finding ledger row, inside the caller's transaction.
	 *
	 * @param string     $run_id Run UUID.
	 * @param WC_Product $product Product to record.
	 * @param array      $keys Missing rule keys.
	 * @param int        $expected_children Published child total for a parent.
	 * @throws RuntimeException When an owned-table write fails.
	 */
	public static function replace_subject( string $run_id, WC_Product $product, array $keys, int $expected_children = 0 ): void {
		global $wpdb;
		$t      = self::tables();
		$id     = $product->get_id();
		$parent = 'variation' === $product->get_type() ? $product->get_parent_id() : 0;
		$date   = $product->get_date_modified();
		if ( false === $wpdb->delete(
			$t['findings'],
			array(
				'run_id'     => $run_id,
				'subject_id' => $id,
			)
		) ) {
			throw new RuntimeException( 'finding_delete_failed' );
		}
		if ( false === $wpdb->replace(
			$t['ledger'],
			array(
				'run_id'            => $run_id,
				'subject_id'        => $id,
				'parent_id'         => $parent,
				'subject_type'      => $product->get_type(),
				'modified_gmt'      => $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : null,
				'expected_children' => $expected_children,
			)
		) ) {
			throw new RuntimeException( 'ledger_write_failed' );
		}
		foreach ( $keys as $key ) {
			if ( false === $wpdb->insert(
				$t['findings'],
				array(
					'run_id'       => $run_id,
					'subject_id'   => $id,
					'parent_id'    => $parent,
					'subject_type' => $product->get_type(),
					'rule_key'     => $key,
					'product_name' => self::short( $product->get_name() ),
					'sku'          => self::short( 'variation' === $product->get_type() ? $product->get_sku( 'edit' ) : $product->get_sku() ),
				)
			) ) {
				throw new RuntimeException( 'finding_write_failed' );
			}
		}
	}

	/** Short.
	 *
	 * @param string $value value input.
	 */
	private static function short( string $value ): string {
		$value = sanitize_text_field( $value );
		return preg_match( '/^.{0,255}/us', $value, $matches ) ? $matches[0] : '';
	}

	/** Keep latest completed and current run; cleanup failure never promotes a report. */
	public static function prune(): void {
		global $wpdb;
		$t     = self::tables();
		$state = DPDA_Scan::reconcile();
		if ( is_wp_error( $state ) ) {
			return;
		}
		$keep = array_filter( array( $state['state']['latest_id'], $state['state']['latest_complete_id'] ) );
		if ( array() === $keep ) {
			return;
		}
		$marks = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
		foreach ( array( 'findings', 'ledger', 'runs' ) as $key ) {
			$table = $t[ $key ];
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE run_id NOT IN ($marks)", ...$keep ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed plugin table and generated placeholders.
		}
	}
}
