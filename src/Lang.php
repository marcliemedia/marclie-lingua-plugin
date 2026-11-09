<?php
/**
 * One language of the site: code (URL prefix, e.g. `de`), WordPress locale (`de_DE`), BCP 47 tag for `lang` /
 * `hreflang` (`de-DE`), name in the language itself (`Deutsch`) and short label (`DE`).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final readonly class Lang {

	public function __construct(
		public string $code,
		public string $locale,
		public string $bcp47,
		public string $name,
		public string $label
	) {}

	/** Open Graph locale (`de_DE`). */
	public function ogLocale(): string {
		return str_replace( '-', '_', $this->bcp47 );
	}
}
