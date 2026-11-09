<?php
/**
 * Marclie Booking language driver backed by Lingua (loaded only when Booking's LanguageDriver interface exists):
 * the request language comes from the URL prefix, Booking's page URLs and links carry the prefix, and
 * withLanguage() makes URLs built in e-mails / cron use the guest's language.
 */

namespace Marclie\Lingua\Integrations;

use Marclie\Booking\I18n\LanguageDriver;
use Marclie\Lingua\Language;
use Marclie\Lingua\Router;

defined( 'ABSPATH' ) || exit;

final class BookingDriver implements LanguageDriver {

	public function current(): string {
		return Language::current();
	}

	public function url( string $url, string $lang ): string {
		return Router::url( $url, $lang );
	}

	public function pageUrl( string $pageKey, string $lang ): ?string {
		$settings = 'Marclie\\Booking\\Settings';
		$id       = method_exists( $settings, 'pageId' ) ? (int) $settings::pageId( $pageKey ) : 0;
		$link     = $id > 0 ? get_permalink( $id ) : false;

		return is_string( $link ) ? Router::url( $link, $lang ) : null;
	}

	public function withLanguage( string $lang, callable $callback ): mixed {
		return Language::with( $lang, $callback );
	}
}
