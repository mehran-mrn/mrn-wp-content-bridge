<?php
/**
 * Plugin Name:       MRN Content Bridge
 * Plugin URI:        https://github.com/mehran-mrn/mrn-wp-content-bridge
 * Description:       پل ماژولار دریافت، پردازش و انتشار محتوا میان وردپرس، تلگرام، بله، لینکدین و سرویس‌های هوش مصنوعی.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            MRN
 * Author URI:        https://github.com/mehran-mrn
 * Text Domain:       mrn-content-bridge
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package MRN\ContentBridge
 */

defined( 'ABSPATH' ) || exit;

define( 'MRNCB_VERSION', '1.0.1' );
define( 'MRNCB_FILE', __FILE__ );
define( 'MRNCB_PATH', plugin_dir_path( __FILE__ ) );
define( 'MRNCB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'MRN\\ContentBridge\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = MRNCB_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( MRN\ContentBridge\Infrastructure\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( MRN\ContentBridge\Infrastructure\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		MRN\ContentBridge\Core\Plugin::instance()->boot();
	}
);
