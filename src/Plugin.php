<?php
/**
 * Wiring: frontend translation, admin screen, WP-CLI commands.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'plugins_loaded', array( Install::class, 'maybeUpgrade' ) );
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'marclie-lingua', false, dirname( plugin_basename( MLG_FILE ) ) . '/languages' );
			}
		);
		Frontend::hooks();
		Blog::hooks();
		if ( is_admin() ) {
			Admin\Page::hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'marclie-lingua', Cli::class );
		}
	}
}
