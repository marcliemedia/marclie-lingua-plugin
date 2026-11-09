<?php
/**
 * Tables (dbDelta), upgrades and shipped translations.
 *
 * DB 1 → 2 (plugin 0.1 → 0.2): translations move from `mlg_strings.translation/status` to `mlg_translations`
 * (language `en`: 0.1 translated into English only); settings for 0.1 sites = source `hr`, target `en`.
 * Seed files `data/{code}.json` (list of {source, translation}) are imported as `auto` when a site is installed
 * and when a language is added; `wp marclie-lingua export --seed --lang=<code>` writes them.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Install {

	public const DB_VERSION = 2;

	public const VERSION_OPTION = 'mlg_db_version';

	public static function hooks(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybeUpgrade' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'seedNewTargets' ), 20, 2 );
	}

	public static function seedFile( string $code ): string {
		return MLG_DIR . 'data/' . sanitize_key( $code ) . '.json';
	}

	public static function activate(): void {
		self::install();
		Scanner::token();
	}

	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$from             = (int) get_option( self::VERSION_OPTION, 0 );
		$wpdb->last_error = '';
		dbDelta( Store::schema() );
		foreach ( array( Store::table(), Store::translationsTable() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return; // retried on the next load
			}
		}
		if ( 1 === $from && ! self::migrateFrom1() ) {
			return;
		}
		if ( null === get_option( Settings::OPTION, null ) ) {
			Settings::save( Settings::defaults() );
		}
		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
		if ( 0 === $from ) {
			foreach ( Settings::targetCodes() as $code ) {
				self::importSeed( $code );
			}
		}
	}

	/**
	 * 0.1 (Croatian → English, one translation column) → per-language rows. Safe to run again: copied rows are
	 * skipped, columns already dropped are not touched.
	 */
	private static function migrateFrom1(): bool {
		global $wpdb;

		$strings = Store::table();
		$columns = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM $strings", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( in_array( 'translation', $columns, true ) ) {
			$wpdb->query(
				'INSERT INTO ' . Store::translationsTable() . " (string_id, lang, translation, status, updated_at)
				SELECT s.id, 'en', s.translation, CASE WHEN s.status = 'edited' THEN 'edited' ELSE 'auto' END, COALESCE(s.updated_at, s.created_at)
				FROM $strings s
				WHERE s.translation IS NOT NULL AND s.translation <> ''
				AND NOT EXISTS (SELECT 1 FROM " . Store::translationsTable() . " t WHERE t.string_id = s.id AND t.lang = 'en')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
			);
			if ( '' !== $wpdb->last_error ) {
				return false;
			}
		}
		if ( null === get_option( Settings::OPTION, null ) ) {
			Settings::save( array( 'source' => 'hr', 'targets' => array( 'en' ), 'booking' => 'auto', 'index' => true ) );
		}
		foreach ( array( 'translation', 'status', 'updated_at' ) as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$wpdb->query( "ALTER TABLE $strings DROP COLUMN $column" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table, fixed names.
				if ( '' !== $wpdb->last_error ) {
					return false;
				}
			}
		}

		return true;
	}

	/** Imports `data/{code}.json` into `$code` without replacing existing translations. */
	public static function importSeed( string $code ): array {
		$file  = self::seedFile( $code );
		$pairs = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return is_array( $pairs ) ? Store::import( $pairs, $code, 'auto' ) : array( 'added' => 0, 'updated' => 0, 'skipped' => 0 );
	}

	/**
	 * A language added in the settings gets its shipped translations.
	 *
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function seedNewTargets( $old, $new ): void {
		$before = is_array( $old ) ? (array) ( $old['targets'] ?? array() ) : array();
		foreach ( is_array( $new ) ? (array) ( $new['targets'] ?? array() ) : array() as $code ) {
			if ( ! in_array( $code, $before, true ) ) {
				self::importSeed( (string) $code );
			}
		}
	}
}
