<?php
/**
 * Plugin Name:       Marclie Lingua
 * Description:       English version of Kristal Paris Novalja from stored Croatian → English string pairs: when a visitor picks English, the Croatian page is translated on the server from the saved translations. Every pair is editable in admin › Prijevodi.
 * Version:           0.1.0
 * Author:            Marclie Agency
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Text Domain:       marclie-lingua
 * Domain Path:       /languages
 *
 * The visitor language comes from Marclie Booking (`?lang=en` / cookie `mbk_lang`, header language menu);
 * without it the site stays Croatian. Booking widgets print their own English and are skipped.
 */

defined( 'ABSPATH' ) || exit;

define( 'MLG_VERSION', '0.1.0' );
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

register_activation_hook( MLG_FILE, array( \Marclie\Lingua\Install::class, 'activate' ) );

\Marclie\Lingua\Plugin::boot();
