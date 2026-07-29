<?php
declare(strict_types=1);

use MRN\ContentBridge\Platform\NormalizedUpdate;
use PHPUnit\Framework\TestCase;

final class NormalizedUpdateTest extends TestCase {
	public function test_canonical_update_preserves_media_group_identity(): void {
		$update = new NormalizedUpdate(
			100,
			'55',
			'album-9',
			'-100123',
			'photo',
			array( 'caption' => 'نمونه' )
		);

		self::assertSame( 100, $update->update_id );
		self::assertSame( 'album-9', $update->media_group_id );
		self::assertSame( 'photo', $update->type );
	}
}
