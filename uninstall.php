<?php
/**
 * Uninstall — remove the plugin's table and options.
 *
 * @package ProblemClient
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'probclient_blacklist';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'probclient_db_version' );
