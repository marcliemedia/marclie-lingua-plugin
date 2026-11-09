<?php
/**
 * Table `{prefix}mlg_strings`: one row per Croatian source string (unique by md5 hash) with its English
 * translation and status — `new` (not translated yet: the page shows the Croatian), `auto` (shipped /
 * imported translation, not checked by a person), `edited` (saved in admin).
 * `path` = page where the string was first found; `last_seen` = last site scan that found it.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const STATUSES = array( 'new', 'auto', 'edited' );

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mlg_strings';
	}

	/** CREATE TABLE statement for dbDelta (two spaces after PRIMARY KEY: dbDelta parses it textually). */
	public static function schema(): string {
		global $wpdb;

		return 'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL auto_increment,
  hash char(32) NOT NULL,
  source longtext NOT NULL,
  translation longtext NULL,
  status varchar(10) NOT NULL default 'new',
  path varchar(255) NOT NULL default '',
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  last_seen datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash),
  KEY status (status)
) " . $wpdb->get_charset_collate() . ';';
	}

	/**
	 * Stored rows among `$hashes`: hash => translation ('' = not translated yet).
	 *
	 * @param list<string> $hashes
	 * @return array<string, string>
	 */
	public static function lookup( array $hashes ): array {
		global $wpdb;

		$found = array();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 400 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT hash, translation FROM ' . self::table() . " WHERE hash IN ($in)", $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholders.
			foreach ( (array) $rows as $row ) {
				$found[ $row['hash'] ] = (string) $row['translation'];
			}
		}

		return $found;
	}

	/**
	 * Adds strings that are not stored yet (status `new`).
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
					'hash'        => $hash,
					'source'      => $source,
					'translation' => '',
					'status'      => 'new',
					'path'        => substr( $path, 0, 255 ),
					'created_at'  => $now,
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

	/** @return array<string, mixed>|null */
	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.

		return is_array( $row ) ? $row : null;
	}

	/** Saves a person's translation: `edited`, or back to `new` when emptied. */
	public static function saveTranslation( int $id, string $translation ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'translation' => $translation,
				'status'      => '' === $translation ? 'new' : 'edited',
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/** @param list<int> $ids */
	public static function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholders.
	}

	/**
	 * WHERE clause + values of an admin view.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private static function where( string $view, string $search ): array {
		global $wpdb;

		$sql  = array( '1=1' );
		$args = array();
		if ( in_array( $view, self::STATUSES, true ) ) {
			$sql[]  = 'status = %s';
			$args[] = $view;
		} elseif ( 'stale' === $view ) {
			$scan = Scanner::lastScan();
			$sql[]  = '' === $scan ? '1=0' : '(last_seen IS NULL OR last_seen < %s)';
			if ( '' !== $scan ) {
				$args[] = $scan;
			}
		}
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$sql[]  = '(source LIKE %s OR translation LIKE %s OR path LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		return array( implode( ' AND ', $sql ), $args );
	}

	/** @return list<array<string, mixed>> */
	public static function page( string $view, string $search, int $perPage, int $page ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $view, $search );
		$args[]           = $perPage;
		$args[]           = max( 0, ( $page - 1 ) * $perPage );

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY path ASC, id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from placeholders.
	}

	public static function count( string $view, string $search = '' ): int {
		global $wpdb;

		[ $where, $args ] = self::where( $view, $search );
		$sql              = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE $where";

		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
	}

	/**
	 * Translated pairs for export.
	 *
	 * @return list<array{source: string, translation: string}>
	 */
	public static function pairs( string $status = '' ): array {
		global $wpdb;

		$sql  = 'SELECT source, translation FROM ' . self::table() . " WHERE translation <> ''";
		$rows = in_array( $status, self::STATUSES, true )
			? $wpdb->get_results( $wpdb->prepare( $sql . ' AND status = %s ORDER BY id', $status ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			: $wpdb->get_results( $sql . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.

		return array_map( static fn( array $r ): array => array( 'source' => (string) $r['source'], 'translation' => (string) $r['translation'] ), (array) $rows );
	}

	/**
	 * Imports pairs: new sources are added, untranslated rows are filled; rows that already have a translation
	 * change only with `$overwrite`. Pairs whose tags do not match their source are skipped.
	 *
	 * @param list<array{source?: mixed, translation?: mixed}> $pairs
	 * @return array{added: int, updated: int, skipped: int}
	 */
	public static function import( array $pairs, string $status = 'auto', bool $overwrite = false ): array {
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
			$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, translation FROM ' . self::table() . ' WHERE hash = %s', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( ! $row ) {
				$wpdb->insert( self::table(), array( 'hash' => $hash, 'source' => $source, 'translation' => $translation, 'status' => $status, 'created_at' => $now, 'updated_at' => $now ) );
				++$stats['added'];
			} elseif ( '' === (string) $row['translation'] || ( $overwrite && $row['translation'] !== $translation ) ) {
				$wpdb->update( self::table(), array( 'translation' => $translation, 'status' => $status, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
				++$stats['updated'];
			} else {
				++$stats['skipped'];
			}
		}

		return $stats;
	}
}
