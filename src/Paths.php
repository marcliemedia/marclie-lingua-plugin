<?php
/**
 * Translated post URLs: a published whole-post translation with its own slug moves the post's path in that language
 * (`/vodic/plaze-novalje/` → `/en/vodic/novalja-beaches/`). Router::url() maps source ↔ translated paths, so links,
 * the switcher, hreflang, canonical URLs, sitemaps and redirects all use the translated path; Router::boot() maps an
 * incoming translated path back to the source path before WordPress parses the request.
 *
 * Option `mlg_paths` (autoloaded, rebuilt when a translation, a post's slug, the permalinks or the settings change):
 * [ 'to' => [ lang => [ source path => translated path ] ], 'old' => [ lang => [ former translated path => source
 * path ] ] ]. Paths are relative to the site root, URL-encoded as in permalinks, with the permalink's trailing slash.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Paths {

	public const OPTION = 'mlg_paths';

	/** @var array{to: array<string, array<string, string>>, from: array<string, array<string, string>>, old: array<string, array<string, string>>}|null */
	private static ?array $map = null;

	public static function hooks(): void {
		add_action( 'marclie_lingua/post_translation_saved', array( self::class, 'rebuild' ) );
		add_action( 'save_post', array( self::class, 'postSaved' ), 20, 2 );
		add_action( 'update_option_permalink_structure', array( self::class, 'rebuild' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'rebuild' ) );
	}

	/** @param mixed $post */
	public static function postSaved( $postId, $post ): void {
		if ( $post instanceof \WP_Post && in_array( $post->post_type, Blog::types(), true ) && ! wp_is_post_revision( $post ) ) {
			self::rebuild();
		}
	}

	public static function rebuild(): void {
		global $wpdb;

		$to  = array();
		$old = array();
		if ( Settings::documentBlog() ) {
			$rows = $wpdb->get_results( 'SELECT post_id, lang, slug, previous_slugs FROM ' . PostTranslations::table() . " WHERE status = 'published'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			foreach ( (array) $rows as $row ) {
				$post = get_post( (int) $row['post_id'] );
				if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! Settings::isTarget( (string) $row['lang'] ) ) {
					continue;
				}
				$source = self::relative( (string) get_permalink( $post ) );
				if ( null === $source ) {
					continue;
				}
				$lang = (string) $row['lang'];
				if ( '' !== (string) $row['slug'] ) {
					$to[ $lang ][ $source ] = self::withSlug( $source, $post->post_name, (string) $row['slug'] );
				}
				foreach ( (array) json_decode( (string) $row['previous_slugs'], true ) as $slug ) {
					$old[ $lang ][ self::withSlug( $source, $post->post_name, (string) $slug ) ] = $source;
				}
			}
		}
		update_option( self::OPTION, array( 'to' => $to, 'old' => $old ), true );
		self::$map = null;
	}

	/** @return array{to: array<string, array<string, string>>, from: array<string, array<string, string>>, old: array<string, array<string, string>>} */
	private static function map(): array {
		if ( null === self::$map ) {
			$stored    = get_option( self::OPTION, array() );
			$to        = is_array( $stored ) && is_array( $stored['to'] ?? null ) ? $stored['to'] : array();
			$from      = array();
			foreach ( $to as $lang => $paths ) {
				$from[ $lang ] = array_flip( (array) $paths );
			}
			self::$map = array(
				'to'   => $to,
				'from' => $from,
				'old'  => is_array( $stored ) && is_array( $stored['old'] ?? null ) ? $stored['old'] : array(),
			);
		}

		return self::$map;
	}

	/** Source path → its path in `$lang` (unchanged when the post has no translated slug there). */
	public static function toTranslated( string $lang, string $path ): string {
		return self::lookup( self::map()['to'][ $lang ] ?? array(), $path ) ?? $path;
	}

	/** Path in `$lang` → source path (unchanged when it is not a translated slug). */
	public static function toSource( string $lang, string $path ): string {
		return self::lookup( self::map()['from'][ $lang ] ?? array(), $path ) ?? $path;
	}

	/** A source path requested in `$lang` whose post has a translated slug there, or a former translated slug: the
	 *  current translated path to redirect to (null: nothing to redirect). */
	public static function redirect( string $lang, string $path ): ?string {
		$map    = self::map();
		$source = self::lookup( $map['old'][ $lang ] ?? array(), $path ) ?? $path;
		$target = self::lookup( $map['to'][ $lang ] ?? array(), $source );

		return null !== $target && $target !== $path ? $target : null;
	}

	/** Same path with or without its trailing slash; keeps the caller's form. */
	private static function lookup( array $paths, string $path ): ?string {
		if ( isset( $paths[ $path ] ) ) {
			return (string) $paths[ $path ];
		}
		$other = str_ends_with( $path, '/' ) ? untrailingslashit( $path ) : $path . '/';
		if ( isset( $paths[ $other ] ) ) {
			$found = (string) $paths[ $other ];

			return str_ends_with( $path, '/' ) ? trailingslashit( $found ) : untrailingslashit( $found );
		}

		return null;
	}

	/** Path of a site URL relative to the site root, or null. */
	private static function relative( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = Router::basePath();
		if ( '' === $path || ! str_starts_with( $path . '/', $base . '/' ) ) {
			return null;
		}
		$rel = substr( $path, strlen( $base ) );

		return '' === $rel ? '/' : $rel;
	}

	/** The path with its last segment equal to the post's slug replaced. */
	private static function withSlug( string $path, string $postName, string $slug ): string {
		$segments = explode( '/', $path );
		for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
			if ( '' !== $postName && $segments[ $i ] === $postName ) {
				$segments[ $i ] = $slug;
				break;
			}
		}

		return implode( '/', $segments );
	}
}
