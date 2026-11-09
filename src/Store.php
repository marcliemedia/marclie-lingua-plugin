<?php
/**
 * Tables:
 * - `{prefix}mlg_strings`: one row per source string (unique by md5 hash); `path` = page where it was first
 *   found, `last_seen` = last site scan that found it.
 * - `{prefix}mlg_translations`: one row per string and translated language, status `auto` (imported, not checked
 *   by a person) or `edited` (saved in admin). No row = not translated yet (status `new`): the page shows the
 *   source text.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Store {

	/** Admin views by status; `new` = no translation row. */
	public const STATUSES = array( 'new', 'auto', 'edited' );

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mlg_strings';
	}

	public static function translationsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'mlg_translations';
	}

	/**
	 * CREATE TABLE statements for dbDelta (two spaces after PRIMARY KEY: dbDelta parses it textually).
	 *
	 * @return list<string>
	 */
	public static function schema(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return array(
			'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL auto_increment,
  hash char(32) NOT NULL,
  source longtext NOT NULL,
  path varchar(255) NOT NULL default '',
  created_at datetime NOT NULL,
  last_seen datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash)
) $collate;",
			'CREATE TABLE ' . self::translationsTable() . " (
  string_id bigint(20) unsigned NOT NULL,
  lang varchar(12) NOT NULL,
  translation longtext NOT NULL,
  status varchar(10) NOT NULL default 'auto',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (string_id,lang),
  KEY lang_status (lang,status)
) $collate;",
		);
	}

	/**
	 * Translations of `$hashes` in `$lang`: hash => translation ('' = stored source, not translated yet).
	 * Hashes not stored at all are missing from the result.
	 *
	 * @param list<string> $hashes
	 * @return array<string, string>
	 */
	public static function lookup( array $hashes, string $lang ): array {
		global $wpdb;

		$found = array();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 400 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT s.hash, t.translation FROM ' . self::table() . ' s LEFT JOIN ' . self::translationsTable() . " t ON t.string_id = s.id AND t.lang = %s WHERE s.hash IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + placeholders.
					array_merge( array( $lang ), $chunk )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$found[ $row['hash'] ] = (string) $row['translation'];
			}
		}

		return $found;
	}

	/**
	 * Adds source strings that are not stored yet.
	 *
	 * @param array<string, string> $sources hash => source
	 */
	public static function register( array $sources, string $path ): void {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$suppress = $wpdb->suppress_errors( true ); // a parallel request may have added the same hash
		foreach ( $sources as $hash => $source ) {
			$wpdb->insert(
				self::table(),
				array(
					'hash'       => $hash,
					'source'     => $source,
					'path'       => substr( $path, 0, 255 ),
					'created_at' => $now,
				)
			);
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Marks strings as found by the current site scan.
	 *
	 * @param list<string> $hashes
	 */
	public static function touch( array $hashes ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 400 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET last_seen = %s WHERE hash IN ($in)", array_merge( array( $now ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholders.
		}
	}

	/** @return array{id: int, source: string, translation: string, status: string}|null */
	public static function find( int $id, string $lang ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.id, s.source, t.translation, t.status FROM ' . self::table() . ' s LEFT JOIN ' . self::translationsTable() . ' t ON t.string_id = s.id AND t.lang = %s WHERE s.id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
				$lang,
				$id
			),
			ARRAY_A
		);

		return is_array( $row )
			? array( 'id' => (int) $row['id'], 'source' => (string) $row['source'], 'translation' => (string) $row['translation'], 'status' => (string) ( $row['status'] ?? 'new' ) )
			: null;
	}

	/** Saves a translation (`edited` by default); an empty one deletes it (back to untranslated). */
	public static function saveTranslation( int $id, string $lang, string $translation, string $status = 'edited' ): void {
		global $wpdb;

		$where = array( 'string_id' => $id, 'lang' => $lang );
		if ( '' === $translation ) {
			$wpdb->delete( self::translationsTable(), $where );
			return;
		}
		$data   = array( 'translation' => $translation, 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::translationsTable() . ' WHERE string_id = %d AND lang = %s', $id, $lang ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( $exists ) {
			$wpdb->update( self::translationsTable(), $data, $where );
		} else {
			$wpdb->insert( self::translationsTable(), array_merge( $where, $data ) );
		}
	}

	/**
	 * Deletes strings with all their translations.
	 *
	 * @param list<int> $ids
	 */
	public static function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::translationsTable() . " WHERE string_id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholders.

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholders.
	}

	/** Deletes every string and translation (the source language changed). */
	public static function truncate(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . self::translationsTable() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
	}

	/**
	 * FROM + WHERE of an admin view in `$lang`, with its values.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private static function where( string $view, string $lang, string $search ): array {
		global $wpdb;

		$sql  = array( '1=1' );
		$args = array( $lang );
		if ( 'new' === $view ) {
			$sql[] = 't.string_id IS NULL';
		} elseif ( in_array( $view, self::STATUSES, true ) ) {
			$sql[]  = 't.status = %s';
			$args[] = $view;
		} elseif ( 'stale' === $view ) {
			$scan  = Scanner::lastScan();
			$sql[] = '' === $scan ? '1=0' : '(s.last_seen IS NULL OR s.last_seen < %s)';
			if ( '' !== $scan ) {
				$args[] = $scan;
			}
		}
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$sql[]  = '(s.source LIKE %s OR t.translation LIKE %s OR s.path LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		return array(
			self::table() . ' s LEFT JOIN ' . self::translationsTable() . ' t ON t.string_id = s.id AND t.lang = %s WHERE ' . implode( ' AND ', $sql ),
			$args,
		);
	}

	/** @return list<array{id: string, source: string, path: string, translation: string, status: string}> */
	public static function page( string $view, string $lang, string $search, int $perPage, int $page ): array {
		global $wpdb;

		[ $from, $args ] = self::where( $view, $lang, $search );
		$args[]          = $perPage;
		$args[]          = max( 0, ( $page - 1 ) * $perPage );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.source, s.path, COALESCE(t.translation, '') AS translation, COALESCE(t.status, 'new') AS status FROM $from ORDER BY s.path ASC, s.id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from placeholders.
	}

	public static function count( string $view, string $lang, string $search = '' ): int {
		global $wpdb;

		[ $from, $args ] = self::where( $view, $lang, $search );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $from", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from placeholders.
	}

	/**
	 * Translated pairs of `$lang` for export.
	 *
	 * @return list<array{source: string, translation: string}>
	 */
	public static function pairs( string $lang, string $status = '' ): array {
		global $wpdb;

		$sql  = 'SELECT s.source, t.translation FROM ' . self::table() . ' s INNER JOIN ' . self::translationsTable() . ' t ON t.string_id = s.id WHERE t.lang = %s';
		$args = array( $lang );
		if ( in_array( $status, array( 'auto', 'edited' ), true ) ) {
			$sql   .= ' AND t.status = %s';
			$args[] = $status;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY s.id', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names + placeholders.

		return array_map( static fn( array $r ): array => array( 'source' => (string) $r['source'], 'translation' => (string) $r['translation'] ), (array) $rows );
	}

	/**
	 * Strings not translated into `$lang` yet, for export.
	 *
	 * @return list<array{source: string, path: string, translation: string}>
	 */
	public static function untranslated( string $lang ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.source, s.path FROM ' . self::table() . ' s LEFT JOIN ' . self::translationsTable() . ' t ON t.string_id = s.id AND t.lang = %s WHERE t.string_id IS NULL ORDER BY s.path, s.id', $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.

		return array_map( static fn( array $r ): array => array( 'source' => (string) $r['source'], 'path' => (string) $r['path'], 'translation' => '' ), (array) $rows );
	}

	/**
	 * Imports pairs into `$lang`: new sources are added, untranslated strings are filled; existing translations
	 * change only with `$overwrite`. Pairs whose tags do not match their source are skipped.
	 *
	 * @param list<array{source?: mixed, translation?: mixed}> $pairs
	 * @return array{added: int, updated: int, skipped: int}
	 */
	public static function import( array $pairs, string $lang, string $status = 'auto', bool $overwrite = false ): array {
		global $wpdb;

		$stats = array( 'added' => 0, 'updated' => 0, 'skipped' => 0 );
		$now   = current_time( 'mysql', true );
		foreach ( $pairs as $pair ) {
			$source      = Format::normalize( (string) ( $pair['source'] ?? '' ) );
			$translation = Format::normalize( (string) ( $pair['translation'] ?? '' ) );
			if ( '' === $source || '' === $translation || ! Format::valid( $source, $translation ) ) {
				++$stats['skipped'];
				continue;
			}
			$hash = Format::hash( $source );
			$id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( ! $id ) {
				$wpdb->insert( self::table(), array( 'hash' => $hash, 'source' => $source, 'created_at' => $now ) );
				$id = (int) $wpdb->insert_id;
			}
			$current = self::find( $id, $lang );
			if ( null === $current || 'new' === $current['status'] ) {
				self::saveTranslation( $id, $lang, $translation, $status );
				++$stats['added'];
			} elseif ( $overwrite && $current['translation'] !== $translation ) {
				self::saveTranslation( $id, $lang, $translation, $status );
				++$stats['updated'];
			} else {
				++$stats['skipped'];
			}
		}

		return $stats;
	}
}
