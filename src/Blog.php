<?php
/**
 * Blog posts on the English site: not stored as translations (owner, 2026-11-04: free and quick for now). Their
 * parts — title, excerpt, photo, contents list, body, date; Bricks elements with `data-mlg-skip` and, on
 * archives, the Bricks Posts items linking to a post — are left out of the string translation and printed with
 * `lang="hr" data-mlg-browser` (HtmlTranslator); a single post page keeps `<html lang="hr">` and its Croatian
 * `<title>` / metas (Frontend). `assets/browser-translate.js` then translates those parts in the visitor's
 * browser with the built-in Translator API (Chrome / Edge on a computer): at once when the hr → en model is on
 * the device, else after the visitor's first click or key press (the browser only downloads the model after a
 * user action). Other browsers offer their own „Translate this page” on a single post (page language hr).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Blog {

	/** @return list<string> Post types treated as blog posts. */
	public static function types(): array {
		return (array) apply_filters( 'marclie_lingua/post_types', array( 'post' ) );
	}

	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'script' ) );
	}

	public static function script(): void {
		if ( ! Frontend::active() ) {
			return;
		}
		wp_enqueue_script( 'mlg-browser-translate', MLG_URL . 'assets/browser-translate.js', array(), (string) filemtime( MLG_DIR . 'assets/browser-translate.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}
}
