<?php
/**
 * WP-CLI worker command.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\CLI;

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Queue\Worker;
use MRN\ContentBridge\Workflow\MessagePoller;

defined( 'ABSPATH' ) || exit;

final class WorkerCommand {
	public function __construct(
		private readonly Worker $worker,
		private readonly MessagePoller $poller,
		private readonly Settings $settings
	) {}

	/**
	 * Run the Content Bridge polling and queue worker.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Jobs processed per pass.
	 *
	 * [--loop]
	 * : Continue until terminated; recommended under Supervisor/systemd.
	 *
	 * [--sleep=<seconds>]
	 * : Delay between continuous passes. Default: configured polling interval.
	 *
	 * [--quiet]
	 * : Suppress successful pass output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mrn-content-bridge worker
	 *     wp mrn-content-bridge worker --loop --sleep=5
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$loop  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'loop', false );
		$quiet = \WP_CLI\Utils\get_flag_value( $assoc_args, 'quiet', false );
		$batch = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : null;
		$sleep = max( 1, absint( $assoc_args['sleep'] ?? $this->settings->get( 'poll_interval', 30 ) ) );

		do {
			$received = $this->poller->poll();
			$result   = $this->worker->run( $batch );
			if ( ! $quiet ) {
				\WP_CLI::log(
					sprintf(
						'Received: %d | Processed: %d | Failed: %d | Locked: %s',
						$received,
						$result['processed'],
						$result['failed'],
						$result['locked'] ? 'yes' : 'no'
					)
				);
			}
			if ( $loop ) {
				sleep( $sleep );
			}
		} while ( $loop );
	}
}
