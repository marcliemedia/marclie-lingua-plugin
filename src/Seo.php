<?php
/**
 * Search engines: every language version of a page is its own URL.
 *
 * - Canonical: in a translated request the canonical URL (core `get_canonical_url`, Rank Math
 *   `rank_math/frontend/canonical` and `rank_math/opengraph/url`) is the same URL in the request's language.
 * - Robots: when the setting `index` is off every translated page is `noindex, follow` (core `wp_robots`,
 *   Rank Math `rank_math/frontend/robots`).
 * - Single blog posts (Blog::types(), filter `marclie_lingua/blog_post_page`) keep their content in the source
 *   language, so a translated version is a duplicate: always `noindex, follow` with the canonical on the source URL,
 *   and no hreflang links on any version of the post.
 * - Alternates (setting `index` on): `<link rel="alternate" hreflang>` for the source and every target language,
 *   `x-default` = the source URL, and `og:locale:alternate` for the other languages. They are built from the page's
 *   canonical URL (Rank Math's canonical when Rank Math is active, `wp_get_canonical_url()` on singular pages, else
 *   the current URL without its query string), so tracking or filter arguments never reach the alternates. They are
 *   left out when the page is noindex (robots of the page, `blog_public` off), when its canonical is another page,
 *   and on 404, search and preview pages.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** The main query is a single blog post (decided once the query ran). */
	private static ?bool $post = null;

	public static function hooks(): void {
		add_action( 'wp_head', array( self::class, 'alternates' ), 5 );
		// priority 20: after other plugins (Marclie Booking) that adjust the canonical URL
		add_filter( 'get_canonical_url', array( self::class, 'canonical' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'canonical' ), 20 );
		add_filter( 'rank_math/opengraph/url', array( self::class, 'canonical' ), 20 );
		// priority 2: right after Rank Math's og:locale (priority 1)
		add_action( 'rank_math/opengraph/facebook', array( self::class, 'rankMathLocales' ), 2 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'rankMathRobots' ), 20 );
	}

	/**
	 * The canonical URL of a translated page in its language (a translated blog post: the source URL).
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function canonical( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! Frontend::active() ) {
			return $url;
		}

		return Router::url( $url, self::isBlogPost() ? Settings::source()->code : Language::current() );
	}

	/**
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || ! self::forceNoindex() ) {
			return $robots;
		}
		unset( $robots['index'] );
		$robots['noindex'] = true;
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}

		return $robots;
	}

	/**
	 * @param array<string, string> $robots e.g. [ 'index' => 'index', 'follow' => 'follow' ]
	 * @return array<string, string>
	 */
	public static function rankMathRobots( $robots ) {
		if ( ! is_array( $robots ) || ! self::forceNoindex() ) {
			return $robots;
		}
		$robots['index'] = 'noindex';
		if ( empty( $robots['follow'] ) ) {
			$robots['follow'] = 'follow';
		}

		return $robots;
	}

	/** hreflang links (and `og:locale:alternate` when Rank Math does not print Open Graph tags). */
	public static function alternates(): void {
		$url = self::alternatesUrl();
		if ( null === $url ) {
			return;
		}
		$source = Settings::source();
		foreach ( Settings::languages() as $lang ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $lang->bcp47 ), esc_url( Router::url( $url, $lang->code ) ) );
		}
		printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( Router::url( $url, $source->code ) ) );
		if ( ! self::rankMath() ) {
			self::printLocales();
		}
	}

	/** `og:locale:alternate` next to Rank Math's `og:locale`. */
	public static function rankMathLocales(): void {
		if ( null !== self::alternatesUrl() ) {
			self::printLocales();
		}
	}

	private static function printLocales(): void {
		$current = Language::current();
		foreach ( Settings::languages() as $lang ) {
			if ( $lang->code !== $current ) {
				printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $lang->ogLocale() ) );
			}
		}
	}

	/** A translated page that must not be indexed: the setting `index` is off, or it is a single blog post. */
	private static function forceNoindex(): bool {
		return Frontend::active() && ( ! Settings::index() || self::isBlogPost() );
	}

	private static function isBlogPost(): bool {
		if ( null !== self::$post ) {
			return self::$post;
		}
		$post = (bool) apply_filters( 'marclie_lingua/blog_post_page', is_singular( Blog::types() ) );
		if ( did_action( 'wp' ) ) {
			self::$post = $post;
		}

		return $post;
	}

	/** The source-language URL the alternates are built from, or null when the page gets no alternates. */
	private static function alternatesUrl(): ?string {
		if ( ! Settings::index() || ! Settings::targets() || ! self::alternatesPage() || self::isBlogPost() || self::isNoindex() ) {
			return null;
		}
		$source  = Settings::source()->code;
		$current = explode( '?', Router::switchUrl( $source ), 2 )[0];
		$url     = '';
		if ( self::rankMath() ) {
			$url = (string) \RankMath\Paper\Paper::get()->get_canonical();
		} elseif ( is_singular() ) {
			$url = (string) wp_get_canonical_url();
		}
		if ( '' === $url ) {
			return $current;
		}
		$url = Router::url( $url, $source );

		// a canonical on another page (or site): this page is not the version search engines should link
		return self::path( $url ) === self::path( $current ) ? $url : null;
	}

	/** A frontend HTML page that has language versions. */
	private static function alternatesPage(): bool {
		return ! is_admin()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_404()
			&& ! is_search()
			&& ! is_preview()
			&& ! is_customize_preview()
			&& ! ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	private static function isNoindex(): bool {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return true;
		}
		if ( self::rankMath() ) {
			$robots = (array) \RankMath\Paper\Paper::get()->get_robots();

			return 'noindex' === ( $robots['index'] ?? '' );
		}
		$robots = (array) apply_filters( 'wp_robots', array() );

		return ! empty( $robots['noindex'] ) || ! empty( $robots['none'] );
	}

	/** Rank Math printed the head metas of this page (its `wp_head` callback runs at priority 1, before ours). */
	private static function rankMath(): bool {
		return did_action( 'rank_math/head' ) > 0 && class_exists( \RankMath\Paper\Paper::class );
	}

	private static function path( string $url ): string {
		return strtolower( untrailingslashit( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
	}
}
