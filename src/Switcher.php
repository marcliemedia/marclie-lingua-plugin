<?php
/**
 * Language switcher: a dropdown that works on any theme (button with a globe icon and the current language,
 * a list of the site's languages in their own names, the current one checked). Every link points to this page —
 * query included — in the other language (Router::switchUrl()). Nothing is printed while the site has fewer than
 * two languages.
 *
 * Placed with the shortcode `[marclie_lingua_switcher display="code|name" class=""]` (`display`: the button shows
 * the language code or its name; `class`: extra wrapper classes, e.g. `mlg-switch--align-right` to open the menu
 * from the right edge) or with the Bricks element "Language switcher" (src/Bricks/SwitcherElement.php, loaded by
 * Bricks only).
 *
 * The wrapper is `translate="no"`: the language names stay as they are on every page; the hidden "Language" label
 * of the button is translated through the `marclie_lingua_text` filter. `assets/switcher.css` (every frontend
 * page while a target language exists; selectors in `:where()`, design tokens as custom properties) styles it,
 * `assets/switcher.js` (enqueued only when a switcher is printed) opens and closes it.
 *
 * Icons: Phosphor Icons (regular weight: globe, caret-down, check), MIT License, https://phosphoricons.com.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Switcher {

	public const SHORTCODE = 'marclie_lingua_switcher';

	private const HANDLE = 'mlg-switcher';

	private const ICONS = array(
		'globe' => 'M128,24h0A104,104,0,1,0,232,128,104.12,104.12,0,0,0,128,24Zm88,104a87.61,87.61,0,0,1-3.33,24H174.16a157.44,157.44,0,0,0,0-48h38.51A87.61,87.61,0,0,1,216,128ZM102,168H154a115.11,115.11,0,0,1-26,45A115.27,115.27,0,0,1,102,168Zm-3.9-16a140.84,140.84,0,0,1,0-48h59.88a140.84,140.84,0,0,1,0,48ZM40,128a87.61,87.61,0,0,1,3.33-24H81.84a157.44,157.44,0,0,0,0,48H43.33A87.61,87.61,0,0,1,40,128ZM154,88H102a115.11,115.11,0,0,1,26-45A115.27,115.27,0,0,1,154,88Zm52.33,0H170.71a135.28,135.28,0,0,0-22.3-45.6A88.29,88.29,0,0,1,206.37,88ZM107.59,42.4A135.28,135.28,0,0,0,85.29,88H49.63A88.29,88.29,0,0,1,107.59,42.4ZM49.63,168H85.29a135.28,135.28,0,0,0,22.3,45.6A88.29,88.29,0,0,1,49.63,168Zm98.78,45.6a135.28,135.28,0,0,0,22.3-45.6h35.66A88.29,88.29,0,0,1,148.41,213.6Z',
		'caret' => 'M213.66,101.66l-80,80a8,8,0,0,1-11.32,0l-80-80A8,8,0,0,1,53.66,90.34L128,164.69l74.34-74.35a8,8,0,0,1,11.32,11.32Z',
		'check' => 'M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z',
	);

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'init', array( self::class, 'registerBricksElement' ), 11 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'style' ) );
	}

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		wp_register_style( self::HANDLE, MLG_URL . 'assets/switcher.css', array(), (string) filemtime( MLG_DIR . 'assets/switcher.css' ) );
		wp_register_script( self::HANDLE, MLG_URL . 'assets/switcher.js', array(), (string) filemtime( MLG_DIR . 'assets/switcher.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	/** Bricks element "Language switcher"; its file is only loaded by Bricks (it extends \Bricks\Element). */
	public static function registerBricksElement(): void {
		if ( class_exists( '\Bricks\Elements' ) ) {
			\Bricks\Elements::register_element( MLG_DIR . 'src/Bricks/SwitcherElement.php' );
		}
	}

	/** Small stylesheet on every frontend page while the site is multilingual (no flash when a switcher appears). */
	public static function style(): void {
		if ( Settings::targets() ) {
			wp_enqueue_style( self::HANDLE );
		}
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'display' => 'code',
				'class'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return self::render(
			array(
				'display' => sanitize_key( $atts['display'] ),
				'class'   => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) ) ),
			)
		);
	}

	/**
	 * Complete switcher: wrapper + button + menu ('' with fewer than two languages).
	 *
	 * @param array{display?: string, class?: string, icon?: bool, align?: string, base?: string} $args
	 *   display `code` (default) or `name` on the button; class extra wrapper classes; icon show the globe
	 *   (default true); align `left` (default) or `right` menu edge; base URL to link instead of this request.
	 */
	public static function render( array $args = array() ): string {
		$inner = self::inner( $args );
		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<div class="%1$s" translate="no" data-mlg-switch>%2$s</div>',
			esc_attr( implode( ' ', self::classes( $args ) ) ),
			$inner
		);
	}

	/**
	 * Wrapper classes: `mlg-switch`, the alignment modifier and the extra classes.
	 *
	 * @param array<string, mixed> $args see render()
	 * @return list<string>
	 */
	public static function classes( array $args ): array {
		$classes = array( 'mlg-switch' );
		if ( 'right' === ( $args['align'] ?? 'left' ) ) {
			$classes[] = 'mlg-switch--align-right';
		}
		$extra = trim( (string) ( $args['class'] ?? '' ) );

		return '' === $extra ? $classes : array_merge( $classes, array( $extra ) );
	}

	/**
	 * Button and menu, for a wrapper printed by the caller (the Bricks element's root, which must carry the
	 * classes(), `translate="no"` and `data-mlg-switch`). Enqueues the script.
	 *
	 * @param array<string, mixed> $args see render()
	 */
	public static function inner( array $args = array() ): string {
		$languages = Settings::languages();
		if ( count( $languages ) < 2 ) {
			return '';
		}
		$current = Language::currentLang();
		$byName  = 'name' === ( $args['display'] ?? 'code' );
		$base    = (string) ( $args['base'] ?? '' );
		$menuId  = wp_unique_id( 'mlg-switch-' );

		$items = '';
		foreach ( $languages as $lang ) {
			$active = $lang->code === $current->code;
			$href   = '' !== $base ? Router::url( $base, $lang->code ) : Router::switchUrl( $lang->code );
			$items .= sprintf(
				'<li class="mlg-switch__item"><a class="mlg-switch__option%1$s" href="%2$s" lang="%3$s" hreflang="%3$s"%4$s data-mlg-lang="%5$s">%6$s%7$s</a></li>',
				$active ? ' is-active' : '',
				esc_url( $href ),
				esc_attr( $lang->bcp47 ),
				$active ? ' aria-current="true"' : '',
				esc_attr( $lang->code ),
				esc_html( $lang->name ),
				$active ? self::icon( 'check', 'mlg-switch__check' ) : ''
			);
		}

		wp_enqueue_script( self::HANDLE );

		return sprintf(
			'<button class="mlg-switch__toggle" type="button" aria-expanded="false" aria-controls="%1$s">%2$s<span class="mlg-switch__sr">%3$s: </span><span class="mlg-switch__current">%4$s</span>%5$s</button><ul class="mlg-switch__menu" id="%1$s" hidden>%6$s</ul>',
			esc_attr( $menuId ),
			( $args['icon'] ?? true ) ? self::icon( 'globe', 'mlg-switch__icon' ) : '',
			esc_html( (string) apply_filters( 'marclie_lingua_text', __( 'Language', 'marclie-lingua' ) ) ),
			esc_html( $byName ? $current->name : $current->label ),
			self::icon( 'caret', 'mlg-switch__caret' ),
			$items
		);
	}

	private static function icon( string $name, string $class ): string {
		return sprintf(
			'<svg class="%1$s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="%2$s"/></svg>',
			esc_attr( $class ),
			self::ICONS[ $name ]
		);
	}
}
