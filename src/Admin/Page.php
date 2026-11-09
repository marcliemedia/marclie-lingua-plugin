<?php
/**
 * Admin › Translations: edit the translations of the site's texts, one translated language at a time (tabs with the
 * share of translated texts), and scan the site for new texts. One POST form saves every changed textarea of the
 * page (tags must match the source), bulk-deletes, or keeps the original text as the translation (names, codes).
 * The pages kept out of search engines because they still show untranslated texts are listed above the table.
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Coverage;
use Marclie\Lingua\Format;
use Marclie\Lingua\Lang;
use Marclie\Lingua\Router;
use Marclie\Lingua\Scanner;
use Marclie\Lingua\Settings;
use Marclie\Lingua\Store;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'marclie-lingua';

	private const NONCE = 'mlg_save';

	private static string $hook = '';

	public static function capability(): string {
		return (string) apply_filters( 'marclie_lingua/capability', 'manage_options' );
	}

	/** Translated language being edited (`mlg_lang`, default the first one); null when none is set up. */
	public static function lang(): ?Lang {
		$targets = Settings::targets();
		$code    = isset( $_REQUEST['mlg_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['mlg_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list state.
		foreach ( $targets as $lang ) {
			if ( $lang->code === $code ) {
				return $lang;
			}
		}

		return $targets[0] ?? null;
	}

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_filter( 'set_screen_option_mlg_per_page', static fn( $status, $option, $value ) => max( 10, min( 500, (int) $value ) ), 10, 3 );
		add_action( 'wp_ajax_mlg_scan_start', array( self::class, 'ajaxScanStart' ) );
		add_action( 'wp_ajax_mlg_scan_url', array( self::class, 'ajaxScanUrl' ) );
		add_action( 'wp_ajax_mlg_scan_finish', array( self::class, 'ajaxScanFinish' ) );
	}

	public static function menu(): void {
		self::$hook = (string) add_menu_page(
			__( 'Translations', 'marclie-lingua' ),
			__( 'Translations', 'marclie-lingua' ),
			self::capability(),
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-translation',
			21
		);
		add_action( 'load-' . self::$hook, array( self::class, 'load' ) );
	}

	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Strings per page', 'marclie-lingua' ),
				'default' => 50,
				'option'  => 'mlg_per_page',
			)
		);
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['mlg_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
			self::save();
		}
	}

	public static function assets(): void {
		wp_enqueue_style( 'mlg-admin', MLG_URL . 'assets/admin.css', array(), (string) filemtime( MLG_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'mlg-admin', MLG_URL . 'assets/admin.js', array(), (string) filemtime( MLG_DIR . 'assets/admin.js' ), array( 'in_footer' => true ) );
		wp_localize_script(
			'mlg-admin',
			'mlgAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mlg_scan' ),
				'i18n'    => array(
					'starting' => __( 'Collecting the pages…', 'marclie-lingua' ),
					/* translators: 1: pages done, 2: all pages. */
					'progress' => __( 'Scanning page %1$s of %2$s…', 'marclie-lingua' ),
					/* translators: %s: number of pages. */
					'failed'   => __( '%s pages could not be loaded; the scan was not recorded. Try again.', 'marclie-lingua' ),
					'error'    => __( 'The scan stopped because of an error.', 'marclie-lingua' ),
					'unsaved'  => __( 'You have unsaved translations.', 'marclie-lingua' ),
				),
			)
		);
	}

	/** URL of the current view (after a save or delete). */
	private static function viewUrl( array $extra = array() ): string {
		$args = array(
			'page'     => self::SLUG,
			'mlg_lang' => self::lang()?->code ?? '',
			'mlg_view' => StringsTable::view(),
			's'        => StringsTable::search(),
			'paged'    => isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list state.
		);

		return add_query_arg( array_filter( array_merge( $args, $extra ), static fn( $v ) => '' !== $v && 'all' !== $v ), admin_url( 'admin.php' ) );
	}

	private static function save(): void {
		check_admin_referer( self::NONCE, 'mlg_nonce' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit translations.', 'marclie-lingua' ), 403 );
		}
		$lang = self::lang();
		if ( null === $lang ) {
			wp_safe_redirect( self::viewUrl() );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_POST['action'] ?? '-1' ) );
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( wp_unslash( $_POST['action2'] ?? '-1' ) );
		}
		if ( 'delete' === $action ) {
			$ids     = array_map( 'intval', (array) wp_unslash( $_POST['mlg_ids'] ?? array() ) );
			$deleted = Store::delete( $ids );
			wp_safe_redirect( self::viewUrl( array( 'mlg_deleted' => $deleted ) ) );
			exit;
		}
		if ( 'original' === $action ) {
			// names, codes, brands: the translation is the text itself, so the page counts as translated
			$kept = 0;
			foreach ( array_map( 'intval', (array) wp_unslash( $_POST['mlg_ids'] ?? array() ) ) as $id ) {
				$row = Store::find( $id, $lang->code );
				if ( null !== $row ) {
					Store::saveTranslation( $id, $lang->code, $row['source'] );
					++$kept;
				}
			}
			wp_safe_redirect( self::viewUrl( array( 'mlg_saved' => $kept ) ) );
			exit;
		}

		$values  = (array) wp_unslash( $_POST['mlg_t'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized below; inline tags are rebuilt from the source on output, never printed as HTML.
		$was     = (array) wp_unslash( $_POST['mlg_was'] ?? array() );
		$saved   = 0;
		$invalid = array();
		foreach ( $values as $id => $value ) {
			$id    = (int) $id;
			$value = Format::normalize( (string) $value );
			if ( md5( $value ) === (string) ( $was[ $id ] ?? '' ) ) {
				continue;
			}
			$row = Store::find( $id, $lang->code );
			if ( null === $row ) {
				continue;
			}
			if ( '' !== $value && ! Format::valid( (string) $row['source'], $value ) ) {
				$invalid[] = array( (string) $row['source'], $value );
				continue;
			}
			Store::saveTranslation( $id, $lang->code, $value );
			++$saved;
		}
		if ( $invalid ) {
			set_transient( 'mlg_invalid_' . get_current_user_id(), $invalid, 10 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::viewUrl( array( 'mlg_saved' => $saved, 'mlg_invalid' => count( $invalid ) ) ) );
		exit;
	}

	public static function render(): void {
		$lang   = self::lang();
		$source = Settings::source();
		?>
		<div class="wrap mlg">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Translations', 'marclie-lingua' ); ?></h1>
			<hr class="wp-header-end">
			<?php
			if ( null === $lang ) {
				printf(
					'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div></div>',
					esc_html__( 'No translated language is set up yet.', 'marclie-lingua' ),
					esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
					esc_html__( 'Add a language in the settings.', 'marclie-lingua' )
				);
				return;
			}
			$table = new StringsTable( $source, $lang );
			$table->prepare_items();
			$last = Scanner::lastScan();
			self::notices( $source, $lang );
			self::tabs( $lang );
			self::hiddenPages( $lang );
			?>
			<p class="mlg-intro">
				<?php
				printf(
					/* translators: 1: source language name, 2: translated language name. */
					esc_html__( 'Each row is one text of the site in %1$s with its %2$s translation; an empty translation shows the %1$s text. Keep the tags such as <strong>…</strong> or <a>…</a> of the text in the translation: they carry the bold text, links and icons.', 'marclie-lingua' ),
					esc_html( $source->name ),
					esc_html( $lang->name )
				);
				?>
			</p>
			<div class="mlg-scan">
				<button type="button" class="button" data-mlg-scan><?php esc_html_e( 'Scan the site', 'marclie-lingua' ); ?></button>
				<span class="mlg-scan__status" data-mlg-scan-status role="status">
					<?php
					echo esc_html(
						'' !== $last
							/* translators: %s: date and time. */
							? sprintf( __( 'Last scan: %s. Run a scan after adding or changing content to list the new texts.', 'marclie-lingua' ), get_date_from_gmt( $last, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
							: __( 'Run a scan after adding or changing content to list the new texts.', 'marclie-lingua' )
					);
					?>
				</span>
			</div>
			<?php $table->views(); ?>
			<form method="get" class="mlg-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="mlg_lang" value="<?php echo esc_attr( $lang->code ); ?>">
				<input type="hidden" name="mlg_view" value="<?php echo esc_attr( StringsTable::view() ); ?>">
				<?php $table->search_box( __( 'Search texts', 'marclie-lingua' ), 'mlg-search' ); ?>
			</form>
			<form method="post" id="mlg-form" action="<?php echo esc_url( self::viewUrl() ); ?>">
				<?php wp_nonce_field( self::NONCE, 'mlg_nonce' ); ?>
				<?php $table->display(); ?>
				<p class="submit"><button type="submit" class="button button-primary" name="mlg_save" value="1"><?php esc_html_e( 'Save changes', 'marclie-lingua' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** One tab per translated language with its share of translated texts (none when there is only one). */
	private static function tabs( Lang $current ): void {
		$targets = Settings::targets();
		if ( ! $targets ) {
			return;
		}
		$all = Store::count( 'all', $current->code );
		echo '<nav class="nav-tab-wrapper mlg-tabs" aria-label="' . esc_attr__( 'Translated languages', 'marclie-lingua' ) . '">';
		foreach ( $targets as $lang ) {
			$done = $all > 0 ? (int) floor( 100 * ( $all - Store::count( 'new', $lang->code ) ) / $all ) : 100;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s <span class="mlg-tabs__share">%5$s</span></a>',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'mlg_lang' => $lang->code ), admin_url( 'admin.php' ) ) ),
				$lang->code === $current->code ? ' nav-tab-active' : '',
				$lang->code === $current->code ? ' aria-current="page"' : '',
				esc_html( $lang->name ),
				/* translators: %s: percentage of translated texts. */
				esc_html( sprintf( __( '%s%% translated', 'marclie-lingua' ), number_format_i18n( $done ) ) )
			);
		}
		echo '</nav>';
	}

	/** Pages of the language kept out of search engines because they still show untranslated texts (Coverage). */
	private static function hiddenPages( Lang $lang ): void {
		$pages = Coverage::pages( $lang->code );
		if ( ! $pages || ! Settings::index() ) {
			return;
		}
		echo '<details class="notice notice-warning mlg-hidden"><summary>';
		printf(
			/* translators: 1: number of pages, 2: language name. */
			esc_html( _n( '%1$s %2$s page is hidden from search engines until its texts are translated.', '%1$s %2$s pages are hidden from search engines until their texts are translated.', count( $pages ), 'marclie-lingua' ) ),
			esc_html( number_format_i18n( count( $pages ) ) ),
			esc_html( $lang->name )
		);
		echo '</summary><ul>';
		foreach ( array_slice( $pages, 0, 100, true ) as $path => $missing ) {
			printf(
				'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a> — %3$s</li>',
				esc_url( Router::url( home_url( $path ), $lang->code ) ),
				esc_html( $path ),
				/* translators: %s: number of texts. */
				esc_html( sprintf( _n( '%s untranslated text', '%s untranslated texts', $missing, 'marclie-lingua' ), number_format_i18n( $missing ) ) )
			);
		}
		echo '</ul><p>' . esc_html__( 'Translate them below (view "Untranslated"; for names and codes use the bulk action "Keep original text"), then open the page or scan the site to update this list.', 'marclie-lingua' ) . '</p></details>';
	}

	private static function notices( Lang $source, Lang $lang ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of redirect results.
		if ( isset( $_GET['mlg_saved'] ) ) {
			$n = (int) $_GET['mlg_saved'];
			/* translators: %s: number of translations. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%s translation saved.', '%s translations saved.', $n, 'marclie-lingua' ), number_format_i18n( $n ) ) ) );
		}
		if ( isset( $_GET['mlg_deleted'] ) ) {
			$n = (int) $_GET['mlg_deleted'];
			/* translators: %s: number of strings. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%s text deleted.', '%s texts deleted.', $n, 'marclie-lingua' ), number_format_i18n( $n ) ) ) );
		}
		if ( ! empty( $_GET['mlg_invalid'] ) ) {
			$key     = 'mlg_invalid_' . get_current_user_id();
			$invalid = (array) get_transient( $key );
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not saved: the translation must use the same tags as the original text, each closed.', 'marclie-lingua' ) . '</p><ul class="mlg-invalid">';
			foreach ( $invalid as $pair ) {
				printf( '<li><span lang="%3$s">%1$s</span><br><span lang="%4$s">%2$s</span></li>', esc_html( (string) ( $pair[0] ?? '' ) ), esc_html( (string) ( $pair[1] ?? '' ) ), esc_attr( $source->bcp47 ), esc_attr( $lang->bcp47 ) );
			}
			echo '</ul></div>';
		}
		// phpcs:enable
	}

	private static function ajaxGuard(): void {
		check_ajax_referer( 'mlg_scan', 'nonce' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	public static function ajaxScanStart(): void {
		self::ajaxGuard();
		Scanner::start();
		wp_send_json_success( array( 'urls' => Scanner::jobs() ) );
	}

	public static function ajaxScanUrl(): void {
		self::ajaxGuard();
		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! str_starts_with( $url, home_url( '/' ) ) ) {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success( Scanner::fetch( $url ) );
	}

	public static function ajaxScanFinish(): void {
		self::ajaxGuard();
		if ( empty( $_POST['failed'] ) ) {
			Scanner::finish();
		} else {
			delete_option( Scanner::RUNNING_OPTION );
		}
		wp_send_json_success();
	}
}
