<?php
/**
 * Translates a rendered Croatian HTML document with stored pairs (HTML5 parser of PHP 8.4, `Dom\HTMLDocument`).
 *
 * Units: an element whose own text has words and whose descendants are all inline elements (Format::INLINE)
 * is one string, inline tags included (`<p>… <a>…</a> …</p>`). Any other element is walked: its own text
 * nodes are strings one by one, its children are walked. Attributes `placeholder`, `title`, `alt`,
 * `aria-label`, the w-site.js message attributes (and the text of submit / button inputs), the `<title>` and the
 * description / Open Graph / Twitter metas are strings too.
 * Skipped: script, style, code, svg …, `[translate="no"]`, `.notranslate`, the admin bar and the Marclie Booking
 * widgets (they print their own English). Blog post parts — `[data-mlg-skip]` and the elements the page's
 * callback picks (constructor) — are skipped too and marked `lang="hr" data-mlg-browser` for the in-browser
 * translation (Blog, assets/browser-translate.js).
 * A string without a stored translation keeps its Croatian. On an English page `<html lang>` and `og:locale`
 * become English; a page that is not (a single blog post) keeps its Croatian head and language.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class HtmlTranslator {

	public const SKIP_TAGS = array( 'script', 'style', 'noscript', 'template', 'textarea', 'code', 'pre', 'kbd', 'samp', 'svg', 'math', 'iframe', 'object', 'canvas', 'video', 'audio' );

	public const SKIP = '[translate="no"], .notranslate, [contenteditable], #wpadminbar, [data-mbk-widget], [class*="brxe-mbk-"], [data-mbk-lazy], '
		. '[data-mbk-search-form], [data-mbk-bookbox], [data-mbk-bookbar], [data-mbk-catalog], [data-mbk-calendar], '
		. '[data-mbk-checkout], [data-mbk-confirmation], [data-mbk-pay-balance], [data-mbk-account]';

	/** Attributes holding visible copy (incl. the w-site.js messages: filter chips, result counts, FAQ search, copy buttons). */
	public const ATTRS = array(
		'placeholder', 'title', 'alt', 'aria-label', 'data-label', 'data-result-label', 'data-result-label-one',
		'data-result-label-zero', 'data-status-one', 'data-status-many', 'data-copy-done', 'data-copy-fail', 'data-demo-note',
	);

	/** Head elements with copy: selector => attribute. */
	public const HEAD = array(
		'meta[name="description"], meta[property="og:title"], meta[property="og:description"], meta[property="og:image:alt"], meta[name="twitter:title"], meta[name="twitter:description"]' => 'content',
	);

	/** @var list<array{kind: string, node: \Dom\Node, hash: string, attr?: string}> */
	private array $jobs = array();

	/** @var array<string, string> hash => source */
	private array $sources = array();

	/** @var list<string> */
	private array $attrs;

	/** Elements already classified as blog post parts (true) or not (false). */
	private \WeakMap $parts;

	/**
	 * @param \Closure(array<string, string>): array<string, string> $resolve hash => source of the page's
	 *        strings → hash => stored translation ('' or missing = keep the Croatian).
	 * @param string        $locale  `<html lang>` of the translated page.
	 * @param bool          $english The page is English: translate `<title>` / metas, set `<html lang>` and `og:locale`.
	 * @param \Closure|null $blog    (\Dom\Element): bool — further blog post parts of this page.
	 */
	public function __construct( private \Closure $resolve, private string $locale = 'en-GB', private bool $english = true, private ?\Closure $blog = null ) {
		$this->attrs = (array) apply_filters( 'marclie_lingua/attributes', self::ATTRS );
		$this->parts = new \WeakMap();
	}

	public function translate( string $html ): string {
		$doc = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR, 'UTF-8' );

		if ( null !== $doc->body ) {
			$this->walk( $doc->body );
		}
		$title = $this->english ? $doc->querySelector( 'head > title' ) : null;
		if ( null !== $title ) {
			$this->add( 'title', $title, Format::escapeText( $title->textContent ) );
		}
		foreach ( $this->english ? self::HEAD : array() as $selector => $attr ) {
			foreach ( $doc->querySelectorAll( $selector ) as $el ) {
				$this->add( 'attr', $el, Format::escapeText( (string) $el->getAttribute( $attr ) ), $attr );
			}
		}

		$map = $this->sources ? ( $this->resolve )( $this->sources ) : array();
		foreach ( $this->jobs as $job ) {
			$translation = $map[ $job['hash'] ] ?? '';
			if ( '' !== $translation ) {
				$this->apply( $doc, $job, $translation );
			}
		}

		if ( $this->english ) {
			$doc->documentElement?->setAttribute( 'lang', $this->locale );
			$doc->querySelector( 'meta[property="og:locale"]' )?->setAttribute( 'content', str_replace( '-', '_', $this->locale ) );
		}

		return $doc->saveHtml();
	}

	/** Blog post part: left to the browser translation. */
	private function blogPart( \Dom\Element $el ): bool {
		if ( ! isset( $this->parts[ $el ] ) ) {
			$this->parts[ $el ] = $el->matches( '[data-mlg-skip]' ) || ( null !== $this->blog && ( $this->blog )( $el ) );
		}

		return $this->parts[ $el ];
	}

	private function skipped( \Dom\Element $el ): bool {
		return in_array( $el->localName, self::SKIP_TAGS, true ) || $el->matches( self::SKIP ) || $this->blogPart( $el );
	}

	private function walk( \Dom\Element $el ): void {
		if ( $this->skipped( $el ) ) {
			if ( $this->blogPart( $el ) ) {
				$el->setAttribute( 'data-mlg-browser', '' );
				if ( ! $el->hasAttribute( 'lang' ) ) {
					$el->setAttribute( 'lang', Language::SOURCE );
				}
			}
			return;
		}
		$this->attributes( $el );
		if ( $this->isUnit( $el ) ) {
			$this->add( 'unit', $el, $this->serialize( $el ) );
			foreach ( $el->querySelectorAll( '*' ) as $inner ) {
				$this->attributes( $inner );
			}
			return;
		}
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof \Dom\Text ) {
				$this->add( 'text', $child, Format::escapeText( $child->data ) );
			} elseif ( $child instanceof \Dom\Element ) {
				$this->walk( $child );
			}
		}
	}

	/** Own text with words + inline-only descendants. */
	private function isUnit( \Dom\Element $el ): bool {
		$ownText = false;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \Dom\Text && Format::hasWords( $child->data ) ) {
				$ownText = true;
				break;
			}
		}

		return $ownText && $this->inlineOnly( $el );
	}

	private function inlineOnly( \Dom\Element $el ): bool {
		foreach ( $el->children as $child ) {
			if ( ! in_array( $child->localName, Format::INLINE, true ) || $this->skipped( $child ) || ! $this->inlineOnly( $child ) ) {
				return false;
			}
		}

		return true;
	}

	/** Children as a source string: text escaped, inline elements as bare tags. */
	private function serialize( \Dom\Node $parent ): string {
		$out = '';
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \Dom\Text ) {
				$out .= Format::escapeText( $child->data );
			} elseif ( $child instanceof \Dom\Element ) {
				$name = $child->localName;
				$out .= in_array( $name, Format::VOID, true ) ? '<' . $name . '>' : '<' . $name . '>' . $this->serialize( $child ) . '</' . $name . '>';
			}
		}

		return $out;
	}

	private function attributes( \Dom\Element $el ): void {
		foreach ( $this->attrs as $attr ) {
			if ( $el->hasAttribute( $attr ) ) {
				$this->add( 'attr', $el, Format::escapeText( (string) $el->getAttribute( $attr ) ), $attr );
			}
		}
		if ( 'input' === $el->localName && in_array( strtolower( (string) $el->getAttribute( 'type' ) ), array( 'submit', 'button', 'reset' ), true ) && $el->hasAttribute( 'value' ) ) {
			$this->add( 'attr', $el, Format::escapeText( (string) $el->getAttribute( 'value' ) ), 'value' );
		}
	}

	private function add( string $kind, \Dom\Node $node, string $raw, string $attr = '' ): void {
		$source = Format::normalize( $raw );
		if ( '' === $source || ! Format::hasWords( Format::plain( $source ) ) ) {
			return;
		}
		$hash                   = Format::hash( $source );
		$this->sources[ $hash ] = $source;
		$job                    = array( 'kind' => $kind, 'node' => $node, 'hash' => $hash );
		if ( '' !== $attr ) {
			$job['attr'] = $attr;
		}
		$this->jobs[] = $job;
	}

	/** @param array{kind: string, node: \Dom\Node, hash: string, attr?: string} $job */
	private function apply( \Dom\HTMLDocument $doc, array $job, string $translation ): void {
		$node = $job['node'];
		switch ( $job['kind'] ) {
			case 'attr':
				$node->setAttribute( $job['attr'], Format::plain( $translation ) );
				return;
			case 'title':
				$node->textContent = Format::plain( $translation );
				return;
			case 'text':
				[ $lead, $tail ] = self::edges( $node->data );
				$node->data      = $lead . Format::plain( $translation ) . $tail;
				return;
		}

		// unit: rebuild the children from the translation, each tag cloned from the source element of that name
		[ $lead, $tail ] = self::edges( $node->textContent );
		$pool            = array();
		foreach ( $node->querySelectorAll( '*' ) as $inner ) {
			$pool[ $inner->localName ][] = $inner->cloneNode( false );
		}
		$node->replaceChildren();
		if ( '' !== $lead ) {
			$node->append( $lead );
		}
		$stack = array( $node );
		foreach ( Format::tokens( $translation ) as [ $type, $value ] ) {
			$top = end( $stack );
			if ( 'text' === $type ) {
				$top->append( $value );
			} elseif ( 'close' === $type ) {
				if ( count( $stack ) > 1 && $top->localName === $value ) {
					array_pop( $stack );
				}
			} else {
				$el = ! empty( $pool[ $value ] ) ? array_shift( $pool[ $value ] ) : $doc->createElement( $value );
				$top->append( $el );
				if ( 'open' === $type ) {
					$stack[] = $el;
				}
			}
		}
		if ( '' !== $tail ) {
			$node->append( $tail );
		}
	}

	/**
	 * Leading and trailing white space of a text.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function edges( string $text ): array {
		preg_match( '/^[\s\x{00A0}]*/u', $text, $lead );
		preg_match( '/[\s\x{00A0}]*$/u', $text, $tail );
		$lead = $lead[0] ?? '';

		return array( $lead, strlen( $lead ) >= strlen( $text ) ? '' : ( $tail[0] ?? '' ) );
	}
}
