<?php
/**
 * One-time, short-lived WordPress login links requested from a bot source.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Logger;

defined( 'ABSPATH' ) || exit;

final class MagicLoginService {
	private const TOKEN_TTL = 60;

	public function __construct(
		private readonly EntityRepository $entities,
		private readonly Logger $logger
	) {}

	public function register(): void {
		add_action( 'admin_post_nopriv_mrncb_magic_login', array( $this, 'consume' ) );
		add_action( 'admin_post_mrncb_magic_login', array( $this, 'consume' ) );
	}

	/**
	 * Create a login link for the WordPress author assigned to an active bot source.
	 */
	public function create_for_message( object $message ): string {
		global $wpdb;
		$source = $this->entities->source( (int) $message->source_id );
		if ( ! $source || 'active' !== (string) ( $source->status ?? '' ) || ! in_array( (string) $source->platform, array( 'telegram', 'bale' ), true ) ) {
			throw new \RuntimeException( 'برای این پیام، منبع ربات فعال Telegram/Bale پیدا نشد.' );
		}
		if ( (string) $source->platform !== (string) $message->platform ) {
			throw new \RuntimeException( 'هویت پلتفرم پیام با منبع ربات یکسان نیست.' );
		}

		$payload   = json_decode( (string) $message->payload, true ) ?: array();
		$chat      = (array) ( $payload['message']['chat'] ?? array() );
		$chat_id   = (string) ( $chat['id'] ?? $message->chat_id ?? '' );
		$chat_type = sanitize_key( (string) ( $chat['type'] ?? '' ) );
		$actor_id  = (string) ( $payload['from']['id'] ?? $payload['message']['from']['id'] ?? '' );
		$source_chat_id = (string) ( $source->chat_id ?? '' );

		if ( 'private' !== $chat_type || '' === $source_chat_id || ! hash_equals( $source_chat_id, $chat_id ) || '' === $actor_id || ! hash_equals( $chat_id, $actor_id ) ) {
			throw new \RuntimeException( 'ورود سریع فقط در گفت‌وگوی خصوصیِ ثبت‌شده برای همین منبع قابل استفاده است.' );
		}

		$config  = $this->entities->config( $source );
		$user_id = absint( $config['author_id'] ?? 0 );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'read' ) ) {
			throw new \RuntimeException( 'کاربر وردپرس متصل به این منبع معتبر یا فعال نیست.' );
		}

		$token      = bin2hex( random_bytes( 32 ) );
		$token_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mrncb_magic_logins WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'mrncb_magic_logins',
			array(
				'token_hash'         => $token_hash,
				'source_id'          => (int) $source->id,
				'user_id'            => $user_id,
				'requested_platform' => (string) $message->platform,
				'requested_user_id'  => $actor_id,
				'expires_at'         => $expires_at,
				'created_at'         => $now,
			)
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( 'ساخت لینک ورود در دیتابیس ناموفق بود.' );
		}

		$this->logger->log(
			'info',
			'login',
			'لینک ورود یک‌بارمصرف از طریق ربات ساخته شد.',
			array( 'source_id' => (int) $source->id, 'user_id' => $user_id, 'expires_at' => $expires_at )
		);

		return add_query_arg(
			array(
				'action' => 'mrncb_magic_login',
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Atomically consume the bearer token and create a non-persistent WP session.
	 */
	public function consume(): void {
		global $wpdb;
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$this->deny();
		}

		$token_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$table      = $wpdb->prefix . 'mrncb_magic_logins';
		$record     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND consumed_at IS NULL LIMIT 1",
				$token_hash
			)
		);
		$now = current_time( 'mysql', true );
		if ( ! $record || strtotime( (string) $record->expires_at . ' UTC' ) < time() ) {
			$this->deny();
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at >= %s",
				$now,
				(int) $record->id,
				$now
			)
		);
		if ( 1 !== $claimed ) {
			$this->deny();
		}

		$user = get_user_by( 'id', (int) $record->user_id );
		if ( ! $user || ! user_can( $user, 'read' ) ) {
			$this->deny();
		}

		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		$this->logger->log(
			'info',
			'login',
			'ورود یک‌بارمصرف ربات با موفقیت مصرف شد.',
			array( 'source_id' => (int) $record->source_id, 'user_id' => (int) $user->ID )
		);
		wp_safe_redirect( admin_url() );
		exit;
	}

	private function deny(): never {
		wp_die(
			esc_html( 'این لینک ورود نامعتبر، منقضی یا قبلاً استفاده شده است.' ),
			esc_html( 'ورود سریع ناموفق بود' ),
			array( 'response' => 403 )
		);
	}
}
