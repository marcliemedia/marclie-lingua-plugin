<?php
/**
 * String format of a stored pair (pure functions).
 *
 * A source string is the text of one translatable unit with its inline elements written as bare tags:
 * `Rezervirajte izravno: kodom <strong>DIRECT5</strong> pri plaćanju …`. White space (incl. &nbsp;) is
 * collapsed to single spaces; `<` and `>` in text are written `&lt;` / `&gt;`; `<br>` is a line break.
 * The translation uses the same tags (any order); on output each tag takes the attributes of the source
 * element with the same name, in order, so links, classes and icons survive.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Format {

	/** Inline elements that stay inside one unit (written as bare tags). */
	public const INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'wbr' );

	/** Elements without content. */
	public const VOID = array( 'br', 'wbr' );

	/** Collapses white space (incl. no-break space) to single spaces and trims. */
	public static function normalize( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}\x{202F}]+/u', ' ', $text ) );
	}

	public static function escapeText( string $text ): string {
		return str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $text );
	}

	public static function hash( string $source ): string {
		return md5( $source );
	}

	/** True when the string has a letter (numbers, symbols and blank strings are not translated). */
	public static function hasWords( string $text ): bool {
		return 1 === preg_match( '/\p{L}/u', $text );
	}

	/**
	 * Tokens of a stored string: text (decoded), open / close tags and void tags. Unknown tag names stay text.
	 *
	 * @return list<array{0: 'text'|'open'|'close'|'void', 1: string}>
	 */
	public static function tokens( string $s ): array {
		$out   = array();
		$parts = preg_split( '#(</?[a-z][a-z0-9]*\s*/?>)#i', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $parts as $part ) {
			if ( preg_match( '#^<(/?)([a-z][a-z0-9]*)\s*/?>$#i', $part, $m ) && in_array( strtolower( $m[2] ), self::INLINE, true ) ) {
				$name  = strtolower( $m[2] );
				$out[] = in_array( $name, self::VOID, true ) ? array( 'void', $name ) : array( '' === $m[1] ? 'open' : 'close', $name );
				continue;
			}
			$out[] = array( 'text', str_replace( array( '&lt;', '&gt;' ), array( '<', '>' ), $part ) );
		}

		return $out;
	}

	/** Text only (tags dropped), for attributes and plain text nodes. */
	public static function plain( string $s ): string {
		$text = '';
		foreach ( self::tokens( $s ) as [ $type, $value ] ) {
			if ( 'text' === $type ) {
				$text .= $value;
			} elseif ( 'void' === $type ) {
				$text .= ' ';
			}
		}

		return self::normalize( $text );
	}

	/**
	 * Tag name => count (opening and void tags).
	 *
	 * @return array<string, int>
	 */
	public static function tagCounts( string $s ): array {
		$counts = array();
		foreach ( self::tokens( $s ) as [ $type, $name ] ) {
			if ( 'open' === $type || 'void' === $type ) {
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
		}
		ksort( $counts );

		return $counts;
	}

	/** A translation is valid when it uses exactly the source's tags and every tag it opens is closed. */
	public static function valid( string $source, string $translation ): bool {
		if ( self::tagCounts( $source ) !== self::tagCounts( $translation ) ) {
			return false;
		}
		$stack = array();
		foreach ( self::tokens( $translation ) as [ $type, $name ] ) {
			if ( 'open' === $type ) {
				$stack[] = $name;
			} elseif ( 'close' === $type && array_pop( $stack ) !== $name ) {
				return false;
			}
		}

		return array() === $stack;
	}
}
