<?php
/**
 * A queue failure that cannot succeed by retrying the same payload.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Queue;

defined( 'ABSPATH' ) || exit;

final class PermanentJobFailure extends \RuntimeException {}
