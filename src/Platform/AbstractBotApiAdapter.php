<?php
/**
 * Shared Telegram-compatible Bot API implementation.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\EntityRepository;

defined( 'ABSPATH' ) || exit;

abstract class AbstractBotApiAdapter implements PlatformAdapterInterface {
	public function __construct(
		protected readonly EntityRepository $entities,
		protected readonly Settings $settings
	) {}

	abstract protected function api_base(): string;

	public function supports_inbound(): bool {
		return true;
	}

	/** @return array<int, NormalizedUpdate> */
	public function poll( object $source ): array {
		$credentials = $this->entities->credentials( $source );
		$timeout     = max( 1, min( 50, (int) $this->settings->get( 'poll_interval', 30 ) ) );
		$result      = $this->request(
			(string) ( $credentials['token'] ?? '' ),
			'getUpdates',
			array(
				'offset'          => (int) $source->last_update_id + 1,
				'limit'           => 100,
				'timeout'         => $timeout,
				'allowed_updates' => wp_json_encode( array( 'message', 'edited_message', 'channel_post', 'edited_channel_post', 'callback_query' ) ),
			),
			$timeout + 8
		);

		$updates = array();
		foreach ( (array) ( $result['result'] ?? array() ) as $raw ) {
			$normalized = $this->normalize( (array) $raw );
			if ( $normalized ) {
				$updates[] = $normalized;
			}
		}
		return $updates;
	}

	public function test_connection( object $entity ): array {
		try {
			$credentials = $this->entities->credentials( $entity );
			$result      = $this->request( (string) ( $credentials['token'] ?? '' ), 'getMe' );
			$name        = $result['result']['username'] ?? $result['result']['first_name'] ?? $this->label();
			return array(
				'ok'      => true,
				'message' => sprintf( 'اتصال موفق به %s', $name ),
				'details' => array( 'bot' => $name ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'ok'      => false,
				'message' => $error->getMessage(),
			);
		}
	}

	/** @param array<string, mixed> $content
	 *  @return array{external_id:string,response:array<string,mixed>}
	 */
	public function publish( object $destination, array $content ): array {
		$credentials = $this->entities->credentials( $destination );
		$token       = (string) ( $credentials['token'] ?? '' );
		$text        = trim( (string) ( $content['text'] ?? '' ) );
		$image       = esc_url_raw( (string) ( $content['image_url'] ?? '' ) );

		if ( $image ) {
			$body = array(
				'chat_id'    => (string) $destination->external_id,
				'photo'      => $image,
				'caption'    => mb_substr( $text, 0, 1024 ),
				'parse_mode' => 'HTML',
			);
			if ( ! empty( $content['reply_markup'] ) ) {
				$body['reply_markup'] = wp_json_encode( $content['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$result = $this->request(
				$token,
				'sendPhoto',
				$body
			);
		} else {
			$body = array(
				'chat_id'                  => (string) $destination->external_id,
				'text'                     => mb_substr( $text, 0, 4096 ),
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => false,
			);
			if ( ! empty( $content['reply_markup'] ) ) {
				$body['reply_markup'] = wp_json_encode( $content['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$result = $this->request(
				$token,
				'sendMessage',
				$body
			);
		}

		return array(
			'external_id' => (string) ( $result['result']['message_id'] ?? '' ),
			'response'    => $result,
		);
	}

	public function download_file( object $source, string $file_id ): string {
		$credentials = $this->entities->credentials( $source );
		$token       = (string) ( $credentials['token'] ?? '' );
		$file        = $this->request( $token, 'getFile', array( 'file_id' => $file_id ) );
		$path        = (string) ( $file['result']['file_path'] ?? '' );
		if ( '' === $path ) {
			throw new \RuntimeException( 'مسیر فایل از API دریافت نشد.' );
		}

		$file_url = match ( $this->key() ) {
			'telegram' => 'https://api.telegram.org/file/bot' . rawurlencode( $token ) . '/' . ltrim( $path, '/' ),
			'bale'     => 'https://tapi.bale.ai/file/bot' . rawurlencode( $token ) . '/' . ltrim( $path, '/' ),
			default    => trailingslashit( $this->api_base() . rawurlencode( $token ) . '/file' ) . ltrim( $path, '/' ),
		};

		$tmp = download_url( $file_url, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( esc_html( $tmp->get_error_message() ) );
		}
		return $tmp;
	}

	public function answer_callback( object $source, string $callback_id, string $text = '' ): void {
		$credentials = $this->entities->credentials( $source );
		$this->request(
			(string) ( $credentials['token'] ?? '' ),
			'answerCallbackQuery',
			array(
				'callback_query_id' => $callback_id,
				'text'              => mb_substr( wp_strip_all_tags( $text ), 0, 180 ),
			)
		);
	}

	/** @param array<string, mixed> $raw */
	protected function normalize( array $raw ): ?NormalizedUpdate {
		$update_id = (int) ( $raw['update_id'] ?? 0 );
		if ( isset( $raw['callback_query'] ) ) {
			$callback = (array) $raw['callback_query'];
			$message  = (array) ( $callback['message'] ?? array() );
			return new NormalizedUpdate(
				$update_id,
				(string) ( $message['message_id'] ?? '' ),
				'',
				(string) ( $message['chat']['id'] ?? '' ),
				'callback',
				array(
					'callback_id' => (string) ( $callback['id'] ?? '' ),
					'data'        => (string) ( $callback['data'] ?? '' ),
					'from'        => (array) ( $callback['from'] ?? array() ),
					'message'     => $message,
					'raw'         => $raw,
				)
			);
		}

		$message_key = '';
		foreach ( array( 'message', 'edited_message', 'channel_post', 'edited_channel_post' ) as $candidate ) {
			if ( isset( $raw[ $candidate ] ) ) {
				$message_key = $candidate;
				break;
			}
		}
		if ( '' === $message_key ) {
			return null;
		}

		$message = (array) $raw[ $message_key ];
		$type    = $this->detect_type( $message, $message_key );

		return new NormalizedUpdate(
			$update_id,
			(string) ( $message['message_id'] ?? '' ),
			(string) ( $message['media_group_id'] ?? '' ),
			(string) ( $message['chat']['id'] ?? '' ),
			$type,
			array(
				'text'      => (string) ( $message['text'] ?? $message['caption'] ?? '' ),
				'caption'   => (string) ( $message['caption'] ?? '' ),
				'entities'  => (array) ( $message['entities'] ?? $message['caption_entities'] ?? array() ),
				'photos'    => (array) ( $message['photo'] ?? array() ),
				'video'     => (array) ( $message['video'] ?? array() ),
				'document'  => (array) ( $message['document'] ?? array() ),
				'forwarded' => isset( $message['forward_origin'] ) || isset( $message['forward_from'] ) || isset( $message['forward_from_chat'] ),
				'edited'    => str_starts_with( $message_key, 'edited_' ),
				'channel'   => str_contains( $message_key, 'channel_post' ),
				'message'   => $message,
				'raw'       => $raw,
			)
		);
	}

	/** @param array<string, mixed> $message */
	private function detect_type( array $message, string $message_key ): string {
		if ( isset( $message['photo'] ) ) {
			return 'photo';
		}
		if ( isset( $message['video'] ) ) {
			return 'video';
		}
		if ( isset( $message['document'] ) ) {
			return 'document';
		}
		if ( str_contains( (string) ( $message['text'] ?? '' ), 'http://' ) || str_contains( (string) ( $message['text'] ?? '' ), 'https://' ) ) {
			return 'link';
		}
		if ( isset( $message['forward_origin'] ) || isset( $message['forward_from'] ) || isset( $message['forward_from_chat'] ) ) {
			return 'forwarded';
		}
		if ( str_contains( $message_key, 'channel_post' ) ) {
			return str_starts_with( $message_key, 'edited_' ) ? 'edited_channel_post' : 'channel_post';
		}
		return 'text';
	}

	/** @param array<string, mixed> $body
	 *  @return array<string, mixed>
	 */
	protected function request( string $token, string $method, array $body = array(), int $timeout = 30 ): array {
		if ( '' === $token ) {
			throw new \InvalidArgumentException( 'توکن ربات ثبت نشده است.' );
		}
		$url      = trailingslashit( $this->api_base() . rawurlencode( $token ) ) . $method;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || empty( $json['ok'] ) ) {
			throw new \RuntimeException( sanitize_text_field( $json['description'] ?? "Bot API HTTP {$code}" ) );
		}
		return $json;
	}
}
