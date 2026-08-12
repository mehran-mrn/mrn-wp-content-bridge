<?php
/**
 * OpenAI Images API provider.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

use MRN\ContentBridge\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class OpenAIImageProvider implements ImageProviderInterface {
	public function __construct( private readonly Settings $settings ) {}

	public function key(): string {
		return 'openai';
	}

	public function generate( ImageGenerationRequest $request ): ImageGenerationResult {
		$key = $this->settings->secret( 'openai_api_key' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'کلید API سرویس OpenAI ثبت نشده است.' );
		}

		$format   = in_array( $request->format, array( 'png', 'webp', 'jpeg' ), true ) ? $request->format : 'webp';
		$payload  = array(
			'model'         => (string) $this->settings->get( 'openai_image_model', 'gpt-image-2' ),
			'prompt'        => trim( (string) $this->settings->get( 'image_base_prompt', '' ) . "\n" . (string) $this->settings->get( 'image_style_prompt', '' ) . "\n" . $request->prompt ),
			'n'             => 1,
			'size'          => $request->size,
			'quality'       => $request->quality,
			'output_format' => $format,
		);
		$response = wp_remote_post(
			untrailingslashit( (string) $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) ) . '/images/generations',
			array(
				'timeout' => max( 120, (int) $this->settings->get( 'openai_timeout', 90 ) ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || empty( $json['data'][0]['b64_json'] ) ) {
			throw new \RuntimeException( sanitize_text_field( $json['error']['message'] ?? "OpenAI Images HTTP {$code}" ) );
		}

		$binary = base64_decode( (string) $json['data'][0]['b64_json'], true );
		if ( false === $binary ) {
			throw new \RuntimeException( 'داده تصویر OpenAI نامعتبر است.' );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = \wp_tempnam( 'mrncb-generated.' . $format );
		if ( ! $tmp || false === file_put_contents( $tmp, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'ذخیره موقت تصویر ممکن نشد.' );
		}

		return new ImageGenerationResult(
			$tmp,
			sanitize_textarea_field( (string) ( $json['data'][0]['revised_prompt'] ?? '' ) ),
			'image/' . ( 'jpeg' === $format ? 'jpeg' : $format )
		);
	}
}
