<?php
/**
 * Predictable single-line article title extraction.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

defined( 'ABSPATH' ) || exit;

final class TitleExtractor {
	public function from_text( string $text ): string {
		$plain = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines = preg_split( '/\R/u', $plain ) ?: array();

		foreach ( $lines as $line ) {
			$candidate = $this->clean_line( $line );
			if ( '' !== $candidate && ! filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
				return $this->limit( $candidate );
			}
		}

		return 'مطلب بدون عنوان';
	}

	public function normalize( string $suggested, string $source_text ): string {
		$lines = preg_split( '/\R/u', wp_strip_all_tags( $suggested ) ) ?: array();
		foreach ( $lines as $line ) {
			$candidate = $this->clean_line( $line );
			if ( '' !== $candidate ) {
				return $this->limit( $candidate );
			}
		}
		return $this->from_text( $source_text );
	}

	private function clean_line( string $line ): string {
		$line = preg_replace( '/^[\s#>*_\-–—]+/u', '', trim( $line ) ) ?? '';
		$line = preg_replace( '/^(?:عنوان|تیتر|title)\s*[:：\-–—]\s*/iu', '', $line ) ?? $line;
		$line = preg_replace( '/\s+/u', ' ', $line ) ?? $line;
		return trim( $line, " \t\n\r\0\x0B\"'“”«»" );
	}

	private function limit( string $title ): string {
		$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		if ( count( $words ) > 14 ) {
			$title = implode( ' ', array_slice( $words, 0, 14 ) ) . '…';
		}
		if ( mb_strlen( $title ) > 140 ) {
			$title = rtrim( mb_substr( $title, 0, 139 ) ) . '…';
		}
		return $title;
	}
}
