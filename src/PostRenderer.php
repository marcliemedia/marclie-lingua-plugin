<?php
/**
 * Whole-post translations on translated pages (blog mode `document`):
 * - content (`the_content`), title (`the_title`, `single_post_title`), excerpt (`get_the_excerpt`) and the SEO title /
 *   description (core `document_title_parts`, Rank Math `rank_math/frontend/title|description`) of a post with a
 *   published translation in the page's language come from PostTranslations;
 * - the content is wrapped in `<!--mlg:doc-->…<!--/mlg:doc-->`, so HtmlTranslator leaves it alone (a post without a
 *   translation keeps its source content there, and its page is noindex — Frontend);
 * - every title / excerpt printed for a document post is "owned": HtmlTranslator never stores it as a string;
 * - lists of posts (any WP_Query that is not for one post) show only the posts translated into the page's language
 *   (filter `marclie_lingua/filter_query` ( bool, WP_Query ) to keep a query unfiltered);
 * - searches find posts by their translated title, content and excerpt.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class PostRenderer {

	/** @var array<string, true> plain texts printed for document posts on this page */
	private static array $owned = array();

	private static bool $rendering = false;

	public static function hooks(): void {
		add_filter( 'the_content', array( self::class, 'content' ), 999 );
		add_filter( 'the_title', array( self::class, 'title' ), 999, 2 );
		add_filter( 'single_post_title', array( self::class, 'singleTitle' ), 999, 2 );
		add_filter( 'get_the_excerpt', array( self::class, 'excerpt' ), 999, 2 );
		add_filter( 'document_title_parts', array( self::class, 'documentTitle' ), 999 );
		add_filter( 'rank_math/frontend/title', array( self::class, 'rankMathTitle' ), 999 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'rankMathDescription' ), 999 );
		add_filter( 'posts_where', array( self::class, 'onlyTranslated' ), 10, 2 );
		add_filter( 'posts_search', array( self::class, 'search' ), 10, 2 );
	}

	/** A translated frontend request in document mode. */
	private static function on(): bool {
		return Settings::documentBlog() && Language::isTranslated() && ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** @return array<string, mixed>|null The published translation of a document post in the page's language. */
	public static function translation( int $postId ): ?array {
		$type = get_post_type( $postId );
		if ( ! self::on() || false === $type || ! PostTranslations::isDocumentType( $type ) ) {
			return null;
		}

		return PostTranslations::published( $postId, Language::current() );
	}

	/** Plain texts HtmlTranslator must leave alone. @return list<string> */
	public static function owned(): array {
		return array_keys( self::$owned );
	}

	private static function own( string $text ): string {
		$plain = Format::normalize( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $plain ) {
			self::$owned[ $plain ] = true;
		}

		return $text;
	}

	public static function content( $content ) {
		$post = get_post();
		if ( self::$rendering || ! is_string( $content ) || ! $post instanceof \WP_Post || ! self::on() || ! PostTranslations::isDocumentType( $post->post_type ) ) {
			return $content;
		}
		$row = PostTranslations::published( $post->ID, Language::current() );
		if ( null !== $row ) {
			self::$rendering = true; // the translated HTML goes through the same content filters as the post's own
			try {
				$content = (string) apply_filters( 'the_content', (string) $row['content'] );
			} finally {
				self::$rendering = false;
			}
		}

		return '<!--' . HtmlTranslator::DOC_START . '-->' . $content . '<!--' . HtmlTranslator::DOC_END . '-->';
	}

	/** @param mixed $title */
	public static function title( $title, $postId = 0 ) {
		if ( ! is_string( $title ) || ! $postId ) {
			return $title;
		}
		$type = get_post_type( (int) $postId );
		if ( ! self::on() || false === $type || ! PostTranslations::isDocumentType( $type ) ) {
			return $title;
		}
		$row = PostTranslations::published( (int) $postId, Language::current() );

		return self::own( null !== $row && '' !== $row['title'] ? (string) $row['title'] : $title ); // sanitized on save, printed like core titles
	}

	/** @param mixed $title */
	public static function singleTitle( $title, $post = null ) {
		return $post instanceof \WP_Post ? self::title( $title, $post->ID ) : $title;
	}

	/** @param mixed $excerpt */
	public static function excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! is_string( $excerpt ) || ! $post instanceof \WP_Post || ! self::on() || ! PostTranslations::isDocumentType( $post->post_type ) ) {
			return $excerpt;
		}
		$row = PostTranslations::published( $post->ID, Language::current() );
		if ( null === $row ) {
			return self::own( $excerpt );
		}
		if ( '' !== $row['excerpt'] ) {
			return self::own( (string) $row['excerpt'] );
		}
		$more = (string) apply_filters( 'excerpt_more', ' [&hellip;]' );

		return self::own( wp_trim_words( excerpt_remove_blocks( strip_shortcodes( (string) $row['content'] ) ), (int) apply_filters( 'excerpt_length', 55 ), $more ) );
	}

	/** The translated SEO title (or title) of the post shown on this page. @return array<string, mixed>|null */
	private static function current(): ?array {
		return is_singular() ? self::translation( (int) get_queried_object_id() ) : null;
	}

	/**
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public static function documentTitle( $parts ) {
		$row = self::current();
		if ( is_array( $parts ) && null !== $row ) {
			$parts['title'] = '' !== $row['seo_title'] ? (string) $row['seo_title'] : (string) $row['title'];
		}

		return $parts;
	}

	/** @param mixed $title */
	public static function rankMathTitle( $title ) {
		$row = self::current();
		if ( null === $row || ! is_string( $title ) ) {
			return $title;
		}
		if ( '' !== $row['seo_title'] ) {
			return (string) $row['seo_title'];
		}
		$source = get_post( (int) get_queried_object_id() );

		return $source instanceof \WP_Post && '' !== $source->post_title ? str_replace( $source->post_title, (string) $row['title'], $title ) : $title;
	}

	/** @param mixed $description */
	public static function rankMathDescription( $description ) {
		$row = self::current();
		if ( null === $row ) {
			return $description;
		}
		if ( '' !== $row['seo_description'] ) {
			return (string) $row['seo_description'];
		}

		return '' !== $row['excerpt'] ? (string) $row['excerpt'] : wp_trim_words( wp_strip_all_tags( (string) $row['content'] ), 30, '…' );
	}

	/**
	 * Lists of posts show only the document posts translated into the page's language (one-post queries keep
	 * working: an untranslated post opens with its source content and noindex).
	 *
	 * @param mixed $where
	 * @param mixed $query
	 */
	public static function onlyTranslated( $where, $query ) {
		if ( ! is_string( $where ) || ! $query instanceof \WP_Query || ! self::on() || $query->is_singular() || ! apply_filters( 'marclie_lingua/filter_query', true, $query ) ) {
			return $where;
		}
		$types = array_values( array_filter( Blog::types(), array( PostTranslations::class, 'isDocumentType' ) ) );
		if ( ! $types ) {
			return $where;
		}
		global $wpdb;

		$ids = PostTranslations::publishedIds( Language::current() );
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return $where . $wpdb->prepare( " AND ( {$wpdb->posts}.post_type NOT IN ($in)", $types ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			. ( $ids ? " OR {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') )' : ' )' );
	}

	/**
	 * Searches match document posts by their translation in the page's language, other post types as usual.
	 *
	 * @param mixed $search SQL ( " AND (…)" ) built by WP_Query
	 * @param mixed $query
	 */
	public static function search( $search, $query ) {
		if ( ! is_string( $search ) || '' === $search || ! $query instanceof \WP_Query || ! self::on() || '' === (string) $query->get( 's' ) ) {
			return $search;
		}
		$types = array_values( array_filter( Blog::types(), array( PostTranslations::class, 'isDocumentType' ) ) );
		if ( ! $types ) {
			return $search;
		}
		global $wpdb;

		$like = '%' . $wpdb->esc_like( (string) $query->get( 's' ) ) . '%';
		$ids  = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT post_id FROM ' . PostTranslations::table() . " WHERE lang = %s AND status = 'published' AND ( title LIKE %s OR content LIKE %s OR excerpt LIKE %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
					Language::current(),
					$like,
					$like,
					$like
				)
			)
		);
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$own  = preg_replace( '/^\s*AND\s*/i', '', $search );

		return $wpdb->prepare( " AND ( ( {$wpdb->posts}.post_type NOT IN ($in) AND ", $types ) . $own . ' )' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			. ( $ids ? " OR {$wpdb->posts}.ID IN (" . implode( ',', $ids ) . ')' : '' ) . ' )';
	}
}
