<?php
/**
 * Telegram Bot API adapter.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

defined( 'ABSPATH' ) || exit;

final class TelegramAdapter extends AbstractBotApiAdapter {
	public function key(): string {
		return 'telegram';
	}

	public function label(): string {
		return 'تلگرام';
	}

	protected function api_base(): string {
		return 'https://api.telegram.org/bot';
	}
}
