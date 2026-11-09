<?php
/**
 * Site scan: requests every public page in English (loopback, anonymous), so new strings get stored and every
 * string on the site is marked as seen. Strings not seen by the last finished scan are "unused" in admin.
 * Scan requests carry the header X-Marclie-Lingua-Scan with a stored random token.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	public const TOKEN_OPTION = 'mlg_scan_token';

	/** Start time (GMT) of the last finished scan. */
	public const LAST_OPTION = 'mlg_last_scan';

	/** Start time of the scan in progress. */
	public const RUNNING_OPTION = 'mlg_scan_running';

	public const HEADER = 'X-Marclie-Lingua-Scan';

	/** @return list<string> Public URLs to scan (filter `marclie_lingua/scan_urls`). */
	public static function urls(): array {
		$urls = array( home_url( '/' ) );
		$ids  = get_posts(
			array(
				'post_type'        => get_post_types( array( 'public' => true ) ),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		foreach ( $ids as $id ) {
			// templates are previewed inside the pages that use them; their own URLs are builder previews
			if ( ! in_array( get_post_type( $id ), array( 'attachment', 'bricks_template' ), true ) ) {
				$urls[] = (string) get_permalink( $id );
			}
		}
		foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ) ) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( in_array( $tax, array( 'post_tag', 'post_format' ), true ) || ! is_taxonomy_viewable( $tax ) ) {
				continue;
			}
			foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) ) as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		$urls[] = home_url( '/mlg-page-not-found/' ); // the 404 template

		return array_values( array_unique( array_filter( (array) apply_filters( 'marclie_lingua/scan_urls', $urls ) ) ) );
	}

	public static function start(): void {
		update_option( self::RUNNING_OPTION, current_time( 'mysql', true ), false );
	}

	public static function finish(): void {
		$started = (string) get_option( self::RUNNING_OPTION, '' );
		if ( '' !== $started ) {
			update_option( self::LAST_OPTION, $started, false );
			delete_option( self::RUNNING_OPTION );
		}
	}

	public static function lastScan(): string {
		return (string) get_option( self::LAST_OPTION, '' );
	}

	/**
	 * One page in English. Redirects are not followed: a redirecting URL has no content of its own and its
	 * target is a scanned page too (a followed loopback redirect stalled inside admin-ajax on LocalWP).
	 *
	 * @return array{url: string, ok: bool, status: int|string}
	 */
	public static function fetch( string $url ): array {
		$response = wp_remote_get(
			add_query_arg( 'lang', Language::TARGET, $url ),
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( self::HEADER => self::token() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'url' => $url, 'ok' => false, 'status' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );

		return array( 'url' => $url, 'ok' => $status < 500, 'status' => $status );
	}

	public static function token(): string {
		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	public static function isScanRequest(): bool {
		$sent = isset( $_SERVER['HTTP_X_MARCLIE_LINGUA_SCAN'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_MARCLIE_LINGUA_SCAN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$token = (string) get_option( self::TOKEN_OPTION, '' );

		return '' !== $sent && '' !== $token && hash_equals( $token, $sent );
	}
}
