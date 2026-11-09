<?php
/**
 * Option `mlg_settings`:
 * - `source`  code of the language the content is written in (the default language, served without URL prefix);
 * - `targets` codes of the translated languages, in switcher order (served under `/{code}/`);
 * - `booking` Marclie Booking integration: `auto` (on when Booking supports it), `on`, `off`;
 * - `index`   translated pages may be indexed (hreflang, canonical, sitemap) — else `noindex, follow`.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'mlg_settings';

	public const BOOKING_MODES = array( 'auto', 'on', 'off' );

	/** @var array{source: string, targets: list<string>, booking: string, index: bool}|null */
	private static ?array $cache = null;

	public static function hooks(): void {
		add_action( 'update_option_' . self::OPTION, array( self::class, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return array{source: string, targets: list<string>, booking: string, index: bool} */
	public static function defaults(): array {
		$source = Languages::fromLocale( (string) get_locale() );

		return array(
			'source'  => null !== $source ? $source->code : 'en',
			'targets' => array(),
			'booking' => 'auto',
			'index'   => true,
		);
	}

	/** @return array{source: string, targets: list<string>, booking: string, index: bool} */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, null );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{source: string, targets: list<string>, booking: string, index: bool}
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
		$booking = (string) ( $input['booking'] ?? 'auto' );

		return array(
			'source'  => $source,
			'targets' => $targets,
			'booking' => in_array( $booking, self::BOOKING_MODES, true ) ? $booking : 'auto',
			'index'   => ! empty( $input['index'] ),
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
}
