<?php
/**
 * Marclie Booking integration (setting `booking`):
 * - `auto` — on when Marclie Booking is active and supports the language contract (it has
 *   `Marclie\Booking\I18n\LanguageAdapter::languages()`);
 * - `on`   — on whenever Marclie Booking is active;
 * - `off`  — never: booking widgets are translated like any other text (their script-rendered parts stay in the
 *   source language).
 *
 * When on: Booking gets the site languages (`marclie_booking/languages`) and its language driver from Lingua
 * (`marclie_booking/language_driver`: language of the request, URLs with the language prefix, e-mails in the
 * guest's language); the booking widgets are not string-translated (Booking prints them in the page's language)
 * and the booking funnel pages stay out of the language sitemaps.
 */

namespace Marclie\Lingua\Integrations;

use Marclie\Lingua\Settings;

defined( 'ABSPATH' ) || exit;

final class Booking {

	private const ADAPTER = 'Marclie\\Booking\\I18n\\LanguageAdapter';

	private const DRIVER = 'Marclie\\Booking\\I18n\\LanguageDriver';

	/** Booking widgets: they print their own copy in the page's language. */
	public const SKIP = array(
		'[data-mbk-widget]', '[class*="brxe-mbk-"]', '[data-mbk-lazy]', '[data-mbk-search-form]', '[data-mbk-bookbox]',
		'[data-mbk-bookbar]', '[data-mbk-catalog]', '[data-mbk-calendar]', '[data-mbk-checkout]', '[data-mbk-confirmation]',
		'[data-mbk-pay-balance]', '[data-mbk-account]',
	);

	/** Booking funnel and account pages (Booking settings page keys; Booking makes them noindex): kept out of the language sitemaps. */
	public const FUNNEL_PAGES = array( 'rezervacija', 'hvala', 'placanje', 'pretraga', 'prijava', 'registracija', 'moj_racun', 'zaboravljena_lozinka' );

	/**
	 * Filters are added when the plugin loads (Booking may resolve its driver on any `plugins_loaded` priority); each
	 * callback checks whether the integration is on.
	 */
	public static function hooks(): void {
		add_filter( 'marclie_lingua/skip_selectors', static fn( $selectors ) => self::enabled() ? array_merge( (array) $selectors, self::SKIP ) : $selectors );
		add_filter( 'marclie_lingua/sitemap_exclude_ids', static fn( $ids ) => self::enabled() ? array_merge( (array) $ids, self::funnelPageIds() ) : $ids );
		add_filter( 'marclie_booking/languages', static fn( $langs ) => self::enabled() && self::supported() ? self::languages() : $langs );
		add_filter( 'marclie_booking/language_driver', static fn( $driver ) => self::enabled() && self::supported() ? new BookingDriver() : $driver );
	}

	public static function present(): bool {
		return class_exists( self::ADAPTER );
	}

	public static function supported(): bool {
		return self::present() && method_exists( self::ADAPTER, 'languages' ) && interface_exists( self::DRIVER );
	}

	public static function enabled(): bool {
		return match ( Settings::booking() ) {
			'off'   => false,
			'on'    => self::present(),
			default => self::supported(),
		};
	}

	/**
	 * @return array<string, array{locale: string, bcp47: string, name: string, label: string, default: bool}>
	 */
	public static function languages(): array {
		$source = Settings::source()->code;
		$out    = array();
		foreach ( Settings::languages() as $lang ) {
			$out[ $lang->code ] = array(
				'locale'  => $lang->locale,
				'bcp47'   => $lang->bcp47,
				'name'    => $lang->name,
				'label'   => $lang->label,
				'default' => $lang->code === $source,
			);
		}

		return $out;
	}

	/** @return list<int> */
	public static function funnelPageIds(): array {
		$settings = 'Marclie\\Booking\\Settings';
		if ( ! method_exists( $settings, 'pageId' ) ) {
			return array();
		}

		return array_values( array_filter( array_map( static fn( string $key ): int => (int) $settings::pageId( $key ), self::FUNNEL_PAGES ) ) );
	}

	/** One line for the settings screen. */
	public static function statusText(): string {
		if ( ! self::present() ) {
			return __( 'Marclie Booking is not active.', 'marclie-lingua' );
		}
		if ( ! self::supported() ) {
			return self::enabled()
				? __( 'Marclie Booking is active but this version does not support the language integration: its widgets are left untranslated and keep their own language.', 'marclie-lingua' )
				: __( 'Marclie Booking is active but this version does not support the language integration.', 'marclie-lingua' );
		}

		return self::enabled()
			? __( 'Marclie Booking detected: the integration is on.', 'marclie-lingua' )
			: __( 'Marclie Booking detected: the integration is off.', 'marclie-lingua' );
	}
}
