<?php
/**
 * One XML sitemap per translated language: `/mlg-sitemap-{code}.xml` (`/?mlg_sitemap={code}` without pretty
 * permalinks), served only while the setting `index` is on.
 *
 * Entries: the site's public URLs (Scanner::urls()) without the 404 page, the blog posts in browser mode (their
 * translated versions are noindex, see Seo), the IDs of filter `marclie_lingua/sitemap_exclude_ids` (list<int>) and
 * posts or terms whose Rank Math robots meta is noindex — each in the sitemap's language (Router::url()), minus the
 * pages still showing untranslated texts in that language (Coverage). The source-language list is the same for every
 * language and cached in a transient for 12 hours, deleted when a post or term is saved or deleted and when the
 * settings change.
 *
 * The sitemaps are listed in Rank Math's sitemap index (`rank_math/sitemap/index`) and in WordPress' own index
 * (`wp-sitemap.xml`, a provider whose entries point to these URLs); each index is only served by its owner.
 * The rewrite rule is added to the stored rules once when it is missing (after activation or an update), so no
 * activation hook is needed.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Sitemap {

	public const QUERY_VAR = 'mlg_sitemap';

	public const RULE = '^mlg-sitemap-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.xml$';

	private const CACHE = 'mlg_sitemap_urls';

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_action( 'wp_loaded', array( self::class, 'maybeFlushRules' ) );
		add_filter( 'query_vars', array( self::class, 'queryVars' ) );
		add_action( 'template_redirect', array( self::class, 'serve' ), -10 );
		add_filter( 'rank_math/sitemap/index', array( self::class, 'rankMathIndex' ) );
		add_action( 'wp_sitemaps_init', array( self::class, 'coreProvider' ) );
		foreach ( array( 'save_post', 'deleted_post', 'saved_term', 'delete_term', 'update_option_' . Settings::OPTION ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
	}

	public static function rewrite(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Adds the rule to the stored rewrite rules when they were built without it (after activation or an update).
	 * Only in admin requests: a flush elsewhere could drop rules other plugins register for admin only.
	 */
	public static function maybeFlushRules(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && $rules && ! isset( $rules[ self::RULE ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public static function queryVars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/** Public URL of the sitemap of a language. */
	public static function url( string $code ): string {
		global $wp_rewrite;

		return $wp_rewrite->using_permalinks()
			? home_url( '/mlg-sitemap-' . $code . '.xml' )
			: add_query_arg( self::QUERY_VAR, $code, home_url( '/' ) );
	}

	/** @return list<string> Codes of the languages that have a sitemap. */
	public static function codes(): array {
		return Settings::index() ? Settings::targetCodes() : array();
	}

	/** @return list<string> Sitemap URLs of the source language (cached). */
	public static function sourceUrls(): array {
		$urls = get_transient( self::CACHE );
		if ( ! is_array( $urls ) ) {
			$urls = self::build();
			set_transient( self::CACHE, $urls, 12 * HOUR_IN_SECONDS );
		}

		return $urls;
	}

	/** @return list<string> Sitemap URLs of a language, without its pages that still show untranslated texts. */
	public static function urls( string $code ): array {
		$urls = array_filter( self::sourceUrls(), static fn( string $url ): bool => ! Coverage::isIncomplete( $code, $url ) );

		return array_values( array_map( static fn( string $url ): string => Router::url( $url, $code ), $urls ) );
	}

	public static function serve(): void {
		$code = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $code ) || '' === $code ) {
			return;
		}
		if ( ! in_array( $code, self::codes(), true ) || null !== Router::current() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		echo self::xml( self::urls( $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped in xml().
		exit;
	}

	/** @param list<string> $urls */
	public static function xml( array $urls ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $url ) {
			$xml .= "\t<url>\n\t\t<loc>" . htmlspecialchars( $url, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</loc>\n\t</url>\n";
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Appends the language sitemaps to Rank Math's sitemap index.
	 *
	 * @param mixed $xml
	 * @return mixed
	 */
	public static function rankMathIndex( $xml ) {
		if ( ! is_string( $xml ) ) {
			return $xml;
		}
		foreach ( self::codes() as $code ) {
			$xml .= "\t<sitemap>\n\t\t<loc>" . htmlspecialchars( self::url( $code ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</loc>\n\t</sitemap>\n";
		}

		return $xml;
	}

	/** Lists the language sitemaps in WordPress' sitemap index. */
	public static function coreProvider(): void {
		if ( ! self::codes() || ! class_exists( \WP_Sitemaps_Provider::class ) ) {
			return;
		}
		wp_register_sitemap_provider(
			'mlg',
			new class() extends \WP_Sitemaps_Provider {

				public function __construct() {
					$this->name        = 'mlg';
					$this->object_type = 'mlg';
				}

				/** @return array<string, object> language code => subtype */
				public function get_object_subtypes() {
					$subtypes = array();
					foreach ( Sitemap::codes() as $code ) {
						$subtypes[ $code ] = (object) array( 'name' => $code );
					}

					return $subtypes;
				}

				/** @return list<array{loc: string}> The plugin's own sitemap URLs instead of `wp-sitemap-mlg-*.xml`. */
				public function get_sitemap_entries() {
					return array_map( static fn( string $code ): array => array( 'loc' => Sitemap::url( $code ) ), Sitemap::codes() );
				}

				/** @return list<array{loc: string}> */
				public function get_url_list( $page_num, $object_subtype = '' ) {
					if ( 1 !== (int) $page_num || ! in_array( $object_subtype, Sitemap::codes(), true ) ) {
						return array();
					}

					return array_map( static fn( string $url ): array => array( 'loc' => $url ), Sitemap::urls( $object_subtype ) );
				}

				public function get_max_num_pages( $object_subtype = '' ) {
					return 1;
				}
			}
		);
	}

	/** @return list<string> */
	private static function build(): array {
		$exclude = array( home_url( '/mlg-page-not-found/' ) );
		$ids     = ! Settings::browserBlog() ? array() : get_posts(
			array(
				'post_type'        => Blog::types(),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$ids     = array_merge( $ids, array_map( 'intval', (array) apply_filters( 'marclie_lingua/sitemap_exclude_ids', array() ) ) );
		$ids     = array_merge(
			$ids,
			get_posts(
				array(
					'post_type'        => 'any',
					'post_status'      => 'publish',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'meta_query'       => array( array( 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'LIKE' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached for 12 hours.
					'suppress_filters' => false,
				)
			)
		);
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				$exclude[] = $link;
			}
		}
		$terms = get_terms(
			array(
				'hide_empty' => false,
				'meta_query' => array( array( 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'LIKE' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached for 12 hours.
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$exclude[] = $link;
			}
		}
		$exclude = array_flip( array_map( array( self::class, 'key' ), $exclude ) );

		return array_values( array_filter( Scanner::urls(), static fn( string $url ): bool => ! isset( $exclude[ self::key( $url ) ] ) ) );
	}

	private static function key( string $url ): string {
		return untrailingslashit( rawurldecode( $url ) );
	}
}
