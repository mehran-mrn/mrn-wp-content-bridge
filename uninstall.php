<?php
/**
 * Optional uninstall cleanup.
 *
 * Data is preserved by default. Set MRNCB_REMOVE_DATA_ON_UNINSTALL to true
 * before uninstalling to remove plugin tables and options.
 *
 * @package MRN\ContentBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'MRNCB_REMOVE_DATA_ON_UNINSTALL' ) || true !== MRNCB_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;

$tables = array(
	'mrncb_sources',
	'mrncb_destinations',
	'mrncb_messages',
	'mrncb_jobs',
	'mrncb_workflows',
	'mrncb_social_posts',
	'mrncb_approvers',
	'mrncb_audit_logs',
	'mrncb_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'mrncb_settings' );
delete_option( 'mrncb_db_version' );
delete_option( 'mrncb_worker_lock' );
delete_option( 'mrncb_last_worker_success' );
