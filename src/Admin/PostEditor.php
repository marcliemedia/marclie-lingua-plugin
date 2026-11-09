<?php
/**
 * Admin › Translations › Posts menu entry and the translation screen of one post in one language (blog mode
 * `document`): `admin.php?page=marclie-lingua-post&post={ID}&lang={code}`, hidden from the menu.
 *
 * The original post is shown read-only (title, URL slug, excerpt, rendered content) next to the translation form:
 * title, URL slug (empty = the original slug; live preview of the translated URL), excerpt, content (the editor
 * kind of the post: visual + HTML for classic content, HTML only for block markup so the block comments survive),
 * SEO title and description (empty = title / excerpt). One POST form; the clicked button (`mlg_do`) decides:
 * `draft` saves a draft, `publish` publishes or updates, `unpublish` saves it as a draft (visitors see the original
 * again), `review` marks it as matching the edited original without changing it, `delete` deletes it. Publishing
 * needs a title and content; a rejected submission is shown again with what was typed.
 *
 * When the original changed since the translation was saved or reviewed, a banner shows what changed: a text diff
 * (the `wp_text_diff()` engine, with two unchanged lines of context) of the saved snapshot and the current post.
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Lang;
use Marclie\Lingua\PostTranslations;
use Marclie\Lingua\Router;
use Marclie\Lingua\Settings;

defined( 'ABSPATH' ) || exit;

final class PostEditor {

	public const SLUG = 'marclie-lingua-post';

	private const NONCE = 'mlg_post_';

	/** ID of the content editor (wp_editor IDs: lowercase letters only). */
	private const EDITOR_ID = 'mlgcontent';

	/** @var array<string, string>|null Fields of a rejected submission, shown again. */
	private static ?array $submitted = null;

	private static string $error = '';

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 12 );
		add_filter( 'set_screen_option_mlg_posts_per_page', static fn( $status, $option, $value ) => max( 5, min( 200, (int) $value ) ), 10, 3 );
	}

	/** Translations › Posts (between Texts and Settings) and the hidden translation screen. */
	public static function menu(): void {
		$list = (string) add_submenu_page( Page::SLUG, __( 'Post translations', 'marclie-lingua' ), __( 'Posts', 'marclie-lingua' ), Page::capability(), PostsTable::SLUG, array( PostsTable::class, 'render' ) );
		add_action( 'load-' . $list, array( PostsTable::class, 'load' ) );
		$edit = (string) add_submenu_page( '', __( 'Translate post', 'marclie-lingua' ), '', Page::capability(), self::SLUG, array( self::class, 'render' ) );
		add_action( 'load-' . $edit, array( self::class, 'load' ) );
	}

	/** URL of the translation screen of a post in a language. */
	public static function url( int $postId, string $lang, array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'post' => $postId, 'lang' => $lang ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function stateLabel( string $state ): string {
		$labels = array(
			'none'      => __( 'Not translated', 'marclie-lingua' ),
			'draft'     => __( 'Draft', 'marclie-lingua' ),
			'published' => __( 'Published', 'marclie-lingua' ),
			'outdated'  => __( 'Needs review', 'marclie-lingua' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * Status pill of a translation state; a link when `$url` is given (`$label` then names the target for screen
	 * readers, e.g. the language).
	 */
	public static function pill( string $state, string $url = '', string $label = '' ): string {
		$text = 'none' === $state && '' !== $url ? __( 'Translate', 'marclie-lingua' ) : self::stateLabel( $state );
		$sr   = '' !== $label ? '<span class="screen-reader-text">' . esc_html( $label ) . ': </span>' : '';
		if ( '' === $url ) {
			return sprintf( '<span class="mlg-pill mlg-pill--%1$s">%2$s%3$s</span>', esc_attr( $state ), $sr, esc_html( $text ) );
		}

		return sprintf(
			'<a class="mlg-pill mlg-pill--%1$s" href="%2$s">%3$s%4$s%5$s</a>',
			esc_attr( $state ),
			esc_url( $url ),
			$sr,
			esc_html( $text ),
			'none' === $state ? '' : '<span class="screen-reader-text"> — ' . esc_html__( 'edit translation', 'marclie-lingua' ) . '</span>'
		);
	}

	/** The post of the request, if it can be translated here. */
	private static function post(): ?\WP_Post {
		$id   = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen state.
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) && PostTranslations::isDocumentType( $post->post_type ) ? $post : null;
	}

	/** Translated language of the request (`lang`, default the first one). */
	private static function lang(): ?Lang {
		$code = isset( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen state.
		foreach ( Settings::targets() as $lang ) {
			if ( $lang->code === $code ) {
				return $lang;
			}
		}

		return Settings::targets()[0] ?? null;
	}

	public static function load(): void {
		// a page without a menu entry has no title of its own (admin-header.php)
		$GLOBALS['title'] = __( 'Translate post', 'marclie-lingua' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin page title.
		// highlight Translations › Posts: get_admin_page_parent() (menu-header.php) would reset the parent to the
		// hidden page's empty parent, so the hidden entry leaves the menu data once the page title is known
		add_action( 'admin_head', static fn() => remove_submenu_page( '', self::SLUG ) );
		add_filter( 'parent_file', static fn() => Page::SLUG );
		add_filter( 'submenu_file', static fn() => PostsTable::SLUG );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['mlg_post_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
			self::save();
		}
	}

	public static function assets(): void {
		$post = self::post();
		$lang = self::lang();
		Page::style();
		if ( null === $post || null === $lang ) {
			return;
		}
		wp_enqueue_script( 'mlg-post-editor', MLG_URL . 'assets/post-editor.js', array(), (string) filemtime( MLG_DIR . 'assets/post-editor.js' ), array( 'in_footer' => true ) );
		$config = array(
			'editorId' => self::EDITOR_ID,
			'source'   => array(
				'title'   => (string) $post->post_title,
				'excerpt' => (string) $post->post_excerpt,
				'content' => (string) $post->post_content,
			),
			'i18n'     => array(
				'unsaved'       => __( 'You have unsaved changes.', 'marclie-lingua' ),
				'confirmDelete' => __( 'Delete this translation? This cannot be undone.', 'marclie-lingua' ),
				'confirmReview' => __( 'Your unsaved changes will be lost. Mark as reviewed anyway?', 'marclie-lingua' ),
				/* translators: %s: number of fields. */
				'copied'        => __( 'Copied the original into %s empty fields.', 'marclie-lingua' ),
				'copiedOne'     => __( 'Copied the original into 1 empty field.', 'marclie-lingua' ),
				'copiedNone'    => __( 'Nothing copied: the fields already have text.', 'marclie-lingua' ),
				/* translators: 1: characters typed, 2: recommended maximum. */
				'count'         => __( '%1$s / %2$s', 'marclie-lingua' ),
				'tooLong'       => __( 'may be cut off', 'marclie-lingua' ),
			),
		);
		wp_add_inline_script( 'mlg-post-editor', 'window.mlgPostEditor = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	private static function save(): void {
		$post = self::post();
		$lang = self::lang();
		if ( null === $post || null === $lang ) {
			wp_die( esc_html__( 'This post cannot be translated here.', 'marclie-lingua' ), 404 );
		}
		check_admin_referer( self::NONCE . $post->ID . '_' . $lang->code, 'mlg_post_nonce' );
		if ( ! current_user_can( Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit translations.', 'marclie-lingua' ), 403 );
		}
		$do  = sanitize_key( wp_unslash( $_POST['mlg_do'] ?? '' ) );
		$old = PostTranslations::get( $post->ID, $lang->code );

		if ( 'delete' === $do ) {
			PostTranslations::delete( $post->ID, $lang->code );
			wp_safe_redirect( PostsTable::url( array( 'mlg_deleted' => $post->ID, 'mlg_deleted_lang' => $lang->code ) ) );
			exit;
		}
		if ( 'review' === $do ) {
			PostTranslations::markReviewed( $post->ID, $lang->code );
			wp_safe_redirect( self::url( $post->ID, $lang->code, array( 'mlg_done' => 'reviewed' ) ) );
			exit;
		}
		if ( ! in_array( $do, array( 'draft', 'publish', 'unpublish' ), true ) ) {
			wp_safe_redirect( self::url( $post->ID, $lang->code ) );
			exit;
		}

		$raw    = (array) wp_unslash( $_POST['mlg'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by PostTranslations::save() (content: wp_kses_post without unfiltered_html).
		$fields = array();
		foreach ( PostTranslations::FIELDS as $field ) {
			$fields[ $field ] = is_string( $raw[ $field ] ?? null ) ? $raw[ $field ] : '';
		}
		$status = 'publish' === $do ? 'published' : 'draft';
		if ( 'published' === $status && ( '' === trim( $fields['title'] ) || '' === trim( $fields['content'] ) ) ) {
			self::$submitted = $fields;
			self::$error     = __( 'Not published: add the translated title and content first.', 'marclie-lingua' );
			return;
		}
		try {
			$row = PostTranslations::save( $post->ID, $lang->code, $fields, $status );
		} catch ( \InvalidArgumentException $e ) {
			self::$submitted = $fields;
			self::$error     = __( 'The translation could not be saved. Reload the page and try again.', 'marclie-lingua' );
			return;
		}

		if ( 'unpublish' === $do ) {
			$done = 'unpublished';
		} elseif ( 'draft' === $do ) {
			$done = 'draft';
		} else {
			$done = null !== $old && 'published' === $old['status'] ? 'updated' : 'published';
		}
		$extra = array( 'mlg_done' => $done );
		$typed = sanitize_title( $fields['slug'] );
		if ( '' !== $typed && $typed !== (string) $row['slug'] ) {
			$extra['mlg_slug'] = 'changed';
		}
		wp_safe_redirect( self::url( $post->ID, $lang->code, $extra ) );
		exit;
	}

	public static function render(): void {
		$post = self::post();
		$lang = self::lang();
		echo '<div class="wrap mlg mlg-editor">';
		if ( null === $post || null === $lang ) {
			echo '<h1>' . esc_html__( 'Translate post', 'marclie-lingua' ) . '</h1><hr class="wp-header-end">';
			Page::emptyState(
				'dashicons-warning',
				__( 'This post cannot be translated here', 'marclie-lingua' ),
				Settings::documentBlog()
					? __( 'It may have been deleted, or it is not a blog post.', 'marclie-lingua' )
					: __( 'Whole-post translation is turned off in the settings.', 'marclie-lingua' ),
				PostsTable::url(),
				__( 'Back to posts', 'marclie-lingua' )
			);
			echo '</div>';
			return;
		}

		$source = Settings::source();
		$row    = PostTranslations::get( $post->ID, $lang->code );
		$state  = null !== $row ? PostTranslations::state( $post, $lang->code ) : 'none';
		$values = self::$submitted ?? array();
		foreach ( PostTranslations::FIELDS as $field ) {
			$values[ $field ] = (string) ( $values[ $field ] ?? ( $row[ $field ] ?? '' ) );
		}
		$live  = in_array( $state, array( 'published', 'outdated' ), true );
		$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'marclie-lingua' );
		?>
		<p class="mlg-back"><a href="<?php echo esc_url( PostsTable::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All posts', 'marclie-lingua' ); ?></a></p>
		<div class="mlg-head mlg-head--editor">
			<div class="mlg-head__text">
				<h1 class="mlg-head__title"><?php echo esc_html( $title ); ?></h1>
				<p class="mlg-head__sub">
					<?php
					/* translators: 1: source language name, 2: translated language name. */
					echo esc_html( sprintf( __( 'Translating from %1$s to %2$s', 'marclie-lingua' ), $source->name, $lang->name ) );
					?>
				</p>
			</div>
			<?php self::languageNav( $post, $lang ); ?>
		</div>
		<hr class="wp-header-end">
		<?php
		self::notices();
		if ( null !== $row && PostTranslations::isOutdated( $row, $post ) ) {
			self::changedBanner( $row, $post, $live );
		}
		?>
		<form method="post" id="mlg-post-form" class="mlg-editor__form" action="<?php echo esc_url( self::url( $post->ID, $lang->code ) ); ?>" novalidate>
			<?php wp_nonce_field( self::NONCE . $post->ID . '_' . $lang->code, 'mlg_post_nonce' ); ?>
			<button type="submit" name="mlg_do" value="<?php echo esc_attr( $live ? 'publish' : 'draft' ); ?>" class="mlg-default-submit" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save', 'marclie-lingua' ); ?></button>
			<div class="mlg-editor__grid">
				<?php self::sourcePane( $post, $source ); ?>
				<?php self::targetPane( $post, $lang, $values ); ?>
			</div>
			<?php self::actionBar( $post, $lang, $row, $state ); ?>
		</form>
		</div>
		<?php
	}

	/** Segmented control: the translated languages of this post with their state. */
	private static function languageNav( \WP_Post $post, Lang $current ): void {
		$targets = Settings::targets();
		if ( count( $targets ) < 2 ) {
			return;
		}
		echo '<nav class="mlg-seg" aria-label="' . esc_attr__( 'Languages of this post', 'marclie-lingua' ) . '">';
		foreach ( $targets as $lang ) {
			$state = PostTranslations::state( $post, $lang->code );
			printf(
				'<a class="mlg-seg__item" href="%1$s"%2$s><span class="mlg-dot mlg-dot--%3$s" aria-hidden="true"></span>%4$s<span class="mlg-seg__meta">%5$s</span></a>',
				esc_url( self::url( $post->ID, $lang->code ) ),
				$lang->code === $current->code ? ' aria-current="page"' : '',
				esc_attr( $state ),
				esc_html( $lang->name ),
				esc_html( self::stateLabel( $state ) )
			);
		}
		echo '</nav>';
	}

	private static function notices(): void {
		if ( '' !== self::$error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( self::$error ) );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of redirect results.
		$done     = isset( $_GET['mlg_done'] ) ? sanitize_key( wp_unslash( $_GET['mlg_done'] ) ) : '';
		$messages = array(
			'draft'       => __( 'Draft saved.', 'marclie-lingua' ),
			'published'   => __( 'Translation published.', 'marclie-lingua' ),
			'updated'     => __( 'Translation updated.', 'marclie-lingua' ),
			'unpublished' => __( 'Translation unpublished. Visitors see the original post on this page again.', 'marclie-lingua' ),
			'reviewed'    => __( 'Marked as reviewed.', 'marclie-lingua' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			$text = $messages[ $done ];
			if ( isset( $_GET['mlg_slug'] ) ) {
				$text .= ' ' . __( 'That URL slug was already taken, so a number was added to it.', 'marclie-lingua' );
			}
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
		}
		// phpcs:enable
	}

	/**
	 * "The original changed" banner with the diff per field and "Mark as reviewed".
	 *
	 * @param array<string, mixed> $row
	 */
	private static function changedBanner( array $row, \WP_Post $post, bool $live ): void {
		$labels  = array(
			'title'   => __( 'Title', 'marclie-lingua' ),
			'excerpt' => __( 'Excerpt', 'marclie-lingua' ),
			'content' => __( 'Content', 'marclie-lingua' ),
		);
		$current = PostTranslations::snapshot( $post );
		$changes = array();
		foreach ( $labels as $field => $label ) {
			$diff = self::diff( (string) ( $row['source_snapshot'][ $field ] ?? '' ), $current[ $field ] );
			if ( '' !== $diff ) {
				$changes[ $label ] = $diff;
			}
		}
		?>
		<section class="mlg-banner mlg-banner--warning" aria-labelledby="mlg-changed-title">
			<span class="dashicons dashicons-update mlg-banner__icon" aria-hidden="true"></span>
			<div class="mlg-banner__body">
				<h2 class="mlg-banner__title" id="mlg-changed-title"><?php esc_html_e( 'The original post changed', 'marclie-lingua' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$live
							? __( 'This translation is still online. Check what changed and update the translation, or mark it as reviewed if nothing needs to change.', 'marclie-lingua' )
							: __( 'The original changed since this draft was saved. Check what changed before you publish.', 'marclie-lingua' )
					);
					?>
				</p>
				<?php if ( $changes ) : ?>
					<details class="mlg-changes">
						<summary>
							<?php
							/* translators: %s: list of changed fields, e.g. "Title, Content". */
							echo esc_html( sprintf( __( 'Show changes: %s', 'marclie-lingua' ), implode( ', ', array_keys( $changes ) ) ) );
							?>
						</summary>
						<?php foreach ( $changes as $label => $diff ) : ?>
							<h3 class="mlg-changes__field"><?php echo esc_html( $label ); ?></h3>
							<div class="mlg-changes__diff"><?php echo wp_kses_post( $diff ); ?></div>
						<?php endforeach; ?>
					</details>
				<?php endif; ?>
			</div>
			<div class="mlg-banner__actions">
				<button type="submit" form="mlg-post-form" name="mlg_do" value="review" class="button" data-mlg-review><?php esc_html_e( 'Mark as reviewed', 'marclie-lingua' ); ?></button>
			</div>
		</section>
		<?php
	}

	/** Split-view table diff of two texts (empty when equal): wp_text_diff() with two lines of unchanged context. */
	private static function diff( string $before, string $after ): string {
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require ABSPATH . WPINC . '/wp-diff.php';
		}
		$before = normalize_whitespace( $before );
		$after  = normalize_whitespace( $after );
		if ( $before === $after ) {
			return '';
		}
		$renderer = new class( array( 'show_split_view' => true ) ) extends \WP_Text_Diff_Renderer_Table {

			public $_leading_context_lines = 2; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- parent property.

			public $_trailing_context_lines = 2; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- parent property.

			private int $blocks = 0;

			/** A divider between changed blocks (unchanged lines skipped). */
			public function _startBlock( $header ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- parent method.
				return 0 === $this->blocks++ ? '' : '<tr class="mlg-diff-gap"><td colspan="2"><span class="screen-reader-text">' . esc_html__( 'Unchanged lines skipped', 'marclie-lingua' ) . '</span></td></tr>';
			}
		};
		$rows     = $renderer->render( new \Text_Diff( explode( "\n", $before ), explode( "\n", $after ) ) );
		if ( '' === trim( (string) $rows ) ) {
			return '';
		}

		return '<table class="diff is-split-view mlg-diff"><thead><tr class="diff-sub-title"><th scope="col">' . esc_html__( 'Before', 'marclie-lingua' ) . '</th><th scope="col">' . esc_html__( 'Now', 'marclie-lingua' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** The original post, read-only, rendered. */
	private static function sourcePane( \WP_Post $post, Lang $source ): void {
		$content = has_blocks( $post->post_content ) ? do_blocks( $post->post_content ) : wpautop( $post->post_content );
		?>
		<section class="mlg-pane mlg-pane--source" aria-labelledby="mlg-source-title">
			<header class="mlg-pane__head">
				<h2 class="mlg-pane__title" id="mlg-source-title"><?php esc_html_e( 'Original', 'marclie-lingua' ); ?> <span class="mlg-pane__lang"><?php echo esc_html( $source->name ); ?></span></h2>
				<?php if ( current_user_can( 'edit_post', $post->ID ) ) : ?>
					<a class="mlg-pane__link" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit original', 'marclie-lingua' ); ?></a>
				<?php endif; ?>
			</header>
			<div class="mlg-pane__body mlg-pane__body--scroll" lang="<?php echo esc_attr( $source->bcp47 ); ?>" tabindex="0" aria-label="<?php esc_attr_e( 'Original post', 'marclie-lingua' ); ?>">
				<div class="mlg-src">
					<p class="mlg-src__label"><?php esc_html_e( 'Title', 'marclie-lingua' ); ?></p>
					<p class="mlg-src__title"><?php echo esc_html( $post->post_title ); ?></p>
				</div>
				<div class="mlg-src">
					<p class="mlg-src__label"><?php esc_html_e( 'URL slug', 'marclie-lingua' ); ?></p>
					<p class="mlg-src__slug"><code><?php echo esc_html( urldecode( $post->post_name ) ); ?></code></p>
				</div>
				<?php if ( '' !== trim( $post->post_excerpt ) ) : ?>
					<div class="mlg-src">
						<p class="mlg-src__label"><?php esc_html_e( 'Excerpt', 'marclie-lingua' ); ?></p>
						<p class="mlg-src__excerpt"><?php echo esc_html( $post->post_excerpt ); ?></p>
					</div>
				<?php endif; ?>
				<div class="mlg-src">
					<p class="mlg-src__label"><?php esc_html_e( 'Content', 'marclie-lingua' ); ?></p>
					<div class="mlg-prose"><?php echo wp_kses_post( $content ); ?></div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * The translation form fields.
	 *
	 * @param array<string, string> $values
	 */
	private static function targetPane( \WP_Post $post, Lang $lang, array $values ): void {
		[ $before, $after ] = self::slugUrl( $post, $lang );
		$blocks             = has_blocks( $post->post_content );
		$bcp47              = $lang->bcp47;
		?>
		<section class="mlg-pane mlg-pane--target" aria-labelledby="mlg-target-title">
			<header class="mlg-pane__head">
				<h2 class="mlg-pane__title" id="mlg-target-title"><?php esc_html_e( 'Translation', 'marclie-lingua' ); ?> <span class="mlg-pane__lang"><?php echo esc_html( $lang->name ); ?></span></h2>
				<span class="mlg-pane__status" role="status" data-mlg-copy-status></span>
				<button type="button" class="button" data-mlg-copy aria-describedby="mlg-copy-help"><?php esc_html_e( 'Start from original', 'marclie-lingua' ); ?></button>
				<span class="screen-reader-text" id="mlg-copy-help"><?php esc_html_e( 'Copies the original title, excerpt and content into the empty fields.', 'marclie-lingua' ); ?></span>
			</header>
			<div class="mlg-pane__body">
				<div class="mlg-field">
					<label class="mlg-field__label" for="mlg-title"><?php esc_html_e( 'Title', 'marclie-lingua' ); ?></label>
					<input type="text" id="mlg-title" name="mlg[title]" class="mlg-input mlg-input--title" value="<?php echo esc_attr( $values['title'] ); ?>" lang="<?php echo esc_attr( $bcp47 ); ?>" autocomplete="off" data-mlg-field="title">
				</div>

				<div class="mlg-field">
					<label class="mlg-field__label" for="mlg-slug"><?php esc_html_e( 'URL slug', 'marclie-lingua' ); ?></label>
					<div class="mlg-slug">
						<input type="text" id="mlg-slug" name="mlg[slug]" class="mlg-input mlg-input--mono" value="<?php echo esc_attr( urldecode( $values['slug'] ) ); ?>" placeholder="<?php echo esc_attr( urldecode( $post->post_name ) ); ?>" autocomplete="off" spellcheck="false" aria-describedby="mlg-slug-help mlg-slug-url" data-mlg-slug>
						<button type="button" class="button" data-mlg-slug-from-title><?php esc_html_e( 'Use title', 'marclie-lingua' ); ?></button>
					</div>
					<?php if ( null !== $before ) : ?>
						<p class="mlg-url" id="mlg-slug-url"><span class="screen-reader-text"><?php esc_html_e( 'Address:', 'marclie-lingua' ); ?> </span><span><?php echo esc_html( $before ); ?></span><strong data-mlg-url-slug data-fallback="<?php echo esc_attr( urldecode( $post->post_name ) ); ?>"><?php echo esc_html( urldecode( '' !== $values['slug'] ? $values['slug'] : $post->post_name ) ); ?></strong><span><?php echo esc_html( (string) $after ); ?></span></p>
					<?php else : ?>
						<p class="mlg-url" id="mlg-slug-url"><?php esc_html_e( 'Translated URLs need pretty permalinks (Settings › Permalinks).', 'marclie-lingua' ); ?></p>
					<?php endif; ?>
					<p class="mlg-field__help" id="mlg-slug-help"><?php esc_html_e( 'Leave empty to keep the original slug.', 'marclie-lingua' ); ?></p>
				</div>

				<div class="mlg-field">
					<label class="mlg-field__label" for="mlg-excerpt"><?php esc_html_e( 'Excerpt', 'marclie-lingua' ); ?></label>
					<textarea id="mlg-excerpt" name="mlg[excerpt]" class="mlg-input" rows="3" lang="<?php echo esc_attr( $bcp47 ); ?>" aria-describedby="mlg-excerpt-help" data-mlg-field="excerpt"><?php echo esc_textarea( $values['excerpt'] ); ?></textarea>
					<p class="mlg-field__help" id="mlg-excerpt-help"><?php esc_html_e( 'Short summary shown in post lists.', 'marclie-lingua' ); ?></p>
				</div>

				<div class="mlg-field mlg-field--content">
					<label class="mlg-field__label" for="<?php echo esc_attr( self::EDITOR_ID ); ?>"><?php esc_html_e( 'Content', 'marclie-lingua' ); ?></label>
					<?php if ( $blocks ) : ?>
						<p class="mlg-field__help"><?php esc_html_e( 'This post is made of blocks: translate the text and keep the <!-- wp:… --> lines as they are.', 'marclie-lingua' ); ?></p>
					<?php endif; ?>
					<?php
					wp_editor(
						$values['content'],
						self::EDITOR_ID,
						array(
							'textarea_name' => 'mlg[content]',
							'textarea_rows' => 22,
							'editor_height' => 520,
							'wpautop'       => false,
							'media_buttons' => true,
							'tinymce'       => ! $blocks,
							'quicktags'     => true,
							'editor_class'  => 'mlg-content',
						)
					);
					?>
				</div>

				<fieldset class="mlg-fieldset">
					<legend class="mlg-fieldset__legend"><?php esc_html_e( 'Search engines', 'marclie-lingua' ); ?></legend>
					<div class="mlg-field">
						<div class="mlg-field__row">
							<label class="mlg-field__label" for="mlg-seo-title"><?php esc_html_e( 'SEO title', 'marclie-lingua' ); ?></label>
							<span class="mlg-count" data-mlg-count-for="mlg-seo-title" data-max="60" aria-hidden="true"></span>
						</div>
						<input type="text" id="mlg-seo-title" name="mlg[seo_title]" class="mlg-input" value="<?php echo esc_attr( $values['seo_title'] ); ?>" lang="<?php echo esc_attr( $bcp47 ); ?>" aria-describedby="mlg-seo-title-help" autocomplete="off">
						<p class="mlg-field__help" id="mlg-seo-title-help"><?php esc_html_e( 'Shown in search results and browser tabs. About 60 characters. Leave empty to use the title.', 'marclie-lingua' ); ?></p>
					</div>
					<div class="mlg-field">
						<div class="mlg-field__row">
							<label class="mlg-field__label" for="mlg-seo-description"><?php esc_html_e( 'SEO description', 'marclie-lingua' ); ?></label>
							<span class="mlg-count" data-mlg-count-for="mlg-seo-description" data-max="155" aria-hidden="true"></span>
						</div>
						<textarea id="mlg-seo-description" name="mlg[seo_description]" class="mlg-input" rows="3" lang="<?php echo esc_attr( $bcp47 ); ?>" aria-describedby="mlg-seo-description-help"><?php echo esc_textarea( $values['seo_description'] ); ?></textarea>
						<p class="mlg-field__help" id="mlg-seo-description-help"><?php esc_html_e( 'The text under the title in search results. About 155 characters. Leave empty to use the excerpt.', 'marclie-lingua' ); ?></p>
					</div>
				</fieldset>
			</div>
		</section>
		<?php
	}

	/**
	 * Translated URL around the slug: [ before, after ], or [ null, null ] without pretty permalinks.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private static function slugUrl( \WP_Post $post, Lang $lang ): array {
		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$template = (string) ( get_sample_permalink( $post->ID )[0] ?? '' );
		$template = str_replace( array( '%postname%', '%pagename%' ), 'mlg-slug-placeholder', $template, $found );
		if ( 0 === $found ) {
			return array( null, null );
		}
		$parts = explode( 'mlg-slug-placeholder', Router::url( $template, $lang->code ), 2 );

		return array( urldecode( $parts[0] ), urldecode( $parts[1] ?? '' ) );
	}

	/**
	 * Sticky bar: state, last save, the actions.
	 *
	 * @param array<string, mixed>|null $row
	 */
	private static function actionBar( \WP_Post $post, Lang $lang, ?array $row, string $state ): void {
		$live  = in_array( $state, array( 'published', 'outdated' ), true );
		$saved = null !== $row ? strtotime( (string) $row['updated_at'] . ' UTC' ) : false;
		?>
		<div class="mlg-actionbar">
			<div class="mlg-actionbar__state">
				<?php echo self::pill( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
				<span class="mlg-actionbar__meta">
					<?php
					if ( false !== $saved ) {
						/* translators: %s: time since, e.g. "5 minutes". */
						echo esc_html( sprintf( __( 'Saved %s ago', 'marclie-lingua' ), human_time_diff( $saved ) ) );
					} else {
						esc_html_e( 'Not saved yet', 'marclie-lingua' );
					}
					?>
				</span>
				<span class="mlg-actionbar__dirty" data-mlg-dirty hidden><?php esc_html_e( 'Unsaved changes', 'marclie-lingua' ); ?></span>
			</div>
			<div class="mlg-actionbar__actions">
				<?php if ( null !== $row ) : ?>
					<button type="submit" name="mlg_do" value="delete" class="button-link button-link-delete" data-mlg-delete><?php esc_html_e( 'Delete translation', 'marclie-lingua' ); ?></button>
				<?php endif; ?>
				<?php if ( $live && 'publish' === $post->post_status ) : ?>
					<a class="button" href="<?php echo esc_url( Router::url( (string) get_permalink( $post ), $lang->code ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'marclie-lingua' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'marclie-lingua' ); ?></span><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				<?php endif; ?>
				<?php if ( $live ) : ?>
					<button type="submit" name="mlg_do" value="unpublish" class="button"><?php esc_html_e( 'Unpublish', 'marclie-lingua' ); ?></button>
					<button type="submit" name="mlg_do" value="publish" class="button button-primary" data-mlg-shortcut aria-keyshortcuts="Control+S"><?php esc_html_e( 'Update', 'marclie-lingua' ); ?></button>
				<?php else : ?>
					<button type="submit" name="mlg_do" value="draft" class="button" data-mlg-shortcut aria-keyshortcuts="Control+S"><?php esc_html_e( 'Save draft', 'marclie-lingua' ); ?></button>
					<button type="submit" name="mlg_do" value="publish" class="button button-primary"><?php esc_html_e( 'Publish', 'marclie-lingua' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
