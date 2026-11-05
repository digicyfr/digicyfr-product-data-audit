<?php
/**
 * Product data audit component.
 *
 * @package Digicyfr_Product_Data_Audit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$dpda_tables = array( $wpdb->prefix . 'dpda_findings', $wpdb->prefix . 'dpda_ledger', $wpdb->prefix . 'dpda_runs' );
foreach ( $dpda_tables as $dpda_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS $dpda_table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall drops fixed plugin-owned tables.
}
foreach ( array( 'dpda_rules_v1', 'dpda_state_v1', 'dpda_start_lock', 'dpda_schema_version' ) as $dpda_option ) {
	delete_option( $dpda_option );
}
