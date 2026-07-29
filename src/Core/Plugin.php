<?php
/**
 * Plugin composition root.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Core;

use MRN\ContentBridge\Admin\Admin;
use MRN\ContentBridge\Admin\SocialMetaBox;
use MRN\ContentBridge\AI\OpenAIImageProvider;
use MRN\ContentBridge\AI\OpenAITextProvider;
use MRN\ContentBridge\AI\ProviderRegistry;
use MRN\ContentBridge\CLI\WorkerCommand;
use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Installer;
use MRN\ContentBridge\Infrastructure\Logger;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\BaleAdapter;
use MRN\ContentBridge\Platform\LinkedInAdapter;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Platform\TelegramAdapter;
use MRN\ContentBridge\Queue\JobQueue;
use MRN\ContentBridge\Queue\JobRouter;
use MRN\ContentBridge\Queue\Worker;
use MRN\ContentBridge\Workflow\ApprovalService;
use MRN\ContentBridge\Workflow\ArticleWorkflow;
use MRN\ContentBridge\Workflow\MediaImporter;
use MRN\ContentBridge\Workflow\MessagePoller;
use MRN\ContentBridge\Workflow\NotificationService;
use MRN\ContentBridge\Workflow\SocialPublisher;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted           = false;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::maybe_upgrade();
		load_plugin_textdomain( 'mrn-content-bridge', false, dirname( plugin_basename( MRNCB_FILE ) ) . '/languages' );

		$vault     = new SecretVault();
		$settings  = new Settings( $vault );
		$entities  = new EntityRepository( $vault );
		$logger    = new Logger();
		$queue     = new JobQueue();
		$platforms = new PlatformRegistry();
		$telegram  = new TelegramAdapter( $entities, $settings );
		$bale      = new BaleAdapter( $entities, $settings );
		$linkedin  = new LinkedInAdapter( $settings );
		$platforms->register( $telegram );
		$platforms->register( $bale );
		$platforms->register( $linkedin );

		$providers = new ProviderRegistry();
		$providers->register_text( new OpenAITextProvider( $settings ) );
		$providers->register_image( new OpenAIImageProvider( $settings ) );

		$poller        = new MessagePoller( $entities, $platforms, $queue, $settings, $logger );
		$media         = new MediaImporter( $platforms, $settings );
		$articles      = new ArticleWorkflow( $entities, $providers, $media, $queue, $settings );
		$approvals     = new ApprovalService( $entities, $platforms, $queue, $logger );
		$social        = new SocialPublisher( $entities, $platforms, $providers, $queue, $settings );
		$notifications = new NotificationService( $entities, $platforms );
		$router        = new JobRouter( $poller, $articles, $approvals, $social, $notifications );
		$worker        = new Worker( $queue, $router, $settings, $logger );

		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['mrncb_every_minute'] = array(
					'interval' => 60,
					'display'  => __( 'هر دقیقه — Content Bridge', 'mrn-content-bridge' ),
				);
				return $schedules;
			}
		);
		if ( ! wp_next_scheduled( 'mrncb_worker_tick' ) ) {
			wp_schedule_event( time() + 60, 'mrncb_every_minute', 'mrncb_worker_tick' );
		}
		add_action(
			'mrncb_worker_tick',
			static function () use ( $settings, $poller, $worker ): void {
				if ( $settings->get( 'enable_wp_cron', true ) ) {
					$poller->poll();
					$worker->run();
					global $wpdb;
					$retention = max( 1, (int) $settings->get( 'log_retention_days', 30 ) );
					$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mrncb_logs WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		);
		add_action( 'transition_post_status', array( $social, 'on_transition' ), 10, 3 );
		add_action( 'mrncb_social_settings_saved', array( $social, 'enqueue_for_post' ) );
		add_action( 'mrncb_approval_callback', array( $approvals, 'handle_callback_message' ) );

		if ( is_admin() ) {
			( new Admin( $settings, $entities, $platforms, $providers, $queue, $worker, $poller, $linkedin, $vault ) )->register();
			( new SocialMetaBox( $entities ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mrn-content-bridge worker', new WorkerCommand( $worker, $poller, $settings ) );
		}

		do_action( 'mrncb_loaded', $platforms, $providers );
	}
}
