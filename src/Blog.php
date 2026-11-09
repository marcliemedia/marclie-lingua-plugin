<?php
/**
 * Blog posts in translated languages. Setting `blog`:
 * - `stored` (default): posts are translated from stored pairs like every other page and can be indexed;
 * - `browser`: posts are not stored as translations (free and quick, but their translated versions are noindex).
 *   Their parts — elements marked `data-mlg-skip`
 * and, on archives, the Bricks Posts items linking to a post — are left out of the string translation and printed
 * with `lang="{source}" data-mlg-browser` (HtmlTranslator); a single post page keeps its source `<html lang>`,
 * `<title>` and metas (Frontend). `assets/browser-translate.js` then translates those parts in the visitor's
 * browser with the built-in Translator API (Chrome / Edge on a computer): at once when the model is on the device,
 * else after the visitor's first click or key press (the browser only downloads the model after a user action).
 * Other browsers offer their own "Translate this page" on a single post.
 *
 * Filter `marclie_lingua/browser_fixes`: list of [ regex source, regex flags, replacement ] applied to the machine
 * translation (proper names the model translates), e.g. [ '\bBlue Lagoon\b', 'g', 'Plava Laguna' ] keeps a
 * hotel name the model translated.
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
		if ( ! Frontend::active() || ! Settings::browserBlog() ) {
			return;
		}
		wp_enqueue_script( 'mlg-browser-translate', MLG_URL . 'assets/browser-translate.js', array(), (string) filemtime( MLG_DIR . 'assets/browser-translate.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		$fixes = array();
		foreach ( (array) apply_filters( 'marclie_lingua/browser_fixes', array() ) as $fix ) {
			if ( is_array( $fix ) && 3 === count( $fix ) ) {
				$fixes[] = array_map( 'strval', array_values( $fix ) );
			}
		}
		$config = array(
			'source' => Settings::source()->code,
			'target' => Language::current(),
			'fixes'  => $fixes,
		);
		wp_add_inline_script( 'mlg-browser-translate', 'window.mlgBrowser = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
