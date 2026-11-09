<?php
/**
 * Plugin Name:       Marclie Lingua
 * Description:       Translates a WordPress site into other languages from editable string pairs: the page is translated on the server, each language has its own URLs (/en/, /de/ …), with a language switcher (shortcode and Bricks element) and an optional Marclie Booking integration.
 * Version:           0.3.1
 * Author:            Marclie Agency
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Text Domain:       marclie-lingua
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MLG_VERSION', '0.3.1' );
define( 'MLG_FILE', __FILE__ );
define( 'MLG_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLG_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Marclie\\Lingua\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$file = MLG_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

require MLG_DIR . 'src/functions.php';

register_activation_hook( MLG_FILE, array( \Marclie\Lingua\Install::class, 'activate' ) );
// the language sitemap rule goes with the plugin: WordPress rebuilds the rules on the next request
register_deactivation_hook( MLG_FILE, static fn() => delete_option( 'rewrite_rules' ) );

\Marclie\Lingua\Router::boot();
\Marclie\Lingua\Plugin::boot();
