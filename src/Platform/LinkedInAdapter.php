<?php
/**
 * LinkedIn official OAuth and Posts API adapter.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

use MRN\ContentBridge\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class LinkedInAdapter implements PlatformAdapterInterface {
	public function __construct( private readonly Settings $settings ) {}

	public function key(): string {
		return 'linkedin';
	}

	public function label(): string {
		return 'LinkedIn';
	}

	public function supports_inbound(): bool {
		return false;
	}

	/** @return array<int, NormalizedUpdate> */
	public function poll( object $source ): array {
		unset( $source );
		throw new \RuntimeException( 'LinkedIn getUpdates ندارد. دریافت پست فقط پس از تأیید Community Management API و مجوزهای رسمی ممکن است.' );
	}

	/** @return array{ok:bool,message:string,details?:array<string,mixed>} */
	public function test_connection( object $entity ): array {
		unset( $entity );
		$token = $this->settings->secret( 'linkedin_access_token' );
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'message' => 'ابتدا اتصال OAuth لینکدین را کامل کنید.',
			);
		}

		$response = wp_remote_get(
			'https://api.linkedin.com/v2/userinfo',
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
		$ok   = 200 === wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => $ok,
			'message' => $ok ? 'اتصال OAuth لینکدین معتبر است.' : sanitize_text_field( $body['message'] ?? 'خطای اتصال LinkedIn' ),
			'details' => $ok ? array( 'name' => $body['name'] ?? '' ) : array(),
		);
	}

	/** @param array<string, mixed> $content
	 *  @return array{external_id:string,response:array<string,mixed>}
	 */
	public function publish( object $destination, array $content ): array {
		$token  = $this->settings->secret( 'linkedin_access_token' );
		$author = (string) $destination->external_id;
		if ( '' === $token || '' === $author ) {
			throw new \RuntimeException( 'توکن OAuth یا Author URN لینکدین موجود نیست.' );
		}

		$payload   = array(
			'author'                    => $author,
			'commentary'                => wp_strip_all_tags( (string) ( $content['text'] ?? '' ) ),
			'visibility'                => 'PUBLIC',
			'distribution'              => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                 => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);
		$image_url = esc_url_raw( (string) ( $content['image_url'] ?? '' ) );
		if ( $image_url ) {
			$image_urn          = $this->upload_image( $image_url, $author, $token );
			$payload['content'] = array(
				'media' => array(
					'id'      => $image_urn,
					'altText' => sanitize_text_field( (string) ( $content['alt_text'] ?? '' ) ),
				),
			);
		}

		$response = wp_remote_post(
			'https://api.linkedin.com/rest/posts',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'Content-Type'              => 'application/json',
					'X-Restli-Protocol-Version' => '2.0.0',
					'Linkedin-Version'          => sanitize_text_field( $this->settings->get( 'linkedin_api_version', '202607' ) ),
				),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
		if ( 201 !== $code ) {
			throw new \RuntimeException( sanitize_text_field( $body['message'] ?? "LinkedIn HTTP {$code}" ) );
		}

		return array(
			'external_id' => (string) wp_remote_retrieve_header( $response, 'x-restli-id' ),
			'response'    => array(
				'status' => $code,
				'body'   => $body,
			),
		);
	}

	public function download_file( object $source, string $file_id ): string {
		unset( $source, $file_id );
		throw new \RuntimeException( 'دانلود ورودی LinkedIn در این نسخه فعال نیست.' );
	}

	private function upload_image( string $image_url, string $owner, string $token ): string {
		$headers    = array(
			'Authorization'             => 'Bearer ' . $token,
			'Content-Type'              => 'application/json',
			'X-Restli-Protocol-Version' => '2.0.0',
			'Linkedin-Version'          => sanitize_text_field( $this->settings->get( 'linkedin_api_version', '202607' ) ),
		);
		$initialize = wp_remote_post(
			'https://api.linkedin.com/rest/images?action=initializeUpload',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( array( 'initializeUploadRequest' => array( 'owner' => $owner ) ) ),
			)
		);
		if ( is_wp_error( $initialize ) ) {
			throw new \RuntimeException( esc_html( $initialize->get_error_message() ) );
		}
		$body       = json_decode( wp_remote_retrieve_body( $initialize ), true ) ?: array();
		$upload_url = (string) ( $body['value']['uploadUrl'] ?? '' );
		$image_urn  = (string) ( $body['value']['image'] ?? '' );
		if ( 200 !== wp_remote_retrieve_response_code( $initialize ) || '' === $upload_url || '' === $image_urn ) {
			throw new \RuntimeException( sanitize_text_field( $body['message'] ?? 'LinkedIn initializeUpload ناموفق بود.' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $image_url, 45 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( esc_html( $tmp->get_error_message() ) );
		}
		try {
			$binary = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $binary ) {
				throw new \RuntimeException( 'خواندن تصویر موقت LinkedIn ممکن نشد.' );
			}
			$upload = wp_remote_request(
				$upload_url,
				array(
					'method'  => 'PUT',
					'timeout' => 90,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
					'body'    => $binary,
				)
			);
			if ( is_wp_error( $upload ) || wp_remote_retrieve_response_code( $upload ) < 200 || wp_remote_retrieve_response_code( $upload ) >= 300 ) {
				throw new \RuntimeException( is_wp_error( $upload ) ? esc_html( $upload->get_error_message() ) : 'آپلود تصویر LinkedIn ناموفق بود.' );
			}
		} finally {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
		return $image_urn;
	}

	public function authorization_url(): string {
		$state = wp_create_nonce( 'mrncb_linkedin_oauth' );
		set_transient( 'mrncb_linkedin_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $this->settings->get( 'linkedin_client_id', '' ),
				'redirect_uri'  => $this->redirect_uri(),
				'state'         => $state,
				'scope'         => $this->settings->get( 'linkedin_scopes', 'openid profile w_member_social' ),
			),
			'https://www.linkedin.com/oauth/v2/authorization'
		);
	}

	public function exchange_code( string $code, string $state ): void {
		$expected = get_transient( 'mrncb_linkedin_oauth_state_' . get_current_user_id() );
		delete_transient( 'mrncb_linkedin_oauth_state_' . get_current_user_id() );
		if ( ! is_string( $expected ) || ! hash_equals( $expected, $state ) ) {
			throw new \RuntimeException( 'OAuth state نامعتبر یا منقضی شده است.' );
		}

		$response = wp_remote_post(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'timeout' => 30,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->settings->get( 'linkedin_client_id', '' ),
					'client_secret' => $this->settings->secret( 'linkedin_client_secret' ),
					'redirect_uri'  => $this->redirect_uri(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
		if ( empty( $body['access_token'] ) ) {
			throw new \RuntimeException( sanitize_text_field( $body['error_description'] ?? 'توکن OAuth دریافت نشد.' ) );
		}
		$this->settings->save( array( 'linkedin_access_token' => $body['access_token'] ) );
	}

	public function redirect_uri(): string {
		$configured = (string) $this->settings->get( 'linkedin_redirect_uri', '' );
		return $configured ?: admin_url( 'admin.php?page=mrncb-ai&mrncb_linkedin_callback=1' );
	}
}
