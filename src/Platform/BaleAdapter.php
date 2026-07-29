<?php
/**
 * Bale Bot API adapter.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

defined( 'ABSPATH' ) || exit;

final class BaleAdapter extends AbstractBotApiAdapter {
	public function key(): string {
		return 'bale';
	}

	public function label(): string {
		return 'بله';
	}

	protected function api_base(): string {
		return 'https://tapi.bale.ai/bot';
	}
}
