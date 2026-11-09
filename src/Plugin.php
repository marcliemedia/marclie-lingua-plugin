<?php
/**
 * Wiring: settings, upgrades, language URLs, frontend translation, admin screens, WP-CLI commands.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Settings::hooks();
		Install::hooks();
		PostTranslations::hooks();
		PostRenderer::hooks();
		Paths::hooks();
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'marclie-lingua', false, dirname( plugin_basename( MLG_FILE ) ) . '/languages' );
			}
		);
		Router::hooks();
		Frontend::hooks();
		Blog::hooks();
		Seo::hooks();
		Sitemap::hooks();
		Switcher::hooks();
		Integrations\Booking::hooks();
		if ( is_admin() ) {
			Admin\Page::hooks();
			Admin\SettingsPage::hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'marclie-lingua', Cli::class );
		}
	}
}
