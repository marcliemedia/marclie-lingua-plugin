<?php
/**
 * Table install / upgrade (dbDelta) and the shipped translations: `data/en.json` (list of
 * {source, translation}) is imported as `auto` when the table is created — it fills missing and untranslated
 * strings, never a translation saved in admin. `wp marclie-lingua export --seed` rewrites the file.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Install {

	public const DB_VERSION = 1;

	public const VERSION_OPTION = 'mlg_db_version';

	public static function seedFile(): string {
		return MLG_DIR . 'data/en.json';
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
		$fresh = 0 === (int) get_option( self::VERSION_OPTION, 0 );
		$wpdb->last_error = '';
		dbDelta( Store::schema() );
		$table = Store::table();
		if ( '' !== $wpdb->last_error || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return; // retried on the next load
		}
		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
		if ( $fresh ) {
			self::importSeed();
		}
	}

	/** @return array{added: int, updated: int, skipped: int} */
	public static function importSeed(): array {
		$file  = self::seedFile();
		$pairs = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

		return is_array( $pairs ) ? Store::import( $pairs, 'auto' ) : array( 'added' => 0, 'updated' => 0, 'skipped' => 0 );
	}
}
