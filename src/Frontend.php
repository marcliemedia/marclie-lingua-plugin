<?php
/**
 * Translated pages: the whole frontend document is buffered and translated (HtmlTranslator) from the stored pairs
 * of the request's language. Strings met for the first time are stored (admin › Translations lists them as
 * untranslated); during a site scan (Scanner) every string on the page is marked as seen.
 * Blog posts are not stored as translations (Blog): parts marked `data-mlg-skip` are translated in the visitor's
 * browser. A single post page keeps its source `<title>` / metas and `<html lang>` (its main content stays in the
 * source language; browsers offer to translate it).
 *
 * Strings printed outside the HTML (e.g. a script's JSON) go through the filters
 *   apply_filters( 'marclie_lingua_text', 'Text' )                   → translated text (or the source text)
 *   apply_filters( 'marclie_lingua_texts', [ 'key' => 'Text', … ] ) → same keys, translated values
 * which return their input unchanged when the plugin is off or the page is in the source language.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/** Single blog post in browser mode: source `<title>`, metas and page language (decided while the query is current). */
	private static bool $post = false;

	/** Archive or blog index in browser mode: Bricks Posts items linking to a blog post are left to the browser. */
	private static bool $archive = false;

	/** The page is a lasting URL whose completeness is remembered (not a 404 or a search). */
	private static bool $track = false;

	public static function hooks(): void {
		// priority 0: an outer buffer, so it receives the document after inner buffers (theme icons) ran
		add_action( 'template_redirect', array( self::class, 'startBuffer' ), 0 );
		add_filter( 'marclie_lingua_text', array( self::class, 'text' ) );
		add_filter( 'marclie_lingua_texts', array( self::class, 'texts' ) );
	}

	/** A frontend HTML page in a translated language. */
	public static function active(): bool {
		return Language::isTranslated()
			&& ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_is_json_request()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! is_feed()
			&& ! is_customize_preview()
			&& ! ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	public static function startBuffer(): void {
		if ( self::active() ) {
			$browser       = Settings::browserBlog();
			self::$post    = $browser && (bool) apply_filters( 'marclie_lingua/blog_post_page', is_singular( Blog::types() ) );
			self::$archive = $browser && ( is_home() || is_archive() );
			// the state of a page is what an anonymous visitor (and a search engine) gets at its own URL
			self::$track   = ! is_404() && ! is_search() && ! is_preview() && ! is_user_logged_in()
				&& 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
				&& ( empty( $_GET ) || '' === (string) get_option( 'permalink_structure' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
			ob_start( array( self::class, 'buffer' ) );
		}
	}

	public static function buffer( string $html ): string {
		if ( '' === $html || false === stripos( substr( $html, 0, 2000 ), '<html' ) || ! self::isHtmlResponse() ) {
			return $html;
		}
		try {
			$translator = new HtmlTranslator(
				self::resolve( ... ),
				Language::currentLang(),
				Settings::source(),
				! self::$post,
				Settings::browserBlog(),
				self::$archive ? self::blogItem( ... ) : null,
				self::finish( ... )
			);

			return $translator->translate( $html );
		} catch ( \Throwable $e ) {
			error_log( 'Marclie Lingua: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- keep the source page, report why.
			return $html;
		}
	}

	/**
	 * A page that still shows untranslated texts is not offered to search engines in this language: `noindex,
	 * follow`, no hreflang links (Coverage keeps it out of the other versions' links and the sitemap).
	 */
	private static function finish( \Dom\HTMLDocument $doc, int $missing ): void {
		if ( self::$track ) {
			Coverage::record( Language::current(), self::requestUri(), $missing );
		}
		if ( 0 === $missing || self::$post ) {
			return; // complete, or a browser-translated blog post (noindex already)
		}
		foreach ( $doc->querySelectorAll( 'link[rel="alternate"][hreflang]' ) as $link ) {
			$link->remove();
		}
		$robots = $doc->querySelector( 'meta[name="robots"]' );
		if ( null === $robots && null !== $doc->head ) {
			$robots = $doc->createElement( 'meta' );
			$robots->setAttribute( 'name', 'robots' );
			$doc->head->append( $robots );
		}
		if ( null === $robots ) {
			return;
		}
		// keep the other directives (nofollow, max-image-preview …); only the indexing changes
		$directives = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $robots->getAttribute( 'content' ) ) ) ) );
		$directives = array_values( array_diff( $directives, array( 'index', 'all', 'noindex' ) ) );
		array_unshift( $directives, 'noindex' );
		if ( ! array_intersect( $directives, array( 'follow', 'nofollow', 'none' ) ) ) {
			$directives[] = 'follow';
		}
		$robots->setAttribute( 'content', implode( ', ', $directives ) );
	}

	/** An item of Bricks' Posts element whose first link is a blog post. */
	private static function blogItem( \Dom\Element $el ): bool {
		if ( ! $el->classList->contains( 'bricks-layout-item' ) ) {
			return false;
		}
		$link = $el->querySelector( 'a[href]' );
		$id   = null !== $link ? url_to_postid( (string) $link->getAttribute( 'href' ) ) : 0;

		return $id > 0 && in_array( get_post_type( $id ), Blog::types(), true );
	}

	/**
	 * Stored translations of the page's strings in the request's language; unknown strings are stored.
	 *
	 * @param array<string, string> $sources hash => source
	 * @return array<string, string> hash => translation
	 */
	public static function resolve( array $sources ): array {
		$found   = Store::lookup( array_keys( $sources ), Language::current() );
		$missing = array_diff_key( $sources, $found );
		if ( $missing ) {
			Store::register( $missing, self::path() );
		}
		if ( Scanner::isScanRequest() ) {
			Store::touch( array_keys( $sources ) );
		}

		return $found;
	}

	public static function text( $text ) {
		return is_string( $text ) ? self::texts( array( $text ) )[0] : $text;
	}

	/**
	 * @param mixed $texts
	 * @return mixed
	 */
	public static function texts( $texts ) {
		if ( ! is_array( $texts ) || ! Language::isTranslated() || is_admin() ) {
			return $texts;
		}
		$sources = array();
		foreach ( $texts as $key => $text ) {
			$source = is_string( $text ) ? Format::normalize( Format::escapeText( $text ) ) : '';
			if ( '' !== $source && Format::hasWords( $source ) ) {
				$sources[ $key ] = $source;
			}
		}
		if ( ! $sources ) {
			return $texts;
		}
		$byHash = array();
		foreach ( $sources as $source ) {
			$byHash[ Format::hash( $source ) ] = $source;
		}
		$found = self::resolve( $byHash );
		foreach ( $sources as $key => $source ) {
			$translation = $found[ Format::hash( $source ) ] ?? '';
			if ( '' !== $translation ) {
				$texts[ $key ] = Format::plain( $translation );
			}
		}

		return $texts;
	}

	/** Source-language path of this request (the page where a new string is found). */
	private static function path(): string {
		$path = (string) wp_parse_url( self::requestUri(), PHP_URL_PATH );

		return '' === $path ? '/' : sanitize_text_field( rawurldecode( $path ) );
	}

	/** This request's URI in the source language (the language prefix is removed by Router); Coverage key source. */
	public static function requestUri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as a URL by the callers.
	}

	private static function isHtmlResponse(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}

		return true;
	}
}
