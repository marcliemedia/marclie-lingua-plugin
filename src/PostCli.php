<?php
/**
 * WP-CLI `wp marclie-lingua post <export|import>`: whole-post translations as JSON, for translating posts outside
 * WordPress (a translator or an AI drafts the `translation` part of each item, then it is imported as a draft or
 * published).
 *
 * Item: { id, lang, url, state, source: { title, slug, excerpt, content }, translation: { title, slug, excerpt,
 * content, seo_title, seo_description } } — content is the post's HTML; keep its tags, links and images.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class PostCli {

	/**
	 * Writes posts with their translation in one language.
	 *
	 * ## OPTIONS
	 * [<ids>...]
	 * : Post IDs; default every published post translated as a document.
	 *
	 * --lang=<code>
	 * : Translated language.
	 *
	 * [--untranslated]
	 * : Only posts without a translation in the language.
	 *
	 * [--outdated]
	 * : Only posts edited since their translation was saved or reviewed.
	 *
	 * [--file=<file>]
	 * : Output file; default STDOUT.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		$lang = self::lang( $assoc );
		$ids  = $args ? array_map( 'intval', $args ) : get_posts(
			array(
				'post_type'        => self::types(),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || ! PostTranslations::isDocumentType( $post->post_type ) ) {
				\WP_CLI::warning( sprintf( 'Post %d is not translated as a document; skipped.', (int) $id ) );
				continue;
			}
			$state = PostTranslations::state( $post, $lang );
			if ( ( isset( $assoc['untranslated'] ) && 'none' !== $state ) || ( isset( $assoc['outdated'] ) && 'outdated' !== $state ) ) {
				continue;
			}
			$row     = PostTranslations::get( $post->ID, $lang );
			$items[] = array(
				'id'          => $post->ID,
				'lang'        => $lang,
				'url'         => Router::url( (string) get_permalink( $post ), $lang ),
				'state'       => $state,
				'source'      => array(
					'title'   => $post->post_title,
					'slug'    => $post->post_name,
					'excerpt' => $post->post_excerpt,
					'content' => $post->post_content,
				),
				'translation' => array_combine( PostTranslations::FIELDS, array_map( static fn( string $f ): string => (string) ( $row[ $f ] ?? '' ), PostTranslations::FIELDS ) ),
			);
		}
		$json = (string) wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( empty( $assoc['file'] ) ) {
			\WP_CLI::line( $json );
			return;
		}
		file_put_contents( $assoc['file'], $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export.
		\WP_CLI::success( sprintf( '%d posts written to %s.', count( $items ), $assoc['file'] ) );
	}

	/**
	 * Saves translations from an export file (items whose `translation.title` and `translation.content` are filled).
	 *
	 * ## OPTIONS
	 * <file>
	 * : JSON file written by `post export` (translation part filled in).
	 *
	 * [--publish]
	 * : Publish the translations; default: save as drafts.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$items = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $items ) ) {
			\WP_CLI::error( 'Not a JSON list.' );
		}
		$saved = 0;
		foreach ( $items as $item ) {
			$id          = (int) ( $item['id'] ?? 0 );
			$lang        = (string) ( $item['lang'] ?? '' );
			$translation = (array) ( $item['translation'] ?? array() );
			$post        = get_post( $id );
			if ( ! $post instanceof \WP_Post || ! Settings::isTarget( $lang ) || '' === trim( (string) ( $translation['title'] ?? '' ) ) || '' === trim( (string) ( $translation['content'] ?? '' ) ) ) {
				\WP_CLI::warning( sprintf( 'Item for post %d (%s) skipped: unknown post or language, or empty title / content.', $id, $lang ) );
				continue;
			}
			foreach ( self::structureWarnings( $post->post_content, (string) $translation['content'] ) as $warning ) {
				\WP_CLI::warning( sprintf( 'Post %d (%s): %s', $id, $lang, $warning ) );
			}
			// the translation was made from the exported source: if the post changed since, it stays "needs review"
			$basedOn = is_array( $item['source'] ?? null ) ? (array) $item['source'] : null;
			if ( null !== $basedOn && PostTranslations::snapshot( $post ) !== array_intersect_key( array_merge( array( 'title' => '', 'content' => '', 'excerpt' => '' ), $basedOn ), array( 'title' => 1, 'content' => 1, 'excerpt' => 1 ) ) ) {
				\WP_CLI::warning( sprintf( 'Post %d (%s) was edited after the export: the translation is marked "Needs review".', $id, $lang ) );
			}
			$row = PostTranslations::save( $id, $lang, $translation, isset( $assoc['publish'] ) ? 'published' : ( PostTranslations::get( $id, $lang )['status'] ?? 'draft' ), $basedOn );
			\WP_CLI::log( sprintf( '%d %s %s → %s', $id, $lang, $row['status'], Router::url( (string) get_permalink( $id ), $lang ) ) );
			++$saved;
		}
		\WP_CLI::success( sprintf( '%d translations saved.', $saved ) );
	}

	/**
	 * Differences in the number of headings, links and images between source and translation (allowed in a
	 * document translation, but usually a sign of a broken or shortened translation).
	 *
	 * @return list<string>
	 */
	private static function structureWarnings( string $source, string $translation ): array {
		$warnings = array();
		foreach ( array( 'headings' => '/<h[1-6][\s>]/i', 'links' => '/<a\s/i', 'images' => '/<img\s/i' ) as $name => $pattern ) {
			$a = preg_match_all( $pattern, $source );
			$b = preg_match_all( $pattern, $translation );
			if ( $a !== $b ) {
				$warnings[] = sprintf( '%s: %d in the source, %d in the translation', $name, $a, $b );
			}
		}

		return $warnings;
	}

	/** @return list<string> */
	private static function types(): array {
		return array_values( array_filter( Blog::types(), array( PostTranslations::class, 'isDocumentType' ) ) );
	}

	/** @param array<string, string> $assoc */
	private static function lang( array $assoc ): string {
		if ( ! Settings::documentBlog() ) {
			\WP_CLI::error( 'Blog posts are not translated as documents (Translations › Settings › Blog posts).' );
		}
		$lang = (string) ( $assoc['lang'] ?? '' );
		if ( ! Settings::isTarget( $lang ) ) {
			\WP_CLI::error( sprintf( 'Choose a translated language with --lang=<code> (%s).', implode( ', ', Settings::targetCodes() ) ) );
		}

		return $lang;
	}
}
