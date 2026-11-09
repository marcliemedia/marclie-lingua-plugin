<?php
/**
 * Visitor language. Source = the site's Croatian, target = English. The language is Marclie Booking's
 * (`?lang=en` → cookie `mbk_lang`, header language menu), so the booking widgets and the rest of the page
 * always agree; filter `marclie_lingua/language` for another source of truth.
 */

namespace Marclie\Lingua;

use Marclie\Booking\I18n\LanguageAdapter;

defined( 'ABSPATH' ) || exit;

final class Language {

	public const SOURCE = 'hr';

	public const TARGET = 'en';

	/** `<html lang>` of the English pages (British English, research/en-glossary.md). */
	public const TARGET_LOCALE = 'en-GB';

	public static function current(): string {
		$lang = class_exists( LanguageAdapter::class ) ? LanguageAdapter::current() : self::SOURCE;

		return (string) apply_filters( 'marclie_lingua/language', $lang );
	}

	public static function isTarget(): bool {
		return self::TARGET === self::current();
	}
}
