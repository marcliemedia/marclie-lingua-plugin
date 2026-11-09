<?php
/**
 * Whole-post translations on translated pages (blog mode `document`):
 * - the content of a post with a published translation in the page's language is swapped where WordPress splits it
 *   into pages (`content_pagination`), so `<!--more-->` teasers, `<!--nextpage-->` pages and generated excerpts work
 *   as for the original, and the content filters run once;
 * - the content of every document post is wrapped in `<!--mlg:doc-->…<!--/mlg:doc-->`, so HtmlTranslator leaves it
 *   alone (a post without a translation keeps its source content there, and its page is noindex — Frontend);
 * - title (`the_title`, `single_post_title`), excerpt (`get_the_excerpt`, `wp_trim_excerpt` — Bricks), the SEO title
 *   / description (core `document_title_parts`; Rank Math title, description, Open Graph and Twitter) come from the
 *   translation; password-protected posts show nothing of it until unlocked;
 * - every title / excerpt printed for a document post is "owned": HtmlTranslator never stores it as a string;
 * - lists of posts (any WP_Query that is not for one post) show only the posts translated into the page's language
 *   (filter `marclie_lingua/filter_query` ( bool, WP_Query ) to keep a query unfiltered);
 * - searches find posts by their translated title, content and excerpt.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class PostRenderer {

	/** @var array<string, true> loosely normalized plain texts printed for document posts on this page */
	private static array $owned = array();

	public static function hooks(): void {
		add_filter( 'content_pagination', array( self::class, 'pages' ), 10, 2 );
		add_filter( 'the_content', array( self::class, 'content' ), 999 );
		add_filter( 'the_title', array( self::class, 'title' ), 999, 2 );
		add_filter( 'single_post_title', array( self::class, 'singleTitle' ), 999, 2 );
		add_filter( 'get_the_excerpt', array( self::class, 'excerpt' ), 999, 2 );
		add_filter( 'wp_trim_excerpt', array( self::class, 'trimmedExcerpt' ), 999, 2 );
		add_filter( 'the_excerpt', array( self::class, 'ownOutput' ), 999 );
		add_filter( 'wp_trim_words', array( self::class, 'trimmedWords' ), 999, 4 );
		add_filter( 'document_title_parts', array( self::class, 'documentTitle' ), 999 );
		add_filter( 'rank_math/frontend/title', array( self::class, 'rankMathTitle' ), 999 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'rankMathDescription' ), 999 );
		foreach ( array( 'facebook/og_title', 'twitter/twitter_title' ) as $tag ) {
			add_filter( 'rank_math/opengraph/' . $tag, array( self::class, 'socialTitle' ), 999 );
		}
		foreach ( array( 'facebook/og_description', 'twitter/twitter_description' ) as $tag ) {
			add_filter( 'rank_math/opengraph/' . $tag, array( self::class, 'rankMathDescription' ), 999 );
		}
		add_filter( 'the_posts', array( self::class, 'prime' ), 10, 2 );
		add_filter( 'posts_where', array( self::class, 'onlyTranslated' ), 10, 2 );
		add_filter( 'posts_search', array( self::class, 'search' ), 10, 2 );
	}

	/** A translated frontend request in document mode. */
	private static function on(): bool {
		return Settings::documentBlog() && Language::isTranslated() && ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** @return list<string> Post types translated as documents. */
	private static function types(): array {
		return array_values( array_filter( Blog::types(), array( PostTranslations::class, 'isDocumentType' ) ) );
	}

	/** @param mixed $post */
	private static function isDocument( $post ): bool {
		return $post instanceof \WP_Post && self::on() && PostTranslations::isDocumentType( $post->post_type );
	}

	/** @return array<string, mixed>|null The published translation of a document post in the page's language. */
	public static function translation( int $postId ): ?array {
		return self::isDocument( get_post( $postId ) ) ? PostTranslations::published( $postId, Language::current() ) : null;
	}

	/** Plain texts HtmlTranslator must leave alone. @return list<string> */
	public static function owned(): array {
		return array_keys( self::$owned );
	}

	private static function own( string $text ): string {
		$plain = Format::loose( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $plain ) {
			self::$owned[ $plain ] = true;
		}

		return $text;
	}

	/**
	 * The translated content split like WordPress splits a post (`<!--nextpage-->`).
	 *
	 * @param mixed $pages
	 * @param mixed $post
	 * @return mixed
	 */
	public static function pages( $pages, $post ) {
		if ( ! is_array( $pages ) || ! self::isDocument( $post ) ) {
			return $pages;
		}
		$row = PostTranslations::published( $post->ID, Language::current() );
		if ( null === $row ) {
			return $pages;
		}
		// split as WordPress splits the original (WP_Query::generate_postdata)
		$content = str_replace( array( "\n<!--nextpage-->\n", "\n<!--nextpage-->", "<!--nextpage-->\n" ), '<!--nextpage-->', (string) $row['content'] );
		$content = str_replace( array( '<!-- wp:nextpage -->', '<!-- /wp:nextpage -->' ), '', $content );
		if ( str_starts_with( $content, '<!--nextpage-->' ) ) {
			$content = substr( $content, 15 );
		}

		return explode( '<!--nextpage-->', $content );
	}

	public static function content( $content ) {
		$post = get_post();
		if ( ! is_string( $content ) || ! self::isDocument( $post ) || post_password_required( $post ) ) {
			return $content; // a locked post prints only its password form, which is translated like any text
		}

		return '<!--' . HtmlTranslator::DOC_START . '-->' . $content . '<!--' . HtmlTranslator::DOC_END . '-->';
	}

	/** @param mixed $title */
	public static function title( $title, $postId = 0 ) {
		if ( ! is_string( $title ) || ! $postId || ! self::isDocument( get_post( (int) $postId ) ) ) {
			return $title;
		}
		$row = PostTranslations::published( (int) $postId, Language::current() );

		return self::own( null !== $row && '' !== $row['title'] ? (string) $row['title'] : $title ); // sanitized on save, printed like core titles
	}

	/** @param mixed $title */
	public static function singleTitle( $title, $post = null ) {
		return $post instanceof \WP_Post ? self::title( $title, $post->ID ) : $title;
	}

	/** The translated excerpt of a post (its own, else generated from the translated content); null: none. */
	private static function translatedExcerpt( \WP_Post $post, int $words = 0 ): ?string {
		$row = PostTranslations::published( $post->ID, Language::current() );
		if ( null === $row ) {
			return null;
		}
		$words = $words > 0 ? $words : (int) apply_filters( 'excerpt_length', 55 );
		if ( '' !== $row['excerpt'] ) {
			return (string) $row['excerpt'];
		}

		return wp_trim_words( excerpt_remove_blocks( strip_shortcodes( (string) $row['content'] ) ), $words, (string) apply_filters( 'excerpt_more', ' [&hellip;]' ) );
	}

	/** @param mixed $excerpt */
	public static function excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! is_string( $excerpt ) || ! self::isDocument( $post ) || post_password_required( $post ) ) {
			return $excerpt;
		}

		return self::own( self::translatedExcerpt( $post ) ?? $excerpt );
	}

	/**
	 * Excerpts built outside `get_the_excerpt` (Bricks reads the post fields and trims them itself, then applies
	 * `wp_trim_excerpt`): same length, translated text.
	 *
	 * @param mixed $text
	 */
	public static function trimmedExcerpt( $text, $raw = '' ) {
		$post = get_post();
		if ( ! is_string( $text ) || ! self::isDocument( $post ) || post_password_required( $post ) ) {
			return $text;
		}
		$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $text ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		$out   = self::translatedExcerpt( $post, max( 1, $words ) );

		return self::own( null !== $out ? $out : $text );
	}

	/** The excerpt as printed (texturized, wrapped) is owned too. @param mixed $excerpt */
	public static function ownOutput( $excerpt ) {
		return is_string( $excerpt ) && self::isDocument( get_post() ) ? self::own( $excerpt ) : $excerpt;
	}

	/**
	 * A re-trimmed owned text (e.g. the core Post Excerpt block trims the excerpt again) stays owned.
	 *
	 * @param mixed $text
	 */
	public static function trimmedWords( $text, $num = 55, $more = null, $original = '' ) {
		if ( is_string( $text ) && is_string( $original ) && '' !== $original && self::on()
			&& isset( self::$owned[ Format::loose( html_entity_decode( wp_strip_all_tags( $original ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ] ) ) {
			self::own( $text );
		}

		return $text;
	}

	/** The translation of the post shown on this page (singular). @return array<string, mixed>|null */
	private static function current(): ?array {
		if ( ! is_singular() ) {
			return null;
		}
		$post = get_queried_object();

		return $post instanceof \WP_Post ? self::translation( $post->ID ) : null;
	}

	private static function locked(): bool {
		$post = get_queried_object();

		return $post instanceof \WP_Post && post_password_required( $post );
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
		$source = get_queried_object();
		if ( $source instanceof \WP_Post && '' !== $source->post_title && str_contains( $title, $source->post_title ) ) {
			return str_replace( $source->post_title, (string) $row['title'], $title );
		}
		// a custom Rank Math title of the original: the translated title with the site's separator and name
		$sep = class_exists( \RankMath\Helper::class ) ? (string) \RankMath\Helper::get_settings( 'titles.title_separator', '-' ) : '-';

		return (string) $row['title'] . ' ' . $sep . ' ' . get_bloginfo( 'name' );
	}

	/** @param mixed $title */
	public static function socialTitle( $title ) {
		$row = self::current();

		return null !== $row ? ( '' !== $row['seo_title'] ? (string) $row['seo_title'] : (string) $row['title'] ) : $title;
	}

	/** @param mixed $description */
	public static function rankMathDescription( $description ) {
		$row = self::current();
		if ( null === $row ) {
			return $description;
		}
		if ( self::locked() ) {
			return ''; // titles of protected posts are public (as in core), their text is not
		}
		if ( '' !== $row['seo_description'] ) {
			return (string) $row['seo_description'];
		}

		return '' !== $row['excerpt'] ? (string) $row['excerpt'] : wp_trim_words( wp_strip_all_tags( (string) $row['content'] ), 30, '…' );
	}

	/**
	 * Loads the translations of a list of posts in one query.
	 *
	 * @param mixed $posts
	 * @return mixed
	 */
	public static function prime( $posts, $query = null ) {
		if ( ! is_array( $posts ) || count( $posts ) < 2 || ! self::on() ) {
			return $posts;
		}
		$ids = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && PostTranslations::isDocumentType( $post->post_type ) ) {
				$ids[] = $post->ID;
			}
		}
		PostTranslations::prime( $ids );

		return $posts;
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
		$types = self::types();
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
	 * Searches match document posts by their translation in the page's language (every search term, `-term`
	 * excludes), other post types as usual.
	 *
	 * @param mixed $search SQL ( " AND (…)" ) built by WP_Query
	 * @param mixed $query
	 */
	public static function search( $search, $query ) {
		if ( ! is_string( $search ) || '' === $search || ! $query instanceof \WP_Query || ! self::on() || '' === (string) $query->get( 's' ) ) {
			return $search;
		}
		$types = self::types();
		if ( ! $types ) {
			return $search;
		}
		global $wpdb;

		$terms = (array) $query->get( 'search_terms' );
		$terms = $terms ? $terms : array( (string) $query->get( 's' ) );
		$sql   = array( 'lang = %s', "status = 'published'" );
		$args  = array( Language::current() );
		foreach ( $terms as $term ) {
			$term    = (string) $term;
			$exclude = str_starts_with( $term, '-' ) && strlen( $term ) > 1;
			$like    = '%' . $wpdb->esc_like( $exclude ? substr( $term, 1 ) : $term ) . '%';
			$sql[]   = $exclude ? '( title NOT LIKE %s AND content NOT LIKE %s AND excerpt NOT LIKE %s )' : '( title LIKE %s OR content LIKE %s OR excerpt LIKE %s )';
			array_push( $args, $like, $like, $like );
		}
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . PostTranslations::table() . ' WHERE ' . implode( ' AND ', $sql ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name + placeholders.
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$own = preg_replace( '/^\s*AND\s*/i', '', $search );
		$hit = $ids ? " OR ( {$wpdb->posts}.ID IN (" . implode( ',', $ids ) . ')' . ( is_user_logged_in() ? '' : " AND {$wpdb->posts}.post_password = ''" ) . ' )' : '';

		return $wpdb->prepare( " AND ( ( {$wpdb->posts}.post_type NOT IN ($in) AND ", $types ) . $own . ' )' . $hit . ' )'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
	}
}
