<?php
/**
 * Deleting the plugin removes its tables, options and the per-user list setting (seed files in data/ go with the
 * plugin files).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'mlg_translations', 'mlg_strings' ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own tables.
}
foreach ( array( 'mlg_settings', 'mlg_incomplete', 'mlg_db_version', 'mlg_scan_token', 'mlg_last_scan', 'mlg_scan_running' ) as $option ) {
	delete_option( $option );
}
foreach ( array( 'mlg_sitemap_urls', 'mlg_upgrade_error' ) as $transient ) {
	delete_transient( $transient );
}
delete_metadata( 'user', 0, 'mlg_per_page', '', true );
