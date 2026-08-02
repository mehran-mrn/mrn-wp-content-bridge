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

		$adapter    = $this->platforms->get( (string) $source->platform );
		$tmp        = $adapter->download_file( $source, $file['id'] );
		$converted  = '';
		$poster_tmp = '';
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

			$upload_tmp  = $tmp;
			$upload_name = sanitize_file_name( $file['name'] );
			if ( 'video' === $file['type'] || str_starts_with( (string) $mime, 'video/' ) ) {
				$poster_tmp = $this->video_poster_file( $source, $file, $tmp );
			}
			if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				$editor = wp_get_image_editor( $tmp );
				if ( is_wp_error( $editor ) ) {
					throw new \RuntimeException( 'تبدیل تصویر به WebP در این سرور پشتیبانی نمی‌شود: ' . esc_html( $editor->get_error_message() ) );
				}
				$converted = wp_tempnam( 'mrncb-image.webp' );
				if ( ! $converted ) {
					throw new \RuntimeException( 'فایل موقت لازم برای تبدیل WebP ساخته نشد.' );
				}
				$saved     = $editor->save( $converted, 'image/webp' );
				if ( is_wp_error( $saved ) ) {
					throw new \RuntimeException( 'تبدیل تصویر به WebP ناموفق بود: ' . esc_html( $saved->get_error_message() ) );
				}
				$upload_tmp  = (string) ( $saved['path'] ?? $converted );
				$converted   = $upload_tmp;
				$upload_name = pathinfo( $upload_name, PATHINFO_FILENAME ) . '.webp';
			}

			$sideload = array(
				'name'     => $upload_name,
				'tmp_name' => $upload_tmp,
			);
			$id       = media_handle_sideload( $sideload, $post_id, sanitize_text_field( (string) ( $payload['caption'] ?? '' ) ) );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( esc_html( $id->get_error_message() ) );
			}
			if ( wp_attachment_is_image( (int) $id ) ) {
				$alt_source = (string) ( $payload['caption'] ?? $payload['text'] ?? '' );
				if ( '' === trim( $alt_source ) && $post_id ) {
					$alt_source = get_the_title( $post_id );
				}
				$alt = wp_trim_words( wp_strip_all_tags( $alt_source ), 18, '…' );
				update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			}
			if ( ! wp_attachment_is_image( (int) $id ) && '' !== $poster_tmp ) {
				$poster_id = $this->attach_video_poster( $poster_tmp, $upload_name, $post_id, $payload );
				if ( $poster_id ) {
					update_post_meta( (int) $id, '_mrncb_video_poster_id', $poster_id );
					update_post_meta( (int) $id, '_thumbnail_id', $poster_id );
				}
			}
			return (int) $id;
		} finally {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( '' !== $converted && file_exists( $converted ) ) {
				wp_delete_file( $converted );
			}
			if ( '' !== $poster_tmp && file_exists( $poster_tmp ) ) {
				wp_delete_file( $poster_tmp );
			}
		}
	}

	public function poster_id( int $attachment_id ): int {
		$poster_id = absint( get_post_meta( $attachment_id, '_mrncb_video_poster_id', true ) );
		return $poster_id ?: absint( get_post_thumbnail_id( $attachment_id ) );
	}

	public function content_block( int $attachment_id ): string {
		if ( wp_attachment_is_image( $attachment_id ) ) {
			return '<figure class="wp-block-image size-large">' . wp_get_attachment_image( $attachment_id, 'large' ) . '</figure>';
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );
		if ( str_starts_with( $mime, 'video/' ) && $url ) {
			$attributes = ' src="' . esc_url( $url ) . '" preload="metadata"';
			$poster_id  = $this->poster_id( $attachment_id );
			$poster_url = $poster_id ? wp_get_attachment_image_url( $poster_id, 'full' ) : false;
			if ( $poster_url ) {
				$attributes .= ' poster="' . esc_url( $poster_url ) . '"';
			}

			return "<!-- wp:shortcode -->\n[video{$attributes}][/video]\n<!-- /wp:shortcode -->";
		}

		return (string) wp_get_attachment_link( $attachment_id );
	}

	/**
	 * Prefer the thumbnail supplied by the messaging platform, then fall back to
	 * extracting an actual frame with FFmpeg when it is available on the host.
	 *
	 * @param array{id:string,name:string,type:string,thumbnail_id:string} $file
	 */
	private function video_poster_file( object $source, array $file, string $video_path ): string {
		if ( '' !== $file['thumbnail_id'] ) {
			try {
				$thumbnail = $this->platforms->get( (string) $source->platform )->download_file( $source, $file['thumbnail_id'] );
				$mime      = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $thumbnail ) : '';
				$size      = filesize( $thumbnail );
				if ( false !== $size && $size <= (int) $this->settings->get( 'max_media_bytes', 20971520 ) && str_starts_with( $mime, 'image/' ) ) {
					return $thumbnail;
				}
				if ( file_exists( $thumbnail ) ) {
					wp_delete_file( $thumbnail );
				}
			} catch ( \Throwable $exception ) {
				do_action( 'mrncb_video_poster_error', $exception, $source, $file );
			}
		}

		return $this->extract_video_frame( $video_path );
	}

	private function extract_video_frame( string $video_path ): string {
		if ( ! function_exists( 'proc_open' ) ) {
			return '';
		}
		$binary = apply_filters( 'mrncb_ffmpeg_binary', 'ffmpeg' );
		if ( ! is_string( $binary ) || '' === trim( $binary ) ) {
			return '';
		}
		$poster = wp_tempnam( 'mrncb-video-poster.jpg' );
		if ( ! $poster ) {
			return '';
		}

		foreach ( array( '1', '0' ) as $second ) {
			$command = array(
				$binary,
				'-hide_banner',
				'-loglevel',
				'error',
				'-y',
				'-ss',
				$second,
				'-i',
				$video_path,
				'-frames:v',
				'1',
				'-vf',
				'scale=1280:-2:force_original_aspect_ratio=decrease',
				'-q:v',
				'2',
				'-f',
				'image2',
				$poster,
			);
			if ( $this->run_process( $command, 20 ) && file_exists( $poster ) && (int) filesize( $poster ) > 0 ) {
				return $poster;
			}
		}

		if ( file_exists( $poster ) ) {
			wp_delete_file( $poster );
		}
		return '';
	}

	/** @param array<int, string> $command */
	private function run_process( array $command, int $timeout ): bool {
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		try {
			$process = @proc_open( $command, $descriptor_spec, $pipes, null, null, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing FFmpeg is an expected, non-fatal condition.
		} catch ( \Throwable ) {
			return false;
		}
		if ( ! is_resource( $process ) ) {
			return false;
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$deadline = microtime( true ) + $timeout;
		$exitcode = -1;
		do {
			stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$exitcode = (int) $status['exitcode'];
				break;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		if ( $status['running'] ) {
			proc_terminate( $process );
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		return 0 === $exitcode;
	}

	/** @param array<string, mixed> $payload */
	private function attach_video_poster( string $poster_path, string $video_name, int $post_id, array $payload ): int {
		$mime      = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $poster_path ) : 'image/jpeg';
		$extension = match ( $mime ) {
			'image/png'  => 'png',
			'image/webp' => 'webp',
			default      => 'jpg',
		};
		$poster_id = media_handle_sideload(
			array(
				'name'     => pathinfo( $video_name, PATHINFO_FILENAME ) . '-poster.' . $extension,
				'tmp_name' => $poster_path,
			),
			$post_id,
			sanitize_text_field( (string) ( $payload['caption'] ?? '' ) )
		);
		if ( is_wp_error( $poster_id ) ) {
			do_action( 'mrncb_video_poster_error', $poster_id, $post_id, $payload );
			return 0;
		}

		$alt_source = (string) ( $payload['caption'] ?? $payload['text'] ?? '' );
		if ( '' === trim( $alt_source ) && $post_id ) {
			$alt_source = get_the_title( $post_id );
		}
		$alt = wp_trim_words( wp_strip_all_tags( $alt_source ), 18, '…' );
		update_post_meta( (int) $poster_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		return (int) $poster_id;
	}

	/** @param array<string, mixed> $payload
	 *  @return array{id:string,name:string,type:string,thumbnail_id:string}|null
	 */
	private function file_descriptor( array $payload ): ?array {
		if ( ! empty( $payload['photos'] ) ) {
			$photo = end( $payload['photos'] );
			return array(
				'id'           => (string) ( $photo['file_id'] ?? '' ),
				'name'         => 'telegram-photo-' . wp_generate_uuid4() . '.jpg',
				'type'         => 'photo',
				'thumbnail_id' => '',
			);
		}
		foreach ( array(
			'video'    => 'mp4',
			'document' => 'bin',
		) as $key => $extension ) {
			if ( ! empty( $payload[ $key ]['file_id'] ) ) {
				$thumbnail = (array) ( $payload[ $key ]['thumbnail'] ?? $payload[ $key ]['thumb'] ?? array() );
				return array(
					'id'           => (string) $payload[ $key ]['file_id'],
					'name'         => (string) ( $payload[ $key ]['file_name'] ?? "{$key}-" . wp_generate_uuid4() . ".{$extension}" ),
					'type'         => $key,
					'thumbnail_id' => (string) ( $thumbnail['file_id'] ?? '' ),
				);
			}
		}
		return null;
	}
}
