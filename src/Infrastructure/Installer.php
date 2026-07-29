<?php
/**
 * Database schema and lifecycle.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Installer {
	public const DB_VERSION = '1.0.0';

	public static function activate(): void {
		self::install_schema();
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['mrncb_every_minute'] = array(
					'interval' => 60,
					'display'  => 'Every minute (MRN Content Bridge)',
				);
				return $schedules;
			}
		);
		if ( ! wp_next_scheduled( 'mrncb_worker_tick' ) ) {
			wp_schedule_event( time() + 60, 'mrncb_every_minute', 'mrncb_worker_tick' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mrncb_worker_tick' );
		delete_option( 'mrncb_worker_lock' );
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( 'mrncb_db_version' ) ) {
			self::install_schema();
		}
	}

	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'mrncb_';

		$sql = array(
			"CREATE TABLE {$p}sources (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				platform varchar(32) NOT NULL,
				chat_id varchar(191) NOT NULL DEFAULT '',
				credentials longtext NULL,
				config longtext NULL,
				status varchar(24) NOT NULL DEFAULT 'active',
				last_update_id bigint NOT NULL DEFAULT 0,
				last_polled_at datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY platform_status (platform,status),
				KEY chat_id (chat_id)
			) {$charset};",
			"CREATE TABLE {$p}destinations (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				platform varchar(32) NOT NULL,
				external_id varchar(191) NOT NULL,
				credentials longtext NULL,
				config longtext NULL,
				status varchar(24) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY platform_status (platform,status)
			) {$charset};",
			"CREATE TABLE {$p}messages (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint unsigned NOT NULL,
				platform varchar(32) NOT NULL,
				update_id bigint NOT NULL,
				external_message_id varchar(191) NOT NULL DEFAULT '',
				media_group_id varchar(191) NOT NULL DEFAULT '',
				chat_id varchar(191) NOT NULL DEFAULT '',
				message_type varchar(40) NOT NULL DEFAULT 'text',
				payload longtext NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'received',
				received_at datetime NOT NULL,
				processed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_update (source_id,update_id),
				KEY media_group (source_id,media_group_id),
				KEY status (status)
			) {$charset};",
			"CREATE TABLE {$p}jobs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				type varchar(64) NOT NULL,
				payload longtext NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				attempts smallint unsigned NOT NULL DEFAULT 0,
				max_attempts smallint unsigned NOT NULL DEFAULT 3,
				available_at datetime NOT NULL,
				locked_at datetime NULL,
				locked_by varchar(191) NULL,
				last_error longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY reservable (status,available_at),
				KEY type (type)
			) {$charset};",
			"CREATE TABLE {$p}workflows (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint unsigned NOT NULL,
				source_message_id bigint unsigned NOT NULL,
				post_id bigint unsigned NULL,
				status varchar(40) NOT NULL DEFAULT 'received',
				context longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_message (source_message_id),
				KEY post_id (post_id),
				KEY status (status)
			) {$charset};",
			"CREATE TABLE {$p}social_posts (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				platform varchar(32) NOT NULL,
				destination_id bigint unsigned NOT NULL,
				post_id bigint unsigned NOT NULL,
				external_post_id varchar(191) NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				attempt_count smallint unsigned NOT NULL DEFAULT 0,
				response longtext NULL,
				published_at datetime NULL,
				last_error longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_destination (post_id,destination_id),
				KEY status (status)
			) {$charset};",
			"CREATE TABLE {$p}approvers (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				platform varchar(32) NOT NULL,
				chat_id varchar(191) NOT NULL,
				user_id varchar(191) NOT NULL,
				name varchar(191) NOT NULL DEFAULT '',
				access_level varchar(40) NOT NULL DEFAULT 'publisher',
				status varchar(24) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY platform_user (platform,user_id)
			) {$charset};",
			"CREATE TABLE {$p}audit_logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				actor varchar(191) NOT NULL,
				action varchar(64) NOT NULL,
				old_status varchar(40) NULL,
				new_status varchar(40) NULL,
				message_id varchar(191) NULL,
				post_id bigint unsigned NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY created_at (created_at)
			) {$charset};",
			"CREATE TABLE {$p}logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				level varchar(16) NOT NULL,
				channel varchar(40) NOT NULL,
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY channel (channel),
				KEY created_at (created_at)
			) {$charset};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'mrncb_db_version', self::DB_VERSION, false );
	}
}
