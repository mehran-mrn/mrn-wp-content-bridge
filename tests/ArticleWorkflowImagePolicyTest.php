<?php
declare(strict_types=1);

use MRN\ContentBridge\Workflow\ArticleWorkflow;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( string $term, string $taxonomy = '' ): array|false {
		return 'Generated category' === $term && 'category' === $taxonomy ? array( 'term_id' => 8 ) : false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return false;
	}
}

final class ArticleWorkflowImagePolicyTest extends TestCase {
	public function testDetectsTelegramPhotoAsSourceImage(): void {
		self::assertTrue(
			$this->detect(
				array(
					(object) array( 'message_type' => 'photo', 'payload' => '{}' ),
				)
			)
		);
	}

	public function testDetectsImageDocumentAsSourceImage(): void {
		self::assertTrue(
			$this->detect(
				array(
					(object) array(
						'message_type' => 'document',
						'payload'      => json_encode( array( 'document' => array( 'mime_type' => 'image/png' ) ) ),
					),
				)
			)
		);
	}

	public function testTextAndNonImageDocumentDoNotCountAsSourceImage(): void {
		self::assertFalse(
			$this->detect(
				array(
					(object) array( 'message_type' => 'text', 'payload' => '{"text":"hello"}' ),
					(object) array(
						'message_type' => 'document',
						'payload'      => json_encode( array( 'document' => array( 'mime_type' => 'application/pdf' ) ) ),
					),
				)
			)
		);
	}

	public function testRssDefaultCategoryIsAddedAlongsideGeneratedCategories(): void {
		$reflection = new ReflectionClass( ArticleWorkflow::class );
		$workflow   = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'category_ids' );

		self::assertSame( array( 8, 17 ), $method->invoke( $workflow, array( 'Generated category' ), 17, true ) );
	}

	/** @param array<int, object> $messages */
	private function detect( array $messages ): bool {
		$reflection = new ReflectionClass( ArticleWorkflow::class );
		$workflow   = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'messages_have_source_image' );
		return (bool) $method->invoke( $workflow, $messages );
	}
}
