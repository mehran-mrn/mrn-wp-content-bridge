<?php
/**
 * OpenAI Responses API text provider.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

use MRN\ContentBridge\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class OpenAITextProvider implements TextProviderInterface {
	public function __construct( private readonly Settings $settings ) {}

	public function key(): string {
		return 'openai';
	}

	public function generate( TextGenerationRequest $request ): TextGenerationResult {
		$schema = 'social' === $request->purpose ? $this->social_schema() : $this->article_schema();
		$input  = $this->system_instruction( $request ) . "\n\n--- SOURCE ---\n" . $request->source_text;
		$body   = array(
			'model'             => (string) $this->settings->get( 'openai_text_model', 'gpt-5.6-terra' ),
			'input'             => $input,
			'max_output_tokens' => (int) $this->settings->get( 'openai_max_output_tokens', 6000 ),
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'mrn_content_bridge_result',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$json = $this->request( '/responses', $body );
		$text = $this->output_text( $json );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'پاسخ ساختاریافته OpenAI قابل خواندن نیست.' );
		}

		if ( 'social' === $request->purpose ) {
			return new TextGenerationResult( '', '', sanitize_textarea_field( (string) ( $data['text'] ?? '' ) ) );
		}

		$inline = array();
		foreach ( (array) ( $data['inline_images'] ?? array() ) as $image ) {
			$inline[] = array(
				'placeholder' => sanitize_key( (string) ( $image['placeholder'] ?? '' ) ),
				'prompt'      => sanitize_textarea_field( (string) ( $image['prompt'] ?? '' ) ),
				'alt'         => sanitize_text_field( (string) ( $image['alt'] ?? '' ) ),
				'caption'     => sanitize_text_field( (string) ( $image['caption'] ?? '' ) ),
			);
		}

		return new TextGenerationResult(
			sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ),
			wp_kses_post( (string) ( $data['content_html'] ?? '' ) ),
			array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['categories'] ?? array() ) ) ) ),
			array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) ) ),
			sanitize_textarea_field( (string) ( $data['featured_image_prompt'] ?? '' ) ),
			$inline,
			(int) ( $json['usage']['input_tokens'] ?? 0 ),
			(int) ( $json['usage']['output_tokens'] ?? 0 )
		);
	}

	/** @return array{ok:bool,message:string} */
	public function test_connection(): array {
		try {
			$this->request(
				'/responses',
				array(
					'model'             => (string) $this->settings->get( 'openai_text_model', 'gpt-5.6-terra' ),
					'input'             => 'Reply with exactly: OK',
					'max_output_tokens' => 16,
				)
			);
			return array(
				'ok'      => true,
				'message' => 'اتصال OpenAI و مدل متن معتبر است.',
			);
		} catch ( \Throwable $error ) {
			return array(
				'ok'      => false,
				'message' => $error->getMessage(),
			);
		}
	}

	private function system_instruction( TextGenerationRequest $request ): string {
		if ( 'social' === $request->purpose ) {
			return "Create one platform-ready social post in {$request->language}. Tone: {$request->tone}. Preserve factual accuracy. Return only the schema. " . $request->prompt;
		}

		return "Transform the source into a polished WordPress article in {$request->language}. "
			. 'Return clean semantic HTML only in content_html. Never use scripts, styles, iframes, forms, event handlers, markdown, or Gutenberg comments. '
			. 'Use image placeholders as <figure data-mrncb-placeholder="PLACEHOLDER"></figure> and declare each placeholder in inline_images. '
			. 'Do not fabricate facts. Select concise category and tag names. '
			. $request->prompt;
	}

	/** @return array<string, mixed> */
	private function article_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'excerpt', 'content_html', 'categories', 'tags', 'featured_image_prompt', 'inline_images' ),
			'properties'           => array(
				'title'                 => array( 'type' => 'string' ),
				'excerpt'               => array( 'type' => 'string' ),
				'content_html'          => array( 'type' => 'string' ),
				'categories'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'tags'                  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'featured_image_prompt' => array( 'type' => 'string' ),
				'inline_images'         => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'placeholder', 'prompt', 'alt', 'caption' ),
						'properties'           => array(
							'placeholder' => array( 'type' => 'string' ),
							'prompt'      => array( 'type' => 'string' ),
							'alt'         => array( 'type' => 'string' ),
							'caption'     => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function social_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'text' ),
			'properties'           => array( 'text' => array( 'type' => 'string' ) ),
		);
	}

	/** @param array<string, mixed> $body
	 *  @return array<string, mixed>
	 */
	private function request( string $path, array $body ): array {
		$key = $this->settings->secret( 'openai_api_key' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'کلید API سرویس OpenAI ثبت نشده است.' );
		}
		$base     = untrailingslashit( (string) $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) );
		$response = wp_remote_post(
			$base . $path,
			array(
				'timeout' => (int) $this->settings->get( 'openai_timeout', 90 ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			throw new \RuntimeException( sanitize_text_field( $json['error']['message'] ?? "OpenAI HTTP {$code}" ) );
		}
		return $json;
	}

	/** @param array<string, mixed> $response */
	private function output_text( array $response ): string {
		foreach ( (array) ( $response['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) && isset( $content['text'] ) ) {
					return (string) $content['text'];
				}
			}
		}
		throw new \RuntimeException( sanitize_text_field( $response['error']['message'] ?? 'OpenAI متن خروجی برنگرداند.' ) );
	}
}
