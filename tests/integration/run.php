<?php
/**
 * Integration checks against a development site's database.
 *
 *     wp eval-file wp-content/plugins/marclie-lingua/tests/integration/run.php
 *
 * Runs with the languages hr (source) + en, de forced in-process (the stored settings are not changed). Strings it
 * creates carry the marker "mlg-integration" and are deleted in `finally`. Exits 1 on any failure.
 */

use Marclie\Lingua\Format;
use Marclie\Lingua\Language;
use Marclie\Lingua\Router;
use Marclie\Lingua\Settings;
use Marclie\Lingua\Store;

defined( 'ABSPATH' ) || exit( 1 );

$mlgFail  = 0;
$mlgCheck = static function ( string $label, bool $ok, string $detail = '' ) use ( &$mlgFail ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $label, ( ! $ok && '' !== $detail ? ' — ' . $detail : '' ), "\n";
	$mlgFail += $ok ? 0 : 1;
};

add_filter( 'option_mlg_settings', static fn( $v ) => array_merge( (array) $v, array( 'source' => 'hr', 'targets' => array( 'en', 'de' ) ) ) );
Settings::flush();

// --- Router::url ---------------------------------------------------------------------------------------------
$home  = untrailingslashit( home_url() );
$host  = (string) wp_parse_url( $home, PHP_URL_HOST ) . ( wp_parse_url( $home, PHP_URL_PORT ) ? ':' . wp_parse_url( $home, PHP_URL_PORT ) : '' );
$cases = array(
	array( $home . '/kontakt/', 'en', $home . '/en/kontakt/' ),
	array( '/kontakt/?a=1&b=2#x', 'de', '/de/kontakt/?a=1&b=2#x' ),
	array( '/en/kontakt/', 'de', '/de/kontakt/' ),
	array( '/en/kontakt/', 'hr', '/kontakt/' ),
	array( '/hr/kontakt/', 'en', '/en/kontakt/' ),
	array( '/', 'en', '/en/' ),
	array( $home, 'en', $home . '/en/' ),
	array( '//' . $host . '/x/', 'en', '//' . $host . '/en/x/' ),
	array( '/page.html', 'en', '/en/page.html' ),
	array( '/kontakt/', 'fr', '/kontakt/' ),
	array( 'https://example.com/kontakt/', 'en', 'https://example.com/kontakt/' ),
	array( '/wp-content/uploads/cjenik.pdf', 'en', '/wp-content/uploads/cjenik.pdf' ),
	array( '/wp-admin/', 'en', '/wp-admin/' ),
	array( '/wp-json/wp/v2/pages', 'en', '/wp-json/wp/v2/pages' ),
	array( '/wp-login.php?action=lostpassword', 'en', '/wp-login.php?action=lostpassword' ),
	array( '/sitemap_index.xml', 'en', '/sitemap_index.xml' ),
	array( '/feed/', 'en', '/feed/' ),
	array( 'mailto:info@example.test', 'en', 'mailto:info@example.test' ),
	array( 'tel:+385', 'en', 'tel:+385' ),
	array( '#top', 'en', '#top' ),
	array( '?q=1', 'en', '?q=1' ),
	array( 'relative/path/', 'en', 'relative/path/' ),
	array( '', 'en', '' ),
);
foreach ( $cases as [ $in, $lang, $want ] ) {
	$got = Router::url( $in, $lang );
	$mlgCheck( "url( $in, $lang )", $want === $got, "got $got" );
}
$once = Router::url( '/a/b/?c=1', 'en' );
$mlgCheck( 'url() is idempotent', Router::url( $once, 'en' ) === $once );

// --- Language::with ------------------------------------------------------------------------------------------
$mlgCheck( 'with() switches and restores', 'de' === Language::with( 'de', static fn() => Language::current() ) && 'hr' === Language::current() );
$mlgCheck( 'with() unknown → source', 'hr' === Language::with( 'xx', static fn() => Language::current() ) );

// --- Store per language --------------------------------------------------------------------------------------
$sources = array();
foreach ( array( 'Prvi tekst mlg-integration', 'Drugi <strong>tekst</strong> mlg-integration' ) as $s ) {
	$sources[ Format::hash( $s ) ] = $s;
}
$ids = array();
try {
	Store::register( $sources, '/mlg-integration/' );
	Store::register( $sources, '/mlg-integration/' ); // duplicate: ignored
	global $wpdb;
	$in  = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Store::table() . " WHERE hash IN ($in) ORDER BY id", array_keys( $sources ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$mlgCheck( 'register stores each source once', 2 === count( $ids ) );

	$found = Store::lookup( array_keys( $sources ), 'en' );
	$mlgCheck( 'lookup: stored, untranslated = ""', array( '', '' ) === array_values( $found ) );

	$stats = Store::import(
		array(
			array( 'source' => 'Prvi tekst mlg-integration', 'translation' => 'First text' ),
			array( 'source' => 'Drugi <strong>tekst</strong> mlg-integration', 'translation' => 'Second text' ), // tags differ
		),
		'en'
	);
	$mlgCheck( 'import adds valid, skips mismatched tags', 1 === $stats['added'] && 1 === $stats['skipped'] );
	$mlgCheck( 'languages are independent', 'First text' === Store::lookup( array_keys( $sources ), 'en' )[ Format::hash( 'Prvi tekst mlg-integration' ) ] && '' === Store::lookup( array_keys( $sources ), 'de' )[ Format::hash( 'Prvi tekst mlg-integration' ) ] );

	$again = Store::import( array( array( 'source' => 'Prvi tekst mlg-integration', 'translation' => 'Other' ) ), 'en' );
	$mlgCheck( 'import keeps existing without overwrite', 1 === $again['skipped'] && 'First text' === Store::find( $ids[0], 'en' )['translation'] );
	$over = Store::import( array( array( 'source' => 'Prvi tekst mlg-integration', 'translation' => 'Other' ) ), 'en', 'auto', true );
	$mlgCheck( 'import overwrites with flag', 1 === $over['updated'] && 'Other' === Store::find( $ids[0], 'en' )['translation'] );

	Store::saveTranslation( $ids[1], 'de', 'Zweiter <strong>Text</strong>' );
	$row = Store::find( $ids[1], 'de' );
	$mlgCheck( 'save → edited', 'edited' === $row['status'] && 'new' === Store::find( $ids[1], 'en' )['status'] );
	Store::saveTranslation( $ids[1], 'de', '' );
	$mlgCheck( 'empty save → untranslated', 'new' === Store::find( $ids[1], 'de' )['status'] );

	$mlgCheck( 'view counts per language', Store::count( 'new', 'de', 'mlg-integration' ) === 2 && Store::count( 'auto', 'en', 'mlg-integration' ) === 1 );
} finally {
	Store::delete( $ids );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::translationsTable() . ' WHERE string_id IN (' . implode( ',', array_fill( 0, max( 1, count( $ids ) ), '%d' ) ) . ')', $ids ?: array( 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$mlgCheck( 'delete removes translations too', 0 === $left );
}

echo $mlgFail ? "\n$mlgFail failed\n" : "\nall passed\n";
exit( $mlgFail ? 1 : 0 );
