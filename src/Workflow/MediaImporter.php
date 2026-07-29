<?php
/**
 * Validated platform media import into WordPress.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Platform\PlatformRegistry;

defined( 'ABSPATH' ) || exit;

final class MediaImporter {
	public function __construct(
		private readonly PlatformRegistry $platforms,
		private readonly Settings $settings
	) {}

	/** @param array<string, mixed> $payload */
	public function import( object $source, array $payload, int $post_id = 0 ): ?int {
		$file = $this->file_descriptor( $payload );
		if ( ! $file ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $this->platforms->get( (string) $source->platform )->download_file( $source, $file['id'] );
		try {
			$size = filesize( $tmp );
			if ( false === $size || $size > (int) $this->settings->get( 'max_media_bytes', 20971520 ) ) {
				throw new \RuntimeException( 'حجم فایل از سقف مجاز بیشتر است.' );
			}

			$mime    = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
			$allowed = get_allowed_mime_types();
			if ( $mime && ! in_array( $mime, $allowed, true ) ) {
				throw new \RuntimeException( 'نوع فایل دریافتی در وردپرس مجاز نیست.' );
			}

			$sideload = array(
				'name'     => sanitize_file_name( $file['name'] ),
				'tmp_name' => $tmp,
			);
			$id       = media_handle_sideload( $sideload, $post_id, sanitize_text_field( (string) ( $payload['caption'] ?? '' ) ) );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( esc_html( $id->get_error_message() ) );
			}
			return (int) $id;
		} finally {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/** @param array<string, mixed> $payload
	 *  @return array{id:string,name:string}|null
	 */
	private function file_descriptor( array $payload ): ?array {
		if ( ! empty( $payload['photos'] ) ) {
			$photo = end( $payload['photos'] );
			return array(
				'id'   => (string) ( $photo['file_id'] ?? '' ),
				'name' => 'telegram-photo-' . wp_generate_uuid4() . '.jpg',
			);
		}
		foreach ( array(
			'video'    => 'mp4',
			'document' => 'bin',
		) as $key => $extension ) {
			if ( ! empty( $payload[ $key ]['file_id'] ) ) {
				return array(
					'id'   => (string) $payload[ $key ]['file_id'],
					'name' => (string) ( $payload[ $key ]['file_name'] ?? "{$key}-" . wp_generate_uuid4() . ".{$extension}" ),
				);
			}
		}
		return null;
	}
}
