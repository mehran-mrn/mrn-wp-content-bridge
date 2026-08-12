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
	private const LINKED_FILE_MAX_BYTES = 103809024; // 99 MiB.
	private const LINKED_FILE_LIMIT     = 10;

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

			$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $tmp ) : '';
			if ( in_array( strtolower( $mime ), array( '', 'application/octet-stream', 'application/x-empty' ), true ) ) {
				$mime = $file['mime'];
			}
			$mime    = $this->normalize_mime( $mime );
			$allowed = get_allowed_mime_types();
			if ( $mime && ! in_array( $mime, $allowed, true ) ) {
				throw new \RuntimeException( 'نوع فایل دریافتی در وردپرس مجاز نیست.' );
			}

			$upload_tmp  = $tmp;
			$upload_name = sanitize_file_name( $file['name'] );
			if ( '' === $upload_name || 'bin' === strtolower( (string) pathinfo( $upload_name, PATHINFO_EXTENSION ) ) ) {
				$upload_name = 'inbound-file-' . wp_generate_uuid4() . '.' . $this->extension_for_mime( $mime );
			}
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
		if ( $this->is_pdf( $mime, $url ) ) {
			return "<!-- wp:shortcode -->\n[mrncb_pdf id=\"{$attachment_id}\"]\n<!-- /wp:shortcode -->";
		}
		$archive = $this->archive_type( $mime, $url );
		if ( '' !== $archive && $url ) {
			$name = $this->attachment_name( $attachment_id, $url );
			$size = $this->attachment_size( $attachment_id );
			$label = strtoupper( $archive );
			$download_text = 'rar' === $archive
				? esc_html__( 'دانلود فایل RAR', 'mrn-content-bridge' )
				: esc_html__( 'دانلود فایل ZIP', 'mrn-content-bridge' );

			return '<div class="mrncb-download-card mrncb-download-card--archive mrncb-download-card--' . esc_attr( $archive ) . '">'
				. '<span class="mrncb-download-card__icon" aria-hidden="true">' . esc_html( $label ) . '</span>'
				. '<span class="mrncb-download-card__details"><strong>' . esc_html( $name ) . '</strong>'
				. ( '' !== $size ? '<small>' . esc_html( $size ) . '</small>' : '' )
				. '</span><a class="mrncb-download-card__button" href="' . esc_url( $url ) . '" download>'
				. $download_text . '</a></div>';
		}
		if ( str_starts_with( $mime, 'video/' ) && $url ) {
			$attributes = ' src="' . esc_url( $url ) . '" preload="metadata"';
			$poster_id  = $this->poster_id( $attachment_id );
			$poster_url = $poster_id ? wp_get_attachment_image_url( $poster_id, 'full' ) : false;
			if ( $poster_url ) {
				$attributes .= ' poster="' . esc_url( $poster_url ) . '"';
			}

			return "<!-- wp:shortcode -->\n[video{$attributes}][/video]\n<!-- /wp:shortcode -->";
		}
		if ( str_starts_with( $mime, 'audio/' ) && $url ) {
			return "<!-- wp:shortcode -->\n[audio src=\"" . esc_url( $url ) . "\" preload=\"metadata\"][/audio]\n<!-- /wp:shortcode -->";
		}

		return (string) wp_get_attachment_link( $attachment_id );
	}

	/**
	 * Download supported file URLs found in the original inbound message.
	 *
	 * @param array<string, mixed> $payload Original normalized message payload.
	 * @param int                  $post_id Parent post ID.
	 * @return array<int, int> Attachment IDs, including previously imported URLs.
	 */
	public function import_linked_files( array $payload, int $post_id ): array {
		$attachment_ids = array();
		$caption        = sanitize_text_field( (string) ( $payload['caption'] ?? '' ) );

		foreach ( $this->linked_urls( $payload ) as $url ) {
			$meta_key      = '_mrncb_link_attachment_' . hash( 'sha256', $url );
			$attachment_id = absint( get_post_meta( $post_id, $meta_key, true ) );
			if ( ! $attachment_id ) {
				$attachment_id = $this->import_linked_file( $url, $post_id, $caption );
				if ( $attachment_id ) {
					update_post_meta( $post_id, $meta_key, $attachment_id );
				}
			}
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		return array_values( array_unique( $attachment_ids ) );
	}

	/**
	 * Remove temporary bare file URLs and localize meaningful anchors after import.
	 *
	 * Only URLs with a successful per-post attachment record are changed. Citation
	 * and ordinary page links are therefore preserved.
	 *
	 * @param string               $content Generated article HTML.
	 * @param array<string, mixed> $payload Original normalized message payload.
	 * @param int                  $post_id Parent post ID.
	 */
	public function localize_imported_links( string $content, array $payload, int $post_id ): string {
		foreach ( $this->linked_urls( $payload ) as $url ) {
			$meta_key      = '_mrncb_link_attachment_' . hash( 'sha256', $url );
			$attachment_id = absint( get_post_meta( $post_id, $meta_key, true ) );
			$local_url     = $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;
			if ( ! $attachment_id || ! $local_url ) {
				continue;
			}

			$candidates = array_values(
				array_unique(
					array(
						$url,
						htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						esc_url( $url ),
					)
				)
			);
			foreach ( $candidates as $candidate ) {
				$pattern = '~<a\b(?P<before>[^>]*?)href\s*=\s*(?P<quote>["\'])' . preg_quote( $candidate, '~' ) . '(?P=quote)(?P<after>[^>]*)>(?P<body>.*?)</a>~isu';
				$content = (string) preg_replace_callback(
					$pattern,
					static function ( array $match ) use ( $url, $local_url ): string {
						$visible = trim( html_entity_decode( wp_strip_all_tags( (string) $match['body'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
						$visible = rtrim( $visible, ".,;:!?)]}>،؛؟»'\"" );
						if ( '' === $visible || $visible === $url || rawurldecode( $visible ) === rawurldecode( $url ) ) {
							return '';
						}

						return '<a' . $match['before'] . 'href="' . esc_url( $local_url ) . '"' . $match['after'] . '>' . $match['body'] . '</a>';
					},
					$content
				);
			}

			$content = str_replace( $candidates, '', $content );
		}

		$content = (string) preg_replace( '~<(p|li)>\s*(?:<br\s*/?>)?\s*</\1>~iu', '', $content );
		return trim( $content );
	}

	/**
	 * Extract explicit HTTP(S) links from text and Telegram/Bale text-link entities.
	 *
	 * @param array<string, mixed> $payload Original normalized message payload.
	 * @return array<int, string>
	 */
	public function linked_urls( array $payload ): array {
		$urls = array();
		$text = html_entity_decode( (string) ( $payload['text'] ?? $payload['caption'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match_all( '~https?://[^\s<>"\']+~iu', $text, $matches ) ) {
			$urls = (array) ( $matches[0] ?? array() );
		}
		foreach ( (array) ( $payload['entities'] ?? array() ) as $entity ) {
			$url = is_array( $entity ) ? (string) ( $entity['url'] ?? '' ) : '';
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$normalized = array();
		foreach ( $urls as $url ) {
			$url = rtrim( trim( $url ), ".,;:!?)]}>،؛؟»'\"" );
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$normalized[ $url ] = $url;
			if ( count( $normalized ) >= self::LINKED_FILE_LIMIT ) {
				break;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Allow only the file extensions that this importer deliberately handles.
	 *
	 * @param array<string, string> $mimes Existing WordPress upload MIME map.
	 * @return array<string, string>
	 */
	public function allowed_upload_mimes( array $mimes ): array {
		return array_merge(
			$mimes,
			array(
				'mp4|m4v' => 'video/mp4',
				'mov'     => 'video/quicktime',
				'webm'    => 'video/webm',
				'ogv'     => 'video/ogg',
				'mp3'     => 'audio/mpeg',
				'm4a'     => 'audio/mp4',
				'ogg|oga' => 'audio/ogg',
				'wav'     => 'audio/wav',
				'flac'    => 'audio/flac',
				'aac'     => 'audio/aac',
				'pdf'     => 'application/pdf',
				'zip'     => 'application/zip',
				'rar'     => 'application/vnd.rar',
			)
		);
	}

	/**
	 * Render an imported PDF with the browser's inline viewer.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 */
	public function render_pdf_shortcode( array $attributes = array() ): string {
		$attributes    = shortcode_atts( array( 'id' => 0 ), $attributes, 'mrncb_pdf' );
		$attachment_id = absint( $attributes['id'] );
		$mime          = (string) get_post_mime_type( $attachment_id );
		$url           = wp_get_attachment_url( $attachment_id );
		if ( ! $attachment_id || ! $url || ! $this->is_pdf( $mime, $url ) ) {
			return '';
		}

		$name = $this->attachment_name( $attachment_id, $url );
		return '<figure class="mrncb-pdf-viewer">'
			. '<object class="mrncb-pdf-viewer__frame" data="' . esc_url( $url ) . '" type="application/pdf" aria-label="' . esc_attr( $name ) . '">'
			. '<p>' . esc_html__( 'نمایش PDF در این مرورگر پشتیبانی نمی‌شود.', 'mrn-content-bridge' ) . ' '
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'باز کردن فایل PDF', 'mrn-content-bridge' ) . '</a></p>'
			. '</object><figcaption><span>' . esc_html( $name ) . '</span>'
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'نمایش در صفحهٔ جدید', 'mrn-content-bridge' ) . '</a>'
			. '</figcaption></figure>';
	}

	/**
	 * Validate, stream, inspect and sideload one supported remote file.
	 */
	private function import_linked_file( string $url, int $post_id, string $caption ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp        = '';
		$poster_tmp = '';
		try {
			$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			$head      = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 5,
				)
			);
			$head_mime        = '';
			$head_disposition = '';
			$head_status = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
			if ( $head_status >= 200 && $head_status < 400 ) {
				$length = (int) wp_remote_retrieve_header( $head, 'content-length' );
				if ( $length >= self::LINKED_FILE_MAX_BYTES ) {
					return 0;
				}
				$head_mime        = $this->normalize_mime( (string) wp_remote_retrieve_header( $head, 'content-type' ) );
				$head_disposition = (string) wp_remote_retrieve_header( $head, 'content-disposition' );
			}

			$filename           = $this->remote_filename( $url, $head_disposition );
			$filename_extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( $this->is_supported_extension( $filename_extension ) ) {
				$extension = $filename_extension;
			}
			$kind = $this->linked_file_kind( $head_mime, $extension );
			if ( '' === $kind && ! $this->is_generic_mime( $head_mime ) ) {
				return 0;
			}
			if ( '' === $kind && ! $this->is_supported_extension( $extension ) ) {
				return 0;
			}

			$tmp = wp_tempnam( '' !== $filename ? $filename : 'mrncb-linked-file' );
			if ( ! $tmp ) {
				return 0;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 120,
					'redirection'         => 5,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::LINKED_FILE_MAX_BYTES,
				)
			);
			if ( is_wp_error( $response ) ) {
				return 0;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$size   = file_exists( $tmp ) ? filesize( $tmp ) : false;
			if ( $status < 200 || $status >= 300 || false === $size || 0 === $size || $size >= self::LINKED_FILE_MAX_BYTES ) {
				return 0;
			}

			$response_mime = $this->normalize_mime( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			$file_mime     = function_exists( 'mime_content_type' ) ? $this->normalize_mime( (string) mime_content_type( $tmp ) ) : '';
			$kind          = $this->linked_file_kind( $file_mime, $extension );
			if ( '' === $kind && $this->is_generic_mime( $file_mime ) ) {
				$kind = $this->linked_file_kind( $response_mime, $extension );
			}
			if ( '' === $kind ) {
				return 0;
			}

			$filename = $this->filename_for_kind( $filename, $kind, $response_mime, $extension );
			if ( 'video' === $kind ) {
				$poster_tmp = $this->extract_video_frame( $tmp );
			}
			$attachment_id = media_handle_sideload(
				array(
					'name'     => $filename,
					'tmp_name' => $tmp,
				),
				$post_id,
				$caption
			);
			if ( is_wp_error( $attachment_id ) ) {
				do_action( 'mrncb_linked_media_error', $attachment_id, $url, $post_id );
				return 0;
			}
			$tmp = '';
			update_post_meta( (int) $attachment_id, '_mrncb_remote_source_url', esc_url_raw( $url ) );

			if ( 'video' === $kind && '' !== $poster_tmp ) {
				$poster_id = $this->attach_video_poster( $poster_tmp, $filename, $post_id, array( 'caption' => $caption ) );
				if ( ! file_exists( $poster_tmp ) ) {
					$poster_tmp = '';
				}
				if ( $poster_id ) {
					update_post_meta( (int) $attachment_id, '_mrncb_video_poster_id', $poster_id );
					update_post_meta( (int) $attachment_id, '_thumbnail_id', $poster_id );
				}
			}

			return (int) $attachment_id;
		} catch ( \Throwable $error ) {
			do_action( 'mrncb_linked_media_error', $error, $url, $post_id );
			return 0;
		} finally {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( '' !== $poster_tmp && file_exists( $poster_tmp ) ) {
				wp_delete_file( $poster_tmp );
			}
		}
	}

	private function linked_file_kind( string $mime, string $extension ): string {
		$mime      = $this->normalize_mime( $mime );
		$extension = strtolower( $extension );
		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}
		if ( in_array( $mime, array( 'application/zip', 'application/vnd.rar', 'application/x-rar-compressed' ), true ) ) {
			return 'archive';
		}
		if ( str_starts_with( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( str_starts_with( $mime, 'audio/' ) ) {
			return 'audio';
		}
		if ( 'application/ogg' === $mime ) {
			return 'ogv' === $extension ? 'video' : 'audio';
		}
		if ( '' !== $mime && ! $this->is_generic_mime( $mime ) ) {
			return '';
		}

		return match ( $extension ) {
			'mp4', 'm4v', 'mov', 'webm', 'ogv' => 'video',
			'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac', 'aac' => 'audio',
			'pdf' => 'pdf',
			'zip', 'rar' => 'archive',
			default => '',
		};
	}

	private function is_supported_extension( string $extension ): bool {
		return '' !== $this->linked_file_kind( '', $extension );
	}

	private function is_generic_mime( string $mime ): bool {
		return in_array( $this->normalize_mime( $mime ), array( '', 'application/octet-stream', 'application/x-empty' ), true );
	}

	private function remote_filename( string $url, string $content_disposition ): string {
		$filename = '';
		if ( preg_match( "/filename\*=UTF-8''([^;]+)/i", $content_disposition, $match ) ) {
			$filename = rawurldecode( trim( $match[1], " \t\n\r\0\x0B\"'" ) );
		} elseif ( preg_match( '/filename\s*=\s*["\']?([^"\';]+)["\']?/i', $content_disposition, $match ) ) {
			$filename = trim( $match[1] );
		}
		if ( '' === $filename ) {
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$filename = rawurldecode( wp_basename( $path ) );
		}

		return sanitize_file_name( $filename );
	}

	private function filename_for_kind( string $filename, string $kind, string $mime, string $url_extension ): string {
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( $this->linked_file_kind( '', $extension ) === $kind ) {
			return $filename;
		}

		$extension = match ( $kind ) {
			'video' => match ( $this->normalize_mime( $mime ) ) {
				'video/webm' => 'webm',
				'video/quicktime' => 'mov',
				'video/ogg' => 'ogv',
				default => in_array( $url_extension, array( 'mp4', 'm4v', 'mov', 'webm', 'ogv' ), true ) ? $url_extension : 'mp4',
			},
			'audio' => match ( $this->normalize_mime( $mime ) ) {
				'audio/ogg', 'application/ogg' => 'ogg',
				'audio/wav', 'audio/x-wav' => 'wav',
				'audio/flac' => 'flac',
				'audio/aac' => 'aac',
				'audio/mp4' => 'm4a',
				default => in_array( $url_extension, array( 'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac', 'aac' ), true ) ? $url_extension : 'mp3',
			},
			'pdf' => 'pdf',
			'archive' => 'rar' === $url_extension || str_contains( $mime, 'rar' ) ? 'rar' : 'zip',
			default => '',
		};
		$basename = pathinfo( $filename, PATHINFO_FILENAME );
		$basename = '' !== $basename ? $basename : 'linked-file-' . wp_generate_uuid4();

		return sanitize_file_name( $basename . '.' . $extension );
	}

	/**
	 * Prefer the thumbnail supplied by the messaging platform, then fall back to
	 * extracting an actual frame with FFmpeg when it is available on the host.
	 *
	 * @param array{id:string,name:string,type:string,mime:string,thumbnail_id:string} $file
	 */
	private function video_poster_file( object $source, array $file, string $video_path ): string {
		if ( '' !== $file['thumbnail_id'] ) {
			try {
				$thumbnail = $this->platforms->get( (string) $source->platform )->download_file( $source, $file['thumbnail_id'] );
				$mime      = $this->image_mime( $thumbnail );
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
		$default_binary = defined( 'MRNCB_FFMPEG_BINARY' ) ? (string) MRNCB_FFMPEG_BINARY : 'ffmpeg';
		$binary         = apply_filters( 'mrncb_ffmpeg_binary', $default_binary );
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
			$this->run_process( $command, 30 );
			if ( file_exists( $poster ) && (int) filesize( $poster ) > 0 && str_starts_with( $this->image_mime( $poster ), 'image/' ) ) {
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
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$close_code = proc_close( $process );
		if ( $exitcode < 0 && is_int( $close_code ) ) {
			$exitcode = $close_code;
		}
		return 0 === $exitcode;
	}

	/** @param array<string, mixed> $payload */
	private function attach_video_poster( string $poster_path, string $video_name, int $post_id, array $payload ): int {
		$mime      = $this->image_mime( $poster_path );
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

	private function image_mime( string $path ): string {
		$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $path ) : '';
		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $path );
		}
		if ( '' === $mime && function_exists( 'getimagesize' ) ) {
			$image = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid remote thumbnail data is expected and ignored.
			$mime  = is_array( $image ) ? (string) ( $image['mime'] ?? '' ) : '';
		}

		return strtolower( $mime );
	}

	/** @param array<string, mixed> $payload
	 *  @return array{id:string,name:string,type:string,mime:string,thumbnail_id:string}|null
	 */
	private function file_descriptor( array $payload ): ?array {
		if ( ! empty( $payload['photos'] ) ) {
			$photo = end( $payload['photos'] );
			return array(
				'id'           => (string) ( $photo['file_id'] ?? '' ),
				'name'         => (string) ( $photo['file_name'] ?? 'inbound-photo-' . wp_generate_uuid4() . '.jpg' ),
				'type'         => 'photo',
				'mime'         => (string) ( $photo['mime_type'] ?? 'image/jpeg' ),
				'thumbnail_id' => '',
			);
		}
		foreach ( array(
			'video'    => 'mp4',
			'document' => 'bin',
		) as $key => $extension ) {
			if ( ! empty( $payload[ $key ]['file_id'] ) ) {
				$thumbnail         = (array) ( $payload[ $key ]['thumbnail'] ?? $payload[ $key ]['thumb'] ?? array() );
				$mime              = $this->normalize_mime( (string) ( $payload[ $key ]['mime_type'] ?? '' ) );
				$fallback_extension = 'document' === $key ? $this->extension_for_mime( $mime ) : $extension;
				return array(
					'id'           => (string) $payload[ $key ]['file_id'],
					'name'         => (string) ( $payload[ $key ]['file_name'] ?? "{$key}-" . wp_generate_uuid4() . ".{$fallback_extension}" ),
					'type'         => $key,
					'mime'         => $mime,
					'thumbnail_id' => (string) ( $thumbnail['file_id'] ?? '' ),
				);
			}
		}
		return null;
	}

	private function normalize_mime( string $mime ): string {
		$without_parameters = strtok( $mime, ';' );
		$mime               = strtolower( trim( false === $without_parameters ? $mime : $without_parameters ) );
		return match ( $mime ) {
			'application/x-zip', 'application/x-zip-compressed', 'multipart/x-zip' => 'application/zip',
			'application/x-pdf' => 'application/pdf',
			'application/rar', 'application/x-rar', 'application/x-rar-compressed' => 'application/vnd.rar',
			default => $mime,
		};
	}

	private function extension_for_mime( string $mime ): string {
		return match ( $this->normalize_mime( $mime ) ) {
			'application/pdf' => 'pdf',
			'application/zip' => 'zip',
			'application/vnd.rar', 'application/x-rar-compressed' => 'rar',
			default => 'bin',
		};
	}

	private function is_pdf( string $mime, string|false $url ): bool {
		return 'application/pdf' === $this->normalize_mime( $mime )
			|| ( is_string( $url ) && 'pdf' === strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) );
	}

	private function archive_type( string $mime, string|false $url ): string {
		$mime      = $this->normalize_mime( $mime );
		$extension = is_string( $url ) ? strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) : '';
		if ( in_array( $mime, array( 'application/vnd.rar', 'application/x-rar-compressed' ), true ) || 'rar' === $extension ) {
			return 'rar';
		}
		if ( 'application/zip' === $mime || 'zip' === $extension ) {
			return 'zip';
		}

		return '';
	}

	private function attachment_name( int $attachment_id, string $url ): string {
		$name = trim( (string) get_the_title( $attachment_id ) );
		if ( '' !== $name ) {
			return $name;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return sanitize_file_name( wp_basename( $path ) );
	}

	private function attachment_size( int $attachment_id ): string {
		$file = get_attached_file( $attachment_id );
		$size = is_string( $file ) && file_exists( $file ) ? filesize( $file ) : false;
		return false === $size ? '' : size_format( $size, 1 );
	}
}
