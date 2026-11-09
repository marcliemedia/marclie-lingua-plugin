<?php
/**
 * Admin list of the site's texts in one translated language: source text (read-only), translation textarea,
 * status. Views by status, search (source, translation, page), pagination, bulk delete. Saving is done by Page
 * (one form).
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Lang;
use Marclie\Lingua\Router;
use Marclie\Lingua\Scanner;
use Marclie\Lingua\Store;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class StringsTable extends \WP_List_Table {

	public function __construct( private Lang $source, private Lang $lang ) {
		parent::__construct(
			array(
				'singular' => 'mlg-string',
				'plural'   => 'mlg-strings',
				'ajax'     => false,
			)
		);
	}

	public static function view(): string {
		$view = isset( $_REQUEST['mlg_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['mlg_view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.

		return in_array( $view, array_merge( Store::STATUSES, array( 'stale' ) ), true ) ? $view : 'all';
	}

	public static function search(): string {
		return isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
	}

	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'source'      => esc_html( $this->source->name ),
			'translation' => esc_html( $this->lang->name ),
			'status'      => esc_html__( 'Status', 'marclie-lingua' ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'original' => esc_html__( 'Keep original text', 'marclie-lingua' ),
			'delete'   => esc_html__( 'Delete', 'marclie-lingua' ),
		);
	}

	protected function get_views() {
		$current = self::view();
		$labels  = array(
			'all'    => __( 'All', 'marclie-lingua' ),
			'new'    => __( 'Untranslated', 'marclie-lingua' ),
			'auto'   => __( 'Automatic', 'marclie-lingua' ),
			'edited' => __( 'Edited', 'marclie-lingua' ),
		);
		if ( '' !== Scanner::lastScan() ) {
			$labels['stale'] = __( 'Not found by the last scan', 'marclie-lingua' );
		}
		$views = array();
		foreach ( $labels as $view => $label ) {
			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( array( 'page' => Page::SLUG, 'mlg_lang' => $this->lang->code, 'mlg_view' => $view ), admin_url( 'admin.php' ) ) ),
				$view === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( Store::count( $view, $this->lang->code ) ) )
			);
		}

		return $views;
	}

	public function prepare_items() {
		$perPage = $this->get_items_per_page( 'mlg_per_page', 50 );
		$view    = self::view();
		$search  = self::search();
		$total   = Store::count( $view, $this->lang->code, $search );

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = Store::page( $view, $this->lang->code, $search, $perPage, $this->get_pagenum() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $perPage,
			)
		);
	}

	public function no_items() {
		if ( '' !== self::search() ) {
			/* translators: %s: search terms. */
			echo esc_html( sprintf( __( 'No texts match “%s”.', 'marclie-lingua' ), self::search() ) );
			return;
		}
		$view = self::view();
		if ( 'new' === $view ) {
			esc_html_e( 'Everything is translated.', 'marclie-lingua' );
		} elseif ( 'stale' === $view ) {
			esc_html_e( 'The last scan found every text.', 'marclie-lingua' );
		} elseif ( 'all' === $view && '' === Scanner::lastScan() ) {
			esc_html_e( 'No texts yet. Scan the site to collect them.', 'marclie-lingua' );
		} else {
			esc_html_e( 'No texts here.', 'marclie-lingua' );
		}
	}

	/** @param array<string, mixed> $item */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="mlg_ids[]" value="%d" />', (int) $item['id'] );
	}

	/** @param array<string, mixed> $item */
	protected function column_source( $item ) {
		$path = (string) $item['path'];
		$page = '' !== $path && str_starts_with( $path, '/' )
			? sprintf(
				'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( Router::url( home_url( $path ), $this->lang->code ) ),
				esc_html( $path )
			)
			: esc_html( '' !== $path ? $path : '—' );

		return sprintf(
			'<div class="mlg-source" lang="%4$s">%1$s</div><div class="mlg-meta">%2$s %3$s</div>',
			esc_html( (string) $item['source'] ),
			esc_html__( 'Page:', 'marclie-lingua' ),
			$page,
			esc_attr( $this->source->bcp47 )
		);
	}

	/** @param array<string, mixed> $item */
	protected function column_translation( $item ) {
		$id          = (int) $item['id'];
		$translation = (string) $item['translation'];
		$length      = max( mb_strlen( (string) $item['source'] ), mb_strlen( $translation ) );

		return sprintf(
			'<label class="screen-reader-text" for="mlg-t-%1$d">%2$s</label><textarea class="large-text mlg-translation" id="mlg-t-%1$d" name="mlg_t[%1$d]" rows="%3$d" lang="%6$s">%4$s</textarea><input type="hidden" name="mlg_was[%1$d]" value="%5$s" />',
			$id,
			/* translators: %s: language name. */
			esc_html( sprintf( __( 'Translation (%s)', 'marclie-lingua' ), $this->lang->name ) ),
			max( 1, min( 12, (int) ceil( $length / 70 ) ) ),
			esc_textarea( $translation ),
			esc_attr( md5( $translation ) ),
			esc_attr( $this->lang->bcp47 )
		);
	}

	/** @param array<string, mixed> $item */
	protected function column_status( $item ) {
		$labels = array(
			'new'    => __( 'Untranslated', 'marclie-lingua' ),
			'auto'   => __( 'Automatic', 'marclie-lingua' ),
			'edited' => __( 'Edited', 'marclie-lingua' ),
		);
		$status = (string) $item['status'];

		return sprintf( '<span class="mlg-pill mlg-pill--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}
}
