<?php
/**
 * Language of the current request: a translated language when the URL starts with its prefix (Router), else the
 * source language. `with()` runs code as another language (e-mails, cron: URLs built in that language).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Language {

	private static ?string $forced = null;

	public static function current(): string {
		return self::$forced ?? Router::current() ?? Settings::source()->code;
	}

	public static function currentLang(): Lang {
		return Settings::lang( self::current() ) ?? Settings::source();
	}

	/** The request is in a translated language. */
	public static function isTranslated(): bool {
		return self::current() !== Settings::source()->code;
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function with( string $code, callable $callback ): mixed {
		$previous     = self::$forced;
		self::$forced = null !== Settings::lang( $code ) ? $code : Settings::source()->code;
		try {
			return $callback();
		} finally {
			self::$forced = $previous;
		}
	}
}
