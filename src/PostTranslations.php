<?php
/**
 * Whole-post translations (blog mode `document`): one row per post and translated language in
 * `{prefix}mlg_post_translations` with the translated title, content (HTML), excerpt, slug and SEO title /
 * description, a status (`draft` | `published`) and the source as it was when the translation was last saved or
 * reviewed (`source_hash`, `source_snapshot`). When the post is edited afterwards the translation is "outdated":
 * it stays published (and indexed) and is flagged in admin until someone reviews it.
 *
 * `previous_slugs` keeps the translated slugs a published translation had before, so their URLs redirect (Paths).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class PostTranslations {

	/** Translated fields. */
	public const FIELDS = array( 'title', 'content', 'excerpt', 'slug', 'seo_title', 'seo_description' );

	public const STATUSES = array( 'draft', 'published' );

	/** @var array<int, array<string, array<string, mixed>>> post ID => lang => row (request cache) */
	private static array $cache = array();

	/** @var array<string, list<int>> lang => published post IDs (request cache) */
	private static array $published = array();

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mlg_post_translations';
	}

	/** CREATE TABLE statement for dbDelta. */
	public static function schema(): string {
		global $wpdb;

		return 'CREATE TABLE ' . self::table() . " (
  post_id bigint(20) unsigned NOT NULL,
  lang varchar(12) NOT NULL,
  title text NOT NULL,
  content longtext NOT NULL,
  excerpt text NOT NULL,
  slug varchar(200) NOT NULL default '',
  seo_title text NOT NULL,
  seo_description text NOT NULL,
  status varchar(10) NOT NULL default 'draft',
  source_hash char(32) NOT NULL default '',
  source_snapshot longtext NOT NULL,
  previous_slugs text NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (post_id,lang),
  KEY lang_status (lang,status),
  KEY lang_slug (lang,slug)
) " . $wpdb->get_charset_collate() . ';';
	}

	public static function hooks(): void {
		add_action( 'deleted_post', array( self::class, 'deletePost' ) );
	}

	/** The post type is translated as whole documents (blog mode `document`). */
	public static function isDocumentType( string $postType ): bool {
		return Settings::documentBlog() && in_array( $postType, Blog::types(), true );
	}

	/** @return array<string, array<string, mixed>> lang => row, for one post */
	public static function forPost( int $postId ): array {
		global $wpdb;

		if ( ! isset( self::$cache[ $postId ] ) ) {
			self::$cache[ $postId ] = array();
			$rows                   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d', $postId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			foreach ( (array) $rows as $row ) {
				self::$cache[ $postId ][ (string) $row['lang'] ] = self::decode( $row );
			}
		}

		return self::$cache[ $postId ];
	}

	/**
	 * Loads the translations of several posts in one query (lists of posts).
	 *
	 * @param list<int> $postIds
	 */
	public static function prime( array $postIds ): void {
		global $wpdb;

		$ids = array_values( array_diff( array_unique( array_map( 'intval', $postIds ) ), array_keys( self::$cache ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$cache[ $id ] = array();
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE post_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name + integers.
		foreach ( (array) $rows as $row ) {
			self::$cache[ (int) $row['post_id'] ][ (string) $row['lang'] ] = self::decode( $row );
		}
	}

	/** @return array<string, mixed>|null */
	public static function get( int $postId, string $lang ): ?array {
		return self::forPost( $postId )[ $lang ] ?? null;
	}

	/** @return array<string, mixed>|null The translation shown to visitors (published), or null. */
	public static function published( int $postId, string $lang ): ?array {
		$row = self::get( $postId, $lang );

		return null !== $row && 'published' === $row['status'] ? $row : null;
	}

	/**
	 * Saves a translation. Slug: sanitized and made unique for the language; a published translation that changes
	 * its slug remembers the old one. The source snapshot is refreshed (saving = the translation matches the post),
	 * unless `$basedOn` gives the source the translation was made from (an import of an older export): then a post
	 * edited since stays "outdated".
	 *
	 * @param array<string, mixed>                                  $fields  Subset of FIELDS.
	 * @param array{title?: string, content?: string, excerpt?: string}|null $basedOn
	 * @return array<string, mixed> The saved row.
	 */
	public static function save( int $postId, string $lang, array $fields, string $status, ?array $basedOn = null ): array {
		global $wpdb;

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || ! Settings::isTarget( $lang ) ) {
			throw new \InvalidArgumentException( 'Unknown post or language.' );
		}
		$old  = self::get( $postId, $lang );
		$data = array();
		foreach ( self::FIELDS as $field ) {
			$data[ $field ] = (string) ( $fields[ $field ] ?? ( $old[ $field ] ?? '' ) );
		}
		$data['title']           = sanitize_text_field( $data['title'] );
		$data['excerpt']         = sanitize_textarea_field( $data['excerpt'] );
		$data['content']         = current_user_can( 'unfiltered_html' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ? $data['content'] : wp_kses_post( $data['content'] );
		$data['seo_title']       = sanitize_text_field( $data['seo_title'] );
		$data['seo_description'] = sanitize_textarea_field( $data['seo_description'] );
		$data['slug']            = self::uniqueSlug( $post, $lang, sanitize_title( $data['slug'] ) );

		$previous = (array) ( $old['previous_slugs'] ?? array() );
		if ( null !== $old && 'published' === $old['status'] && '' !== (string) $old['slug'] && $old['slug'] !== $data['slug'] ) {
			$previous[] = (string) $old['slug'];
		}
		$previous = array_values( array_diff( array_unique( $previous ), array( $data['slug'] ) ) );

		$row = array_merge(
			$data,
			array(
				'post_id'         => $postId,
				'lang'            => $lang,
				'status'          => in_array( $status, self::STATUSES, true ) ? $status : 'draft',
				'source_hash'     => null !== $basedOn ? self::hash( (string) ( $basedOn['title'] ?? '' ), (string) ( $basedOn['content'] ?? '' ), (string) ( $basedOn['excerpt'] ?? '' ) ) : self::sourceHash( $post ),
				'source_snapshot' => (string) wp_json_encode( null !== $basedOn ? array( 'title' => (string) ( $basedOn['title'] ?? '' ), 'content' => (string) ( $basedOn['content'] ?? '' ), 'excerpt' => (string) ( $basedOn['excerpt'] ?? '' ) ) : self::snapshot( $post ) ),
				'previous_slugs'  => (string) wp_json_encode( $previous ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);
		if ( null === $old ) {
			$wpdb->insert( self::table(), $row );
		} else {
			$wpdb->update( self::table(), $row, array( 'post_id' => $postId, 'lang' => $lang ) );
		}
		unset( self::$cache[ $postId ] );
		self::$published = array();
		Coverage::record( $lang, (string) get_permalink( $postId ), 0 ); // judged again when the page is rendered
		do_action( 'marclie_lingua/post_translation_saved', $postId, $lang );

		return (array) self::get( $postId, $lang );
	}

	/** The translation matches the current post again (reviewed after the post was edited), unchanged otherwise. */
	public static function markReviewed( int $postId, string $lang ): void {
		global $wpdb;

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || null === self::get( $postId, $lang ) ) {
			return;
		}
		$wpdb->update(
			self::table(),
			array(
				'source_hash'     => self::sourceHash( $post ),
				'source_snapshot' => (string) wp_json_encode( self::snapshot( $post ) ),
			),
			array( 'post_id' => $postId, 'lang' => $lang )
		);
		unset( self::$cache[ $postId ] );
		self::$published = array();
	}

	public static function delete( int $postId, string $lang ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'post_id' => $postId, 'lang' => $lang ) );
		unset( self::$cache[ $postId ] );
		self::$published = array();
		do_action( 'marclie_lingua/post_translation_saved', $postId, $lang );
	}

	/** Deleted post: its translations go too. */
	public static function deletePost( $postId ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'post_id' => (int) $postId ) );
		unset( self::$cache[ (int) $postId ] );
		self::$published = array();
		do_action( 'marclie_lingua/post_translation_saved', (int) $postId, '' );
	}

	/**
	 * IDs of the posts with a published translation in a language.
	 *
	 * @return list<int>
	 */
	public static function publishedIds( string $lang ): array {
		global $wpdb;

		if ( ! isset( self::$published[ $lang ] ) ) {
			self::$published[ $lang ] = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . self::table() . " WHERE lang = %s AND status = 'published'", $lang ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}

		return self::$published[ $lang ];
	}

	/**
	 * State of a post in a language: `none`, `draft`, `published` or `outdated` (published, but the post changed
	 * since the translation was saved or reviewed).
	 */
	public static function state( \WP_Post $post, string $lang ): string {
		$row = self::get( $post->ID, $lang );
		if ( null === $row ) {
			return 'none';
		}
		if ( 'published' !== $row['status'] ) {
			return 'draft';
		}

		return self::isOutdated( $row, $post ) ? 'outdated' : 'published';
	}

	/** @param array<string, mixed> $row */
	public static function isOutdated( array $row, \WP_Post $post ): bool {
		return (string) $row['source_hash'] !== self::sourceHash( $post );
	}

	public static function sourceHash( \WP_Post $post ): string {
		return self::hash( $post->post_title, $post->post_content, $post->post_excerpt );
	}

	private static function hash( string $title, string $content, string $excerpt ): string {
		return md5( $title . "\0" . $content . "\0" . $excerpt );
	}

	/** @return array{title: string, content: string, excerpt: string} */
	public static function snapshot( \WP_Post $post ): array {
		return array(
			'title'   => (string) $post->post_title,
			'content' => (string) $post->post_content,
			'excerpt' => (string) $post->post_excerpt,
		);
	}

	/**
	 * A slug free in the language: not another post's translated slug (current or former — former ones redirect),
	 * not the own slug of another public post or page (they could share the URL). Empty = the source slug is used.
	 */
	private static function uniqueSlug( \WP_Post $post, string $lang, string $slug ): string {
		global $wpdb;

		if ( '' === $slug || $slug === $post->post_name ) {
			return '' === $slug ? '' : $slug;
		}
		$candidate = $slug;
		for ( $n = 2; $n < 100; $n++ ) {
			$types = array_values( get_post_types( array( 'public' => true ) ) );
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE lang = %s AND post_id <> %d AND ( slug = %s OR previous_slugs LIKE %s )', $lang, $post->ID, $candidate, '%"' . $wpdb->esc_like( $candidate ) . '"%' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_name = %s AND ID <> %d AND post_status NOT IN ('trash', 'auto-draft') AND post_type IN ($in)", array_merge( array( $candidate, $post->ID ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			if ( 0 === $taken ) {
				return $candidate;
			}
			$candidate = $slug . '-' . $n;
		}

		return $candidate;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$row['post_id']         = (int) $row['post_id'];
		$row['source_snapshot'] = json_decode( (string) $row['source_snapshot'], true ) ?: array( 'title' => '', 'content' => '', 'excerpt' => '' );
		$row['previous_slugs']  = json_decode( (string) $row['previous_slugs'], true ) ?: array();

		return $row;
	}
}
