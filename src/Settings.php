<?php
/**
 * Option `mlg_settings`:
 * - `source`   code of the language the content is written in (the default language, served without URL prefix);
 * - `targets`  codes of the translated languages, in switcher order (served under `/{code}/`);
 * - `booking`  Marclie Booking integration: `auto` (on when Booking supports it), `on`, `off`;
 * - `index`    translated pages may be indexed (hreflang, canonical, sitemap) — else `noindex, follow`;
 * - `xdefault` language of the `hreflang="x-default"` link (searchers whose language the site does not have);
 * - `blog`     blog posts: `stored` (translated from stored pairs like any page, indexable) or `browser`
 *              (parts marked `data-mlg-skip` translated in the visitor's browser; translated versions noindex).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'mlg_settings';

	public const BOOKING_MODES = array( 'auto', 'on', 'off' );

	public const BLOG_MODES = array( 'stored', 'browser' );

	/** @var array{source: string, targets: list<string>, booking: string, index: bool, xdefault: string, blog: string}|null */
	private static ?array $cache = null;

	public static function hooks(): void {
		add_action( 'update_option_' . self::OPTION, array( self::class, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return array{source: string, targets: list<string>, booking: string, index: bool, xdefault: string, blog: string} */
	public static function defaults(): array {
		$source = Languages::fromLocale( (string) get_locale() );
		$code   = null !== $source ? $source->code : 'en';

		return array(
			'source'   => $code,
			'targets'  => array(),
			'booking'  => 'auto',
			'index'    => true,
			'xdefault' => $code,
			'blog'     => 'stored',
		);
	}

	/** @return array{source: string, targets: list<string>, booking: string, index: bool, xdefault: string, blog: string} */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, null );
			if ( is_array( $stored ) && ! array_key_exists( 'blog', $stored ) ) {
				$stored['blog'] = 'browser'; // saved by 0.2.0, which translated blog posts in the browser only
			}
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{source: string, targets: list<string>, booking: string, index: bool, xdefault: string, blog: string}
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$source   = sanitize_key( (string) ( $input['source'] ?? $defaults['source'] ) );
		if ( null === Languages::get( $source ) ) {
			$source = $defaults['source'];
		}
		$targets = array();
		foreach ( (array) ( $input['targets'] ?? array() ) as $code ) {
			$code = sanitize_key( (string) $code );
			if ( $code !== $source && null !== Languages::get( $code ) && ! in_array( $code, $targets, true ) ) {
				$targets[] = $code;
			}
		}
		$booking  = (string) ( $input['booking'] ?? 'auto' );
		$xdefault = sanitize_key( (string) ( $input['xdefault'] ?? $source ) );
		$blog     = (string) ( $input['blog'] ?? 'stored' );

		return array(
			'source'   => $source,
			'targets'  => $targets,
			'booking'  => in_array( $booking, self::BOOKING_MODES, true ) ? $booking : 'auto',
			'index'    => ! empty( $input['index'] ),
			'xdefault' => in_array( $xdefault, $targets, true ) ? $xdefault : $source,
			'blog'     => in_array( $blog, self::BLOG_MODES, true ) ? $blog : 'stored',
		);
	}

	/** @param array<string, mixed> $settings */
	public static function save( array $settings ): void {
		update_option( self::OPTION, self::sanitize( $settings ), true );
		self::flush();
	}

	public static function source(): Lang {
		return Languages::get( self::get()['source'] ) ?? new Lang( 'en', 'en_GB', 'en-GB', 'English', 'EN' );
	}

	/** @return list<Lang> */
	public static function targets(): array {
		return array_values( array_filter( array_map( array( Languages::class, 'get' ), self::get()['targets'] ) ) );
	}

	/** @return list<string> */
	public static function targetCodes(): array {
		return array_map( static fn( Lang $l ): string => $l->code, self::targets() );
	}

	/** @return list<Lang> Source first, then the targets. */
	public static function languages(): array {
		return array_merge( array( self::source() ), self::targets() );
	}

	public static function isTarget( string $code ): bool {
		return in_array( $code, self::targetCodes(), true );
	}

	public static function lang( string $code ): ?Lang {
		foreach ( self::languages() as $lang ) {
			if ( $lang->code === $code ) {
				return $lang;
			}
		}

		return null;
	}

	public static function booking(): string {
		return self::get()['booking'];
	}

	public static function index(): bool {
		return self::get()['index'];
	}

	/** Language of the `hreflang="x-default"` link. */
	public static function xDefault(): Lang {
		return self::lang( self::get()['xdefault'] ) ?? self::source();
	}

	/** Blog posts translated in the visitor's browser (not stored, not indexed in translated languages). */
	public static function browserBlog(): bool {
		return 'browser' === self::get()['blog'];
	}
}
