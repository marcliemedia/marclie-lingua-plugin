<?php
/**
 * Pages whose translated version still shows untranslated texts. Search engines detect a page's language from its
 * visible text, and a page in two languages is a poor result, so such a page is `noindex, follow`, gets no hreflang
 * links and stays out of the language sitemap until every text on it is translated (Frontend records the state
 * each time the page is rendered in that language; a site scan renders them all).
 *
 * Option `mlg_incomplete` (not autoloaded): [ language code => [ path => number of untranslated texts ] ]; written
 * only when a page's state changes.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Coverage {

	public const OPTION = 'mlg_incomplete';

	/** @var array<string, array<string, int>>|null */
	private static ?array $map = null;

	/** @return array<string, array<string, int>> */
	private static function map(): array {
		if ( null === self::$map ) {
			$stored    = get_option( self::OPTION, array() );
			self::$map = is_array( $stored ) ? $stored : array();
		}

		return self::$map;
	}

	/** Normalized key of a URL path (`/kontakt/` and `/kontakt` are the same page). */
	public static function key( string $path ): string {
		$path = rawurldecode( (string) wp_parse_url( $path, PHP_URL_PATH ) );

		return '' === $path ? '/' : trailingslashit( $path );
	}

	/** Remembers how many texts of a page are untranslated in a language (0 = complete). */
	public static function record( string $lang, string $path, int $missing ): void {
		$map  = self::map();
		$key  = self::key( $path );
		$was  = (int) ( $map[ $lang ][ $key ] ?? 0 );
		if ( $was === $missing ) {
			return;
		}
		if ( $missing > 0 ) {
			$map[ $lang ][ $key ] = $missing;
		} else {
			unset( $map[ $lang ][ $key ] );
			if ( empty( $map[ $lang ] ) ) {
				unset( $map[ $lang ] );
			}
		}
		self::$map = $map;
		update_option( self::OPTION, $map, false );
	}

	public static function isIncomplete( string $lang, string $path ): bool {
		return ! empty( self::map()[ $lang ][ self::key( $path ) ] );
	}

	/** @return array<string, int> path => untranslated texts, in one language */
	public static function pages( string $lang ): array {
		$pages = self::map()[ $lang ] ?? array();
		ksort( $pages );

		return $pages;
	}

	/** Forgets every recorded page (e.g. the source language changed). */
	public static function reset(): void {
		self::$map = array();
		delete_option( self::OPTION );
	}
}
