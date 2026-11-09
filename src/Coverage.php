<?php
/**
 * Pages whose translated version still shows untranslated texts. Search engines detect a page's language from its
 * visible text, and a page in two languages is a poor result, so such a page is `noindex, follow`, gets no hreflang
 * links and stays out of the language sitemap until every text on it is translated. Frontend records the state each
 * time an anonymous visitor gets the page at its own URL in that language (not previews, logged-in views or
 * filtered URLs); a site scan renders every page in every language and then forgets pages it no longer finds.
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

	/**
	 * Normalized key of a page URL: its path (`/kontakt/` and `/kontakt` are the same page); with plain permalinks
	 * also WordPress' own query variables (`/?page_id=5`), which tell the pages apart there (tracking arguments
	 * never do).
	 */
	public static function key( string $url ): string {
		$path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$key   = '' === $path ? '/' : trailingslashit( $path );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query && '' === (string) get_option( 'permalink_structure' ) ) {
			parse_str( $query, $args );
			$vars = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ? $GLOBALS['wp']->public_query_vars : array( 'p', 'page_id', 'cat', 'tag', 'paged', 'post_type', 'm', 'author' );
			$args = array_intersect_key( $args, array_flip( $vars ) );
			ksort( $args );
			$key .= $args ? '?' . http_build_query( $args ) : '';
		}

		return $key;
	}

	/** Remembers how many texts of a page are untranslated in a language (0 = complete). */
	public static function record( string $lang, string $url, int $missing ): void {
		$key = self::key( $url );
		if ( (int) ( self::map()[ $lang ][ $key ] ?? 0 ) === $missing ) {
			return;
		}
		// re-read: another request may have recorded another page since this one started
		wp_cache_delete( self::OPTION, 'options' );
		self::$map = null;
		$map       = self::map();
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

	/**
	 * After a full scan: forgets pages and languages the scan did not render (deleted pages, changed slugs, removed
	 * languages).
	 *
	 * @param list<string> $jobs Scanned URLs (Scanner::jobs()).
	 */
	public static function retain( array $jobs ): void {
		$keep = array();
		foreach ( $jobs as $url ) {
			$lang = Router::languageOf( $url );
			if ( null !== $lang ) {
				$keep[ $lang ][ self::key( Router::url( $url, Settings::source()->code ) ) ] = true;
			}
		}
		wp_cache_delete( self::OPTION, 'options' );
		self::$map = null;
		$map       = self::map();
		foreach ( $map as $lang => $pages ) {
			$map[ $lang ] = array_intersect_key( $pages, $keep[ $lang ] ?? array() );
			if ( ! $map[ $lang ] ) {
				unset( $map[ $lang ] );
			}
		}
		if ( $map !== self::$map ) {
			self::$map = $map;
			update_option( self::OPTION, $map, false );
		}
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
