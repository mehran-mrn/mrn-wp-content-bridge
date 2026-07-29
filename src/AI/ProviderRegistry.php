<?php
/**
 * Extensible AI provider registry.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	/** @var array<string, TextProviderInterface> */
	private array $text = array();

	/** @var array<string, ImageProviderInterface> */
	private array $image = array();

	public function register_text( TextProviderInterface $provider ): void {
		$this->text[ $provider->key() ] = $provider;
	}

	public function register_image( ImageProviderInterface $provider ): void {
		$this->image[ $provider->key() ] = $provider;
	}

	public function text( string $key = 'openai' ): TextProviderInterface {
		$providers = apply_filters( 'mrncb_text_providers', $this->text );
		if ( ! isset( $providers[ $key ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Text provider not found: {$key}" ) );
		}
		return $providers[ $key ];
	}

	public function image( string $key = 'openai' ): ImageProviderInterface {
		$providers = apply_filters( 'mrncb_image_providers', $this->image );
		if ( ! isset( $providers[ $key ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Image provider not found: {$key}" ) );
		}
		return $providers[ $key ];
	}
}
