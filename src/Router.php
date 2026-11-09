<?php
/**
 * Language URLs: the source language at the site's own URLs (`/kontakt/`), each translated language under its
 * prefix (`/en/kontakt/`, `/de/kontakt/`). No cookie and no browser-language guess: one URL = one language.
 *
 * boot() runs when the plugin file loads (before WordPress parses the request): on a prefixed frontend URL it
 * remembers the language and removes the prefix from `REQUEST_URI`, so WordPress resolves the source page.
 * Redirects (plugins_loaded): `/en` → `/en/`; `/{source}/x/` → `/x/`; legacy `?lang=en` → `/en/…`.
 * Links in translated pages are rewritten on output (HtmlTranslator) and redirects of the request keep the
 * prefix (`wp_redirect`).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Router {

	/** Legacy query argument (Marclie Booking `?lang=`); redirected to the prefixed URL. */
	public const LEGACY_ARG = 'lang';

	private static ?string $current = null;

	/** Request URI before the prefix was removed. */
	private static string $originalUri = '';

	/** Redirect decided in boot(), sent on plugins_loaded. */
	private static string $redirect = '';

	public static function boot(): void {
		if ( ! self::isFrontendRequest() ) {
			return;
		}
		$uri                = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed as a URL, rebuilt below.
		self::$originalUri  = $uri;
		[ $path, $query ]   = array_pad( explode( '?', $uri, 2 ), 2, null );
		$base               = self::basePath();
		if ( ! str_starts_with( $path . '/', $base . '/' ) ) {
			return;
		}
		$rel     = substr( $path, strlen( $base ) );
		$rel     = '' === $rel ? '/' : $rel;
		$segment = self::firstSegment( $rel );
		$suffix  = null !== $query ? '?' . $query : '';

		if ( null !== $segment && Settings::isTarget( $segment ) ) {
			$rest = (string) substr( $rel, strlen( $segment ) + 1 );
			if ( '' === $rest ) {
				if ( self::isRead() ) {
					self::$redirect = $base . '/' . $segment . '/' . $suffix;
					return;
				}
				$rest = '/'; // a form posted to `/en`: serve the language's home page
			}
			$moved = self::isRead() ? Paths::redirect( $segment, $rest ) : null;
			if ( null !== $moved ) {
				self::$redirect = $base . '/' . $segment . $moved . $suffix; // source or former slug → translated slug
				return;
			}
			$source                 = Paths::toSource( $segment, $rest ); // translated slug → the post's own path
			self::$current          = $segment;
			$_SERVER['REQUEST_URI'] = $base . $source . $suffix;
			// servers that pass the path as PATH_INFO (WordPress prefers it when set); it has no base path
			$info = isset( $_SERVER['PATH_INFO'] ) ? (string) $_SERVER['PATH_INFO'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt only.
			if ( '/' . $segment === $info || str_starts_with( $info, '/' . $segment . '/' ) ) {
				$_SERVER['PATH_INFO'] = $source;
			}
			return;
		}
		if ( null !== $segment && $segment === Settings::source()->code && Settings::targets() ) {
			$rest           = (string) substr( $rel, strlen( $segment ) + 1 );
			self::$redirect = $base . ( '' === $rest ? '/' : $rest ) . $suffix;
			return;
		}
		if ( null !== $query && Settings::targets() && ! self::excluded( $rel ) ) {
			parse_str( $query, $args );
			$legacy = isset( $args[ self::LEGACY_ARG ] ) && is_string( $args[ self::LEGACY_ARG ] ) ? strtolower( $args[ self::LEGACY_ARG ] ) : '';
			if ( '' !== $legacy && ( Settings::isTarget( $legacy ) || Settings::source()->code === $legacy ) ) {
				unset( $args[ self::LEGACY_ARG ] );
				$clean          = $path . ( $args ? '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) : '' );
				self::$redirect = self::url( $clean, $legacy );
			}
		}
	}

	public static function hooks(): void {
		add_action( 'plugins_loaded', array( self::class, 'sendRedirect' ), 1 );
		add_filter( 'wp_redirect', array( self::class, 'filterRedirect' ), 20 );
	}

	public static function sendRedirect(): void {
		if ( '' === self::$redirect || ! self::isRead() ) {
			return;
		}
		$target = wp_validate_redirect( self::$redirect, '' );
		if ( '' === $target ) {
			return; // malformed request path (e.g. `//host`): leave it to WordPress
		}
		wp_safe_redirect( $target, 301, 'Marclie Lingua' );
		exit;
	}

	/** Internal redirects of a translated request stay in its language (login forms, canonical fixes …). */
	public static function filterRedirect( $location ) {
		if ( ! is_string( $location ) || null === self::$current ) {
			return $location;
		}

		return self::url( $location, self::$current );
	}

	/** Translated language of this request (null = source language). */
	public static function current(): ?string {
		return self::$current;
	}

	public static function originalUri(): string {
		return self::$originalUri;
	}

	/**
	 * The same internal URL in another language; external, excluded and query/fragment-only URLs are returned as
	 * they are. Idempotent: an existing language prefix is replaced.
	 */
	public static function url( string $url, string $lang ): string {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) || str_starts_with( $url, '?' ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return $url;
		}
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return $url; // mailto:, tel:, javascript: …
		}
		if ( isset( $parts['host'] ) ) {
			$home = wp_parse_url( home_url() );
			if ( strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
				return $url;
			}
		} elseif ( ! str_starts_with( $url, '/' ) ) {
			return $url; // relative to the current page, which already has the prefix
		}
		$path = (string) ( $parts['path'] ?? '/' );
		$base = self::basePath();
		if ( ! str_starts_with( $path . '/', $base . '/' ) ) {
			return $url;
		}
		$rel = substr( $path, strlen( $base ) );
		$rel = '' === $rel ? '/' : $rel;
		if ( self::excluded( $rel, $url ) ) {
			return $url;
		}
		$segment = self::firstSegment( $rel );
		if ( null !== $segment && ( Settings::isTarget( $segment ) || $segment === Settings::source()->code ) ) {
			$rel = (string) substr( $rel, strlen( $segment ) + 1 );
			$rel = '' === $rel ? '/' : $rel;
			if ( Settings::isTarget( $segment ) ) {
				$rel = Paths::toSource( $segment, $rel );
			}
		}
		if ( $lang !== Settings::source()->code && Settings::isTarget( $lang ) ) {
			$rel = '/' . $lang . Paths::toTranslated( $lang, $rel );
		}

		$out = '';
		if ( isset( $parts['host'] ) ) {
			$out = ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		$out .= $base . $rel;
		$out .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$out .= isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $out;
	}

	/** Translated language of an internal URL by its prefix (null: source language or not a site URL). */
	public static function languageOf( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = self::basePath();
		if ( ! str_starts_with( $path . '/', $base . '/' ) ) {
			return null;
		}
		$segment = self::firstSegment( (string) substr( $path, strlen( $base ) ) );

		return null !== $segment && Settings::isTarget( $segment ) ? $segment : null;
	}

	/** This page (path + query, without the legacy `lang` argument) in another language, as an absolute URL. */
	public static function switchUrl( string $lang ): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt as a URL below.
		$url = remove_query_arg( self::LEGACY_ARG, self::origin() . $uri );

		return self::url( $url, $lang );
	}

	/** Paths never prefixed: WordPress internals, files (filter `marclie_lingua/url_excluded`). */
	public static function excluded( string $rel, string $url = '' ): bool {
		$excluded = 1 === preg_match( '#^/(wp-admin|wp-json|wp-content|wp-includes)(/|$)|^/[^/]*\.php$|^/(feed|sitemap[^/]*\.xml|robots\.txt|favicon\.ico)(/|$)#', $rel )
			|| 1 === preg_match( '#\.(?!html?$)[a-z0-9]{2,5}$#i', $rel );

		return (bool) apply_filters( 'marclie_lingua/url_excluded', $excluded, '' !== $url ? $url : $rel );
	}

	/** Path of the site root without trailing slash (`''` or `/subdir`). */
	public static function basePath(): string {
		return rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	}

	private static function origin(): string {
		$home = wp_parse_url( home_url() );

		return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}

	private static function firstSegment( string $rel ): ?string {
		return 1 === preg_match( '#^/([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(?:/|$)#', $rel, $m ) ? $m[1] : null;
	}

	private static function isFrontendRequest(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? 'index.php' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.

		return 'index.php' === $script && ! isset( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
	}

	/** GET or HEAD: only these are redirected (a redirect would turn a POST into a GET). */
	private static function isRead(): bool {
		return in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
	}
}
