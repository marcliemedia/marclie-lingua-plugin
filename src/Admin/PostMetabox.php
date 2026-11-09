<?php
/**
 * "Translations" box on the edit screen of a blog post (blog mode `document`; classic and block editor; users with
 * the plugin capability): the state of the post in each translated language with a link to its translation screen
 * (PostEditor). After the post is updated, the published translations that no longer match it are announced: an
 * admin notice on the classic editor; on the block editor assets/post-editor.js asks `wp_ajax_mlg_post_states`
 * for the refreshed box and shows an editor notice.
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Lang;
use Marclie\Lingua\PostTranslations;
use Marclie\Lingua\Settings;

defined( 'ABSPATH' ) || exit;

final class PostMetabox {

	public static function hooks(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'style' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'script' ) );
		add_action( 'wp_ajax_mlg_post_states', array( self::class, 'ajax' ) );
	}

	/** @param mixed $post */
	private static function applies( $post ): bool {
		return $post instanceof \WP_Post
			&& PostTranslations::isDocumentType( $post->post_type )
			&& array() !== Settings::targets()
			&& current_user_can( Page::capability() );
	}

	/** The post being edited on post.php / post-new.php, if the box applies to it. */
	private static function screenPost(): ?\WP_Post {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = $GLOBALS['post'] ?? null;

		return null !== $screen && 'post' === $screen->base && self::applies( $post ) ? $post : null;
	}

	/**
	 * @param string $postType
	 * @param mixed  $post
	 */
	public static function register( $postType, $post ): void {
		if ( ! self::applies( $post ) ) {
			return;
		}
		add_meta_box( 'mlg-translations', __( 'Translations', 'marclie-lingua' ), array( self::class, 'render' ), (string) $postType, 'side', 'default', array( '__back_compat_meta_box' => false ) );
	}

	public static function render( \WP_Post $post ): void {
		echo '<div class="mlg mlg-mb" data-mlg-metabox>' . self::body( $post ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in body().
	}

	/** Inner HTML of the box (escaped). */
	private static function body( \WP_Post $post ): string {
		if ( 'auto-draft' === $post->post_status ) {
			return '<p class="mlg-mb__empty">' . esc_html__( 'Save the post first, then translate it here.', 'marclie-lingua' ) . '</p>';
		}
		$out = '<ul class="mlg-mb__list">';
		foreach ( Settings::targets() as $lang ) {
			$state = PostTranslations::state( $post, $lang->code );
			$out  .= sprintf(
				'<li class="mlg-mb__item"><span class="mlg-mb__lang">%1$s</span>%2$s<a class="mlg-mb__link" href="%3$s">%4$s<span class="screen-reader-text"> (%1$s)</span></a></li>',
				esc_html( $lang->name ),
				PostEditor::pill( $state ),
				esc_url( PostEditor::url( $post->ID, $lang->code ) ),
				'none' === $state ? esc_html__( 'Translate', 'marclie-lingua' ) : esc_html__( 'Edit', 'marclie-lingua' )
			);
		}

		return $out . '</ul><p class="mlg-mb__all"><a href="' . esc_url( PostsTable::url() ) . '">' . esc_html__( 'All post translations', 'marclie-lingua' ) . '</a></p>';
	}

	/**
	 * Languages whose published translation no longer matches the post.
	 *
	 * @return list<Lang>
	 */
	private static function outdated( \WP_Post $post ): array {
		return array_values( array_filter( Settings::targets(), static fn( Lang $lang ): bool => 'outdated' === PostTranslations::state( $post, $lang->code ) ) );
	}

	/** @param list<Lang> $langs */
	private static function message( array $langs ): string {
		return sprintf(
			/* translators: 1: number of translations, 2: language names. */
			_n( 'Post updated: %1$s translation may need review (%2$s).', 'Post updated: %1$s translations may need review (%2$s).', count( $langs ), 'marclie-lingua' ),
			number_format_i18n( count( $langs ) ),
			implode( ', ', array_map( static fn( Lang $lang ): string => $lang->name, $langs ) )
		);
	}

	/** Classic editor: after "Post updated" (`message` in the URL). */
	public static function notice(): void {
		$post = self::screenPost();
		if ( null === $post || empty( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$langs = self::outdated( $post );
		if ( ! $langs ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( self::message( $langs ) ),
			esc_url( PostEditor::url( $post->ID, $langs[0]->code ) ),
			esc_html__( 'Review translation', 'marclie-lingua' )
		);
	}

	public static function style(): void {
		if ( null !== self::screenPost() ) {
			Page::style();
		}
	}

	/** Block editor: refresh the box and announce translations to review after each save. */
	public static function script(): void {
		$post = self::screenPost();
		if ( null === $post ) {
			return;
		}
		wp_enqueue_script( 'mlg-post-editor', MLG_URL . 'assets/post-editor.js', array( 'wp-data', 'wp-notices' ), (string) filemtime( MLG_DIR . 'assets/post-editor.js' ), array( 'in_footer' => true ) );
		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'mlg_metabox' ),
			'post'    => $post->ID,
		);
		wp_add_inline_script( 'mlg-post-editor', 'window.mlgMetabox = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function ajax(): void {
		check_ajax_referer( 'mlg_metabox', 'nonce' );
		if ( ! current_user_can( Page::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$post = get_post( absint( $_POST['post'] ?? 0 ) );
		if ( ! self::applies( $post ) ) {
			wp_send_json_error( null, 400 );
		}
		$langs = self::outdated( $post );
		wp_send_json_success(
			array(
				'html'    => self::body( $post ),
				'message' => $langs ? self::message( $langs ) : '',
				'url'     => $langs ? PostEditor::url( $post->ID, $langs[0]->code ) : '',
				'action'  => __( 'Review translation', 'marclie-lingua' ),
			)
		);
	}
}
