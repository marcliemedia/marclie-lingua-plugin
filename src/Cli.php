<?php
/**
 * WP-CLI: `wp marclie-lingua <scan|export|import|seed>`. `--lang=<code>` picks the translated language; it may
 * be left out when the site has only one.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Requests every public page in every translated language: stores new texts, marks every text as seen and
	 * records which pages still show untranslated texts.
	 *
	 * ## EXAMPLES
	 *     wp marclie-lingua scan
	 */
	public function scan(): void {
		if ( ! Settings::targets() ) {
			\WP_CLI::error( 'No translated language is set up (Translations › Settings).' );
		}
		$urls = Scanner::jobs();
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
		$untranslated = array();
		foreach ( Settings::targetCodes() as $code ) {
			$untranslated[] = sprintf( '%s %d (%d pages hidden from search engines)', $code, Store::count( 'new', $code ), count( Coverage::pages( $code ) ) );
		}
		\WP_CLI::success( sprintf( '%d pages scanned. Texts: %d; untranslated: %s.', count( $urls ), Store::count( 'all', Settings::source()->code ), implode( ', ', $untranslated ) ) );
	}

	/**
	 * Writes the translations of one language as JSON (list of {source, translation}).
	 *
	 * ## OPTIONS
	 * [<file>]
	 * : Output file; default STDOUT.
	 *
	 * [--lang=<code>]
	 * : Translated language (required when the site has several).
	 *
	 * [--status=<status>]
	 * : Only translations with this status (auto, edited).
	 *
	 * [--untranslated]
	 * : Texts not translated into the language yet: {source, path, translation: ""}.
	 *
	 * [--seed]
	 * : Write every translation of the language to the plugin's data/{code}.json.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		$lang = self::lang( $assoc );
		$data = isset( $assoc['untranslated'] )
			? Store::untranslated( $lang )
			: Store::pairs( $lang, isset( $assoc['seed'] ) ? '' : (string) ( $assoc['status'] ?? '' ) );
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$file = isset( $assoc['seed'] ) ? Install::seedFile( $lang ) : ( $args[0] ?? '' );
		if ( '' === $file ) {
			\WP_CLI::line( $json );
			return;
		}
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export.
		\WP_CLI::success( sprintf( '%d texts written to %s.', count( $data ), $file ) );
	}

	/**
	 * Imports a JSON list of {source, translation} into one language: adds new texts, fills untranslated ones.
	 *
	 * ## OPTIONS
	 * <file>
	 * : JSON file.
	 *
	 * [--lang=<code>]
	 * : Translated language (required when the site has several).
	 *
	 * [--status=<status>]
	 * : Status of the imported translations (auto or edited). Default: auto.
	 *
	 * [--overwrite]
	 * : Also replace translations that already exist.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$lang  = self::lang( $assoc );
		$pairs = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $pairs ) ) {
			\WP_CLI::error( 'Not a JSON list.' );
		}
		$status = in_array( $assoc['status'] ?? '', array( 'auto', 'edited' ), true ) ? $assoc['status'] : 'auto';
		$stats  = Store::import( $pairs, $lang, $status, isset( $assoc['overwrite'] ) );
		\WP_CLI::success( sprintf( 'Added %d, updated %d, skipped %d.', $stats['added'], $stats['updated'], $stats['skipped'] ) );
	}

	/**
	 * Imports the shipped translations (data/{code}.json) without replacing existing ones.
	 *
	 * ## OPTIONS
	 * [--lang=<code>]
	 * : Translated language (required when the site has several).
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function seed( array $args, array $assoc ): void {
		$stats = Install::importSeed( self::lang( $assoc ) );
		\WP_CLI::success( sprintf( 'Added %d, updated %d, skipped %d.', $stats['added'], $stats['updated'], $stats['skipped'] ) );
	}

	/** @param array<string, string> $assoc */
	private static function lang( array $assoc ): string {
		$codes = Settings::targetCodes();
		$lang  = (string) ( $assoc['lang'] ?? ( 1 === count( $codes ) ? $codes[0] : '' ) );
		if ( ! in_array( $lang, $codes, true ) ) {
			\WP_CLI::error( sprintf( 'Choose a translated language with --lang=<code> (%s).', $codes ? implode( ', ', $codes ) : 'none set up' ) );
		}

		return $lang;
	}
}
