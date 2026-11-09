<?php
/**
 * Public API for themes and other plugins.
 */

defined( 'ABSPATH' ) || exit;

/** Code of the current request's language (`hr`, `en` …). */
function marclie_lingua_language(): string {
	return \Marclie\Lingua\Language::current();
}

/**
 * Site languages, source (default) language first.
 *
 * @return list<array{code: string, locale: string, bcp47: string, name: string, label: string, default: bool}>
 */
function marclie_lingua_languages(): array {
	$source = \Marclie\Lingua\Settings::source()->code;

	return array_map(
		static fn( \Marclie\Lingua\Lang $l ): array => array(
			'code'    => $l->code,
			'locale'  => $l->locale,
			'bcp47'   => $l->bcp47,
			'name'    => $l->name,
			'label'   => $l->label,
			'default' => $l->code === $source,
		),
		\Marclie\Lingua\Settings::languages()
	);
}

/** An internal URL in a language (default: the current one); other URLs are returned unchanged. */
function marclie_lingua_url( string $url, ?string $lang = null ): string {
	return \Marclie\Lingua\Router::url( $url, $lang ?? \Marclie\Lingua\Language::current() );
}
