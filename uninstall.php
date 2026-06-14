<?php
/**
 * Uninstall — remove the plugin's tables and options.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$riskybuyer_tables = array(
	$wpdb->prefix . 'riskybuyer_blacklist',
	$wpdb->prefix . 'riskybuyer_remote_cache',
);
foreach ( $riskybuyer_tables as $riskybuyer_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$riskybuyer_table}" );
}

delete_option( 'riskybuyer_db_version' );
delete_option( 'riskybuyer_settings' );
delete_option( 'riskybuyer_sync_state' );
