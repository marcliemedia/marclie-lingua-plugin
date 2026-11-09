<?php
/**
 * English pages: the whole frontend document is buffered and translated (HtmlTranslator) from the stored
 * pairs. Strings met for the first time are stored as `new` (admin › Prijevodi lists them); during a site
 * scan (Scanner) every string on the page is marked as seen.
 * Blog posts are not stored as translations (Blog): their parts carry `data-mlg-skip` in the Bricks templates /
 * Card: Post component and are translated in the visitor's browser. A single post page keeps its Croatian
 * `<title>` / metas and `<html lang="hr">` (its main content is Croatian; browsers offer to translate it).
 *
 * Strings printed outside the HTML (e.g. a script's JSON) go through the filters
 *   apply_filters( 'marclie_lingua_text', 'Hrvatski tekst' )        → English text (or the Croatian)
 *   apply_filters( 'marclie_lingua_texts', [ 'key' => 'Tekst', … ] ) → same keys, English values
 * which return their input unchanged when the plugin is off or the page is Croatian.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/** Single blog post: Croatian `<title>`, metas and page language (decided before output, while the query is current). */
	private static bool $post = false;

	/** Archive or blog index: the items of Bricks' own Posts element that link to a blog post are left to the browser. */
	private static bool $archive = false;

	public static function hooks(): void {
		// priority 0: an outer buffer, so it receives the document after inner buffers (theme icons) ran
		add_action( 'template_redirect', array( self::class, 'startBuffer' ), 0 );
		add_filter( 'marclie_lingua_text', array( self::class, 'text' ) );
		add_filter( 'marclie_lingua_texts', array( self::class, 'texts' ) );
	}

	/** A frontend HTML page in English. */
	public static function active(): bool {
		return Language::isTarget()
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
			self::$post    = (bool) apply_filters( 'marclie_lingua/blog_post_page', is_singular( Blog::types() ) );
			self::$archive = is_home() || is_archive();
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
				Language::TARGET_LOCALE,
				! self::$post,
				self::$archive ? self::blogItem( ... ) : null
			);

			return $translator->translate( $html );
		} catch ( \Throwable $e ) {
			error_log( 'Marclie Lingua: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- keep the Croatian page, report why.
			return $html;
		}
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
	 * Stored translations of the page's strings; unknown strings are stored as `new`.
	 *
	 * @param array<string, string> $sources hash => source
	 * @return array<string, string> hash => translation
	 */
	public static function resolve( array $sources ): array {
		$found   = Store::lookup( array_keys( $sources ) );
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
		if ( ! is_array( $texts ) || ! Language::isTarget() || is_admin() ) {
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

	/** Path of this request (first page where a new string is found). */
	private static function path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the path is kept.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return '' === $path ? '/' : sanitize_text_field( rawurldecode( $path ) );
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
