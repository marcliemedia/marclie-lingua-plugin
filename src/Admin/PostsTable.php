<?php
/**
 * Admin › Translations › Posts (blog mode `document`): the blog posts (Blog::types(), every status but trash) with
 * one state per translated language — not translated, draft, published, needs review (published, but the post
 * changed since) — each linking to the translation screen (PostEditor). Above the table: progress per language
 * over the published posts, with links to the posts that need work. Filters: language and state (`mlg_lang`,
 * `mlg_state`), title search, sorting by title or date, pagination (screen option). Without document mode, or
 * without a translated language, the screen explains what to set up instead.
 *
 * States come from one query per language (PostTranslations rows); "needs review" compares the stored source hash
 * with the current post, so the published posts with a translation are loaded once per request.
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Blog;
use Marclie\Lingua\Lang;
use Marclie\Lingua\PostTranslations;
use Marclie\Lingua\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PostsTable extends \WP_List_Table {

	public const SLUG = 'marclie-lingua-posts';

	public const STATES = array( 'none', 'draft', 'published', 'outdated' );

	/** @var array<string, array<int, string>> lang => post ID => state, for the posts with a translation row */
	private static array $states = array();

	/** @param list<Lang> $targets */
	public function __construct( private array $targets ) {
		parent::__construct(
			array(
				'singular' => 'mlg-post',
				'plural'   => 'mlg-posts',
				'ajax'     => false,
			)
		);
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function load(): void {
		// the filter form is a GET form: drop the list table's nonce fields from the address, like core lists do
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list state only.
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'mlg_filter' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same-site redirect.
			exit;
		}
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Posts per page', 'marclie-lingua' ),
				'default' => 20,
				'option'  => 'mlg_posts_per_page',
			)
		);
		add_action( 'admin_enqueue_scripts', array( Page::class, 'style' ) );
	}

	/** Language filter (`mlg_lang`); '' = every language. */
	public static function langFilter(): string {
		$code = isset( $_REQUEST['mlg_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['mlg_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.

		return Settings::isTarget( $code ) ? $code : '';
	}

	/** State filter (`mlg_state`); '' = any. */
	public static function stateFilter(): string {
		$state = isset( $_REQUEST['mlg_state'] ) ? sanitize_key( wp_unslash( $_REQUEST['mlg_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.

		return in_array( $state, self::STATES, true ) ? $state : '';
	}

	public static function search(): string {
		return isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
	}

	/**
	 * State of every post that has a translation in a language (the others are `none`).
	 *
	 * @return array<int, string> post ID => draft|published|outdated
	 */
	public static function states( string $lang ): array {
		global $wpdb;

		if ( isset( self::$states[ $lang ] ) ) {
			return self::$states[ $lang ];
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, status, source_hash FROM ' . PostTranslations::table() . ' WHERE lang = %s', $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$live = array();
		foreach ( $rows as $row ) {
			if ( 'published' === $row['status'] ) {
				$live[] = (int) $row['post_id'];
			}
		}
		_prime_post_caches( $live, false, false );
		$states = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['post_id'];
			if ( 'published' !== $row['status'] ) {
				$states[ $id ] = 'draft';
				continue;
			}
			$post          = get_post( $id );
			$states[ $id ] = $post instanceof \WP_Post && PostTranslations::isOutdated( $row, $post ) ? 'outdated' : 'published';
		}
		self::$states[ $lang ] = $states;

		return $states;
	}

	/**
	 * IDs of the blog posts in some statuses.
	 *
	 * @param string|list<string> $status
	 * @return list<int>
	 */
	private static function ids( $status ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => Blog::types(),
					'post_status'      => $status,
					'fields'           => 'ids',
					'numberposts'      => -1,
					'suppress_filters' => true,
				)
			)
		);
	}

	public function get_columns() {
		$columns = array( 'title' => esc_html__( 'Post', 'marclie-lingua' ) );
		foreach ( $this->targets as $lang ) {
			$columns[ 'mlg_' . $lang->code ] = esc_html( $lang->name );
		}
		$columns['date'] = esc_html__( 'Date', 'marclie-lingua' );

		return $columns;
	}

	protected function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'date', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'title';
	}

	public function prepare_items() {
		$perPage = $this->get_items_per_page( 'mlg_posts_per_page', 20 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list sorting.
		$orderby = isset( $_GET['orderby'] ) && 'title' === $_GET['orderby'] ? 'title' : 'date';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : ( 'title' === $orderby && ! isset( $_GET['order'] ) ? 'ASC' : 'DESC' );
		// phpcs:enable
		$args = array(
			'post_type'        => Blog::types(),
			'post_status'      => 'any',
			'posts_per_page'   => $perPage,
			'paged'            => $this->get_pagenum(),
			'orderby'          => $orderby,
			'order'            => $order,
			'suppress_filters' => true,
		);
		if ( '' !== self::search() ) {
			$args['s'] = self::search();
		}
		$state = self::stateFilter();
		if ( '' !== $state ) {
			$langs = '' !== self::langFilter() ? array( self::langFilter() ) : Settings::targetCodes();
			$match = array();
			if ( 'none' === $state ) {
				foreach ( self::ids( 'any' ) as $id ) {
					foreach ( $langs as $code ) {
						if ( ! isset( self::states( $code )[ $id ] ) ) {
							$match[ $id ] = true;
						}
					}
				}
			} else {
				foreach ( $langs as $code ) {
					foreach ( self::states( $code ) as $id => $s ) {
						if ( $s === $state ) {
							$match[ $id ] = true;
						}
					}
				}
			}
			$args['post__in'] = $match ? array_keys( $match ) : array( 0 );
		}
		$query = new \WP_Query( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->items           = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $perPage,
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$lang  = self::langFilter();
		$state = self::stateFilter();
		?>
		<div class="alignleft actions mlg-filters">
			<label class="screen-reader-text" for="mlg-filter-lang"><?php esc_html_e( 'Filter by language', 'marclie-lingua' ); ?></label>
			<select name="mlg_lang" id="mlg-filter-lang">
				<option value=""><?php esc_html_e( 'All languages', 'marclie-lingua' ); ?></option>
				<?php foreach ( $this->targets as $target ) : ?>
					<option value="<?php echo esc_attr( $target->code ); ?>" <?php selected( $lang, $target->code ); ?>><?php echo esc_html( $target->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="mlg-filter-state"><?php esc_html_e( 'Filter by state', 'marclie-lingua' ); ?></label>
			<select name="mlg_state" id="mlg-filter-state">
				<option value=""><?php esc_html_e( 'Any state', 'marclie-lingua' ); ?></option>
				<?php foreach ( self::STATES as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state, $value ); ?>><?php echo esc_html( PostEditor::stateLabel( $value ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'marclie-lingua' ), '', 'mlg_filter', false ); ?>
			<?php if ( '' !== $lang || '' !== $state || '' !== self::search() ) : ?>
				<a class="mlg-filters__clear" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'marclie-lingua' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	public function no_items() {
		if ( '' !== self::search() || '' !== self::stateFilter() ) {
			esc_html_e( 'No posts match these filters.', 'marclie-lingua' );
			return;
		}
		esc_html_e( 'No posts yet. Write a post, then translate it here.', 'marclie-lingua' );
	}

	/** @param \WP_Post $item */
	protected function column_title( $item ) {
		$lang   = '' !== self::langFilter() ? self::langFilter() : $this->targets[0]->code;
		$title  = '' !== $item->post_title ? $item->post_title : __( '(no title)', 'marclie-lingua' );
		$status = get_post_status_object( $item->post_status );
		$state  = 'publish' !== $item->post_status && null !== $status ? ' — <span class="post-state">' . esc_html( (string) $status->label ) . '</span>' : '';

		$actions = array();
		if ( current_user_can( 'edit_post', $item->ID ) ) {
			$actions['edit'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $item->ID ) ), esc_html__( 'Edit original', 'marclie-lingua' ) );
		}
		if ( 'publish' === $item->post_status ) {
			$actions['view'] = sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( (string) get_permalink( $item ) ), esc_html__( 'View original', 'marclie-lingua' ), esc_html__( '(opens in a new tab)', 'marclie-lingua' ) );
		}

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a>%3$s</strong>%4$s',
			esc_url( PostEditor::url( $item->ID, $lang ) ),
			esc_html( $title ),
			$state,
			$this->row_actions( $actions )
		);
	}

	/** @param \WP_Post $item */
	protected function column_date( $item ) {
		$time = 'publish' === $item->post_status || 'future' === $item->post_status ? $item->post_date : $item->post_modified;

		return sprintf( '<span class="mlg-date">%s</span>', esc_html( mysql2date( (string) get_option( 'date_format' ), $time ) ) );
	}

	/**
	 * Translated-language columns.
	 *
	 * @param \WP_Post $item
	 * @param string   $column_name
	 */
	protected function column_default( $item, $column_name ) {
		if ( ! str_starts_with( $column_name, 'mlg_' ) ) {
			return '';
		}
		$code = substr( $column_name, 4 );
		$name = $code;
		foreach ( $this->targets as $lang ) {
			if ( $lang->code === $code ) {
				$name = $lang->name;
			}
		}

		return PostEditor::pill( self::states( $code )[ $item->ID ] ?? 'none', PostEditor::url( $item->ID, $code ), $name );
	}

	/** The screen. */
	public static function render(): void {
		$targets = Settings::targets();
		echo '<div class="wrap mlg mlg-posts">';
		echo '<div class="mlg-head"><div class="mlg-head__text"><h1 class="mlg-head__title">' . esc_html__( 'Posts', 'marclie-lingua' ) . '</h1>';
		echo '<p class="mlg-head__sub">' . esc_html__( 'Translate each blog post as a whole: title, content, excerpt, URL and SEO.', 'marclie-lingua' ) . '</p></div></div>';
		echo '<hr class="wp-header-end">';
		if ( ! Settings::documentBlog() ) {
			Page::emptyState(
				'dashicons-admin-post',
				__( 'Whole-post translation is off', 'marclie-lingua' ),
				__( 'Blog posts are translated another way right now. To translate them here, open the settings and choose “Translate whole posts” under Blog posts.', 'marclie-lingua' ),
				admin_url( 'admin.php?page=' . SettingsPage::SLUG . '#mlg-section-blog' ),
				__( 'Open settings', 'marclie-lingua' )
			);
			echo '</div>';
			return;
		}
		if ( ! $targets ) {
			Page::emptyState(
				'dashicons-translation',
				__( 'No translated language yet', 'marclie-lingua' ),
				__( 'Add the languages you want to translate into, then come back to translate your posts.', 'marclie-lingua' ),
				admin_url( 'admin.php?page=' . SettingsPage::SLUG ),
				__( 'Add a language', 'marclie-lingua' )
			);
			echo '</div>';
			return;
		}
		self::notices();
		self::overview( $targets );
		$table = new self( $targets );
		$table->prepare_items();
		?>
		<form method="get" class="mlg-posts__form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php $table->search_box( __( 'Search posts', 'marclie-lingua' ), 'mlg-post-search' ); ?>
			<?php $table->display(); ?>
		</form>
		</div>
		<?php
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of redirect results.
		if ( empty( $_GET['mlg_deleted'] ) ) {
			return;
		}
		$post = get_post( absint( $_GET['mlg_deleted'] ) );
		$lang = Settings::lang( sanitize_key( wp_unslash( $_GET['mlg_deleted_lang'] ?? '' ) ) );
		// phpcs:enable
		if ( ! $post instanceof \WP_Post || null === $lang ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: 1: language name, 2: post title. */
			esc_html( sprintf( __( '%1$s translation of “%2$s” deleted.', 'marclie-lingua' ), $lang->name, $post->post_title ) )
		);
	}

	/**
	 * Progress per language over the published posts.
	 *
	 * @param list<Lang> $targets
	 */
	private static function overview( array $targets ): void {
		$live  = self::ids( 'publish' );
		$total = count( $live );
		if ( 0 === $total ) {
			return;
		}
		$isLive = array_flip( $live );
		echo '<section class="mlg-overview" aria-labelledby="mlg-overview-title"><h2 class="screen-reader-text" id="mlg-overview-title">' . esc_html__( 'Progress by language', 'marclie-lingua' ) . '</h2><ul class="mlg-overview__list">';
		foreach ( $targets as $lang ) {
			$count = array_fill_keys( array( 'draft', 'published', 'outdated' ), 0 );
			foreach ( self::states( $lang->code ) as $id => $state ) {
				if ( isset( $isLive[ $id ] ) ) {
					++$count[ $state ];
				}
			}
			$done  = $count['published'] + $count['outdated'];
			$todo  = $total - $done - $count['draft'];
			$links = array();
			if ( $count['outdated'] > 0 ) {
				/* translators: %s: number of posts. */
				$links[] = self::filterLink( $lang->code, 'outdated', sprintf( _n( '%s needs review', '%s need review', $count['outdated'], 'marclie-lingua' ), number_format_i18n( $count['outdated'] ) ), 'outdated' );
			}
			if ( $count['draft'] > 0 ) {
				/* translators: %s: number of posts. */
				$links[] = self::filterLink( $lang->code, 'draft', sprintf( _n( '%s draft', '%s drafts', $count['draft'], 'marclie-lingua' ), number_format_i18n( $count['draft'] ) ), 'draft' );
			}
			if ( $todo > 0 ) {
				/* translators: %s: number of posts. */
				$links[] = self::filterLink( $lang->code, 'none', sprintf( _n( '%s to translate', '%s to translate', $todo, 'marclie-lingua' ), number_format_i18n( $todo ) ), 'none' );
			}
			if ( ! $links ) {
				$links[] = '<span class="mlg-overview__done">' . esc_html__( 'All done', 'marclie-lingua' ) . '</span>';
			}
			printf(
				'<li class="mlg-overview__item"><div class="mlg-overview__head"><span class="mlg-overview__lang">%1$s</span><span class="mlg-overview__count">%2$s</span></div><div class="mlg-meter" aria-hidden="true"><span class="mlg-meter__done" style="width:%3$s%%"></span><span class="mlg-meter__review" style="width:%4$s%%"></span></div><p class="mlg-overview__links">%5$s</p></li>',
				esc_html( $lang->name ),
				/* translators: 1: translated posts, 2: published posts. */
				esc_html( sprintf( __( '%1$s of %2$s published', 'marclie-lingua' ), number_format_i18n( $done ), number_format_i18n( $total ) ) ),
				esc_attr( (string) round( 100 * $count['published'] / $total, 2 ) ),
				esc_attr( (string) round( 100 * $count['outdated'] / $total, 2 ) ),
				implode( '', $links ) // escaped in filterLink()
			);
		}
		echo '</ul></section>';
	}

	private static function filterLink( string $lang, string $state, string $label, string $dot ): string {
		return sprintf(
			'<a href="%1$s"><span class="mlg-dot mlg-dot--%2$s" aria-hidden="true"></span>%3$s</a>',
			esc_url( self::url( array( 'mlg_lang' => $lang, 'mlg_state' => $state ) ) ),
			esc_attr( $dot ),
			esc_html( $label )
		);
	}
}
