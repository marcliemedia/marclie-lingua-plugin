<?php
/**
 * WP-CLI: `wp marclie-lingua <scan|export|import|seed>`.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Requests every public page in English: stores new strings, marks every string as seen.
	 *
	 * ## EXAMPLES
	 *     wp marclie-lingua scan
	 */
	public function scan(): void {
		$urls = Scanner::urls();
		Scanner::start();
		$bad = 0;
		foreach ( $urls as $i => $url ) {
			$r = Scanner::fetch( $url );
			\WP_CLI::log( sprintf( '%d/%d %s %s', $i + 1, count( $urls ), $r['status'], $url ) );
			$bad += $r['ok'] ? 0 : 1;
		}
		if ( $bad ) {
			\WP_CLI::error( sprintf( '%d pages failed; the scan was not recorded.', $bad ) );
		}
		Scanner::finish();
		\WP_CLI::success( sprintf( '%d pages scanned. Strings: %d, untranslated: %d.', count( $urls ), Store::count( 'all' ), Store::count( 'new' ) ) );
	}

	/**
	 * Writes stored strings as JSON (list of {source, translation}).
	 *
	 * ## OPTIONS
	 * [<file>]
	 * : Output file; default STDOUT.
	 *
	 * [--status=<status>]
	 * : Only rows with this status (auto, edited).
	 *
	 * [--untranslated]
	 * : Untranslated strings instead: {source, path, translation: ""}.
	 *
	 * [--seed]
	 * : Write every translated pair to the plugin's data/en.json.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		global $wpdb;

		if ( isset( $assoc['untranslated'] ) ) {
			$rows = $wpdb->get_results( 'SELECT source, path FROM ' . Store::table() . " WHERE translation = '' OR translation IS NULL ORDER BY path, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			$data = array_map( static fn( array $r ): array => array( 'source' => $r['source'], 'path' => $r['path'], 'translation' => '' ), (array) $rows );
		} else {
			$data = Store::pairs( isset( $assoc['seed'] ) ? '' : (string) ( $assoc['status'] ?? '' ) );
		}
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$file = isset( $assoc['seed'] ) ? Install::seedFile() : ( $args[0] ?? '' );
		if ( '' === $file ) {
			\WP_CLI::line( $json );
			return;
		}
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export.
		\WP_CLI::success( sprintf( '%d strings written to %s.', count( $data ), $file ) );
	}

	/**
	 * Imports a JSON list of {source, translation}: adds new sources, fills untranslated ones.
	 *
	 * ## OPTIONS
	 * <file>
	 * : JSON file.
	 *
	 * [--status=<status>]
	 * : Status of the imported rows (auto or edited). Default: auto.
	 *
	 * [--overwrite]
	 * : Also replace translations that already exist.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$pairs = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $pairs ) ) {
			\WP_CLI::error( 'Not a JSON list.' );
		}
		$status = in_array( $assoc['status'] ?? '', array( 'auto', 'edited' ), true ) ? $assoc['status'] : 'auto';
		$stats  = Store::import( $pairs, $status, isset( $assoc['overwrite'] ) );
		\WP_CLI::success( sprintf( 'Added %d, updated %d, skipped %d.', $stats['added'], $stats['updated'], $stats['skipped'] ) );
	}

	/** Imports the shipped translations (data/en.json) without replacing existing ones. */
	public function seed(): void {
		$stats = Install::importSeed();
		\WP_CLI::success( sprintf( 'Added %d, updated %d, skipped %d.', $stats['added'], $stats['updated'], $stats['skipped'] ) );
	}
}
