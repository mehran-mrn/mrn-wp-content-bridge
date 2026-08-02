<?php
declare(strict_types=1);

use MRN\ContentBridge\Workflow\TitleExtractor;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

final class TitleExtractorTest extends TestCase {
	public function testUsesOnlyTheFirstMeaningfulLine(): void {
		$extractor = new TitleExtractor();

		self::assertSame(
			'عنوان کوتاه و دقیق',
			$extractor->from_text( "\n\nعنوان کوتاه و دقیق\nاین خط آغاز بدنه مطلب است و نباید وارد عنوان شود." )
		);
	}

	public function testRemovesCommonHeadingPrefixesAndMarkdown(): void {
		$extractor = new TitleExtractor();

		self::assertSame( 'آینده فناوری ایران', $extractor->from_text( '## عنوان: آینده فناوری ایران' ) );
	}

	public function testNormalizesMultilineAiTitles(): void {
		$extractor = new TitleExtractor();

		self::assertSame(
			'عنوان پیشنهادی',
			$extractor->normalize( "عنوان پیشنهادی\nتوضیح اضافه‌ای که مدل برگردانده است", 'متن اصلی' )
		);
	}

	public function testLimitsOverlongTitles(): void {
		$extractor = new TitleExtractor();
		$title     = $extractor->from_text( 'یک دو سه چهار پنج شش هفت هشت نه ده یازده دوازده سیزده چهارده پانزده شانزده' );

		self::assertStringEndsWith( '…', $title );
		self::assertSame( 14, count( preg_split( '/\s+/u', rtrim( $title, '…' ) ) ?: array() ) );
	}
}
