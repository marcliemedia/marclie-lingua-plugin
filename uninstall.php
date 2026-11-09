<?php
/**
 * Deleting the plugin removes its table and options (the shipped data/en.json goes with the files).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mlg_strings' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
foreach ( array( 'mlg_db_version', 'mlg_scan_token', 'mlg_last_scan', 'mlg_scan_running' ) as $option ) {
	delete_option( $option );
}
