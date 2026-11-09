<?php
/**
 * Translates a rendered HTML document of the source language with stored pairs (HTML5 parser of PHP 8.4,
 * `Dom\HTMLDocument`).
 *
 * Units: an element whose own text has words and whose descendants are all inline elements (Format::INLINE)
 * is one string, inline tags included (`<p>… <a>…</a> …</p>`). Any other element is walked: its own text
 * nodes are strings one by one, its children are walked. The attributes `placeholder`, `title`, `alt`,
 * `aria-label` (filter `marclie_lingua/attributes` adds more), the text of submit / button inputs, the `<title>`
 * and the description / Open Graph / Twitter metas are strings too.
 * Skipped: script, style, code, svg …, `[translate="no"]`, `.notranslate`, `[contenteditable]`, the admin bar and
 * the selectors of filter `marclie_lingua/skip_selectors`. Blog post parts — `[data-mlg-skip]` and the elements
 * the page's callback picks (constructor) — are skipped too and marked `lang="{source}" data-mlg-browser` for the
 * in-browser translation (Blog, assets/browser-translate.js).
 * A string without a stored translation keeps the source text. Internal links (`a[href]`, `area[href]`,
 * `form[action]`) get the language prefix (Router::url). When the head is translated, `<html lang>` and
 * `og:locale` become the target language; a page whose head is not (a single blog post) keeps the source ones.
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class HtmlTranslator {

	public const SKIP_TAGS = array( 'script', 'style', 'noscript', 'template', 'textarea', 'code', 'pre', 'kbd', 'samp', 'svg', 'math', 'iframe', 'object', 'canvas', 'video', 'audio' );

	/** Never translated (filter `marclie_lingua/skip_selectors` adds selectors). */
	public const SKIP = array( '[translate="no"]', '.notranslate', '[contenteditable]', '#wpadminbar' );

	/** Attributes holding visible copy (filter `marclie_lingua/attributes`). */
	public const ATTRS = array( 'placeholder', 'title', 'alt', 'aria-label' );

	/** Elements whose link gets the language prefix: selector => attribute. */
	public const LINKS = array(
		'a[href]'      => 'href',
		'area[href]'   => 'href',
		'form[action]' => 'action',
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

	private string $skip;

	/** Elements already classified as blog post parts (true) or not (false). */
	private \WeakMap $parts;

	/**
	 * @param \Closure(array<string, string>): array<string, string> $resolve hash => source of the page's
	 *        strings → hash => stored translation ('' or missing = keep the source text).
	 * @param Lang          $target        Language of the page.
	 * @param Lang          $source        Language of the content.
	 * @param bool          $translateHead Translate `<title>` / metas, set `<html lang>` and `og:locale` (false: a
	 *                                     single blog post left to the browser, whose content stays in the source).
	 * @param bool          $browserParts  `[data-mlg-skip]` and `$blog` elements are blog parts for the browser
	 *                                     translation (false: blog posts are translated like everything else).
	 * @param \Closure|null $blog          (\Dom\Element): bool — further blog post parts of this page.
	 * @param \Closure|null $finish        (\Dom\HTMLDocument, int $missing): void — called before serializing, with
	 *                                     the number of the page's texts that have no translation.
	 */
	public function __construct(
		private \Closure $resolve,
		private Lang $target,
		private Lang $source,
		private bool $translateHead = true,
		private bool $browserParts = true,
		private ?\Closure $blog = null,
		private ?\Closure $finish = null
	) {
		$this->attrs = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'marclie_lingua/attributes', self::ATTRS ) ) ) );
		$this->skip  = implode( ', ', array_unique( array_map( 'strval', (array) apply_filters( 'marclie_lingua/skip_selectors', self::SKIP ) ) ) );
		$this->parts = new \WeakMap();
	}

	public function translate( string $html ): string {
		$doc = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR, 'UTF-8' );

		if ( null !== $doc->body ) {
			$this->walk( $doc->body );
		}
		$title = $this->translateHead ? $doc->querySelector( 'head > title' ) : null;
		if ( null !== $title ) {
			$this->add( 'title', $title, Format::escapeText( $title->textContent ) );
		}
		foreach ( $this->translateHead ? self::HEAD : array() as $selector => $attr ) {
			foreach ( $doc->querySelectorAll( $selector ) as $el ) {
				$this->add( 'attr', $el, Format::escapeText( (string) $el->getAttribute( $attr ) ), $attr );
			}
		}

		$map     = $this->sources ? ( $this->resolve )( $this->sources ) : array();
		$missing = array();
		foreach ( $this->jobs as $job ) {
			$translation = $map[ $job['hash'] ] ?? '';
			if ( '' !== $translation ) {
				$this->apply( $doc, $job, $translation );
			} else {
				$missing[ $job['hash'] ] = true;
			}
		}

		$this->rewriteLinks( $doc );
		$root = $doc->documentElement;
		$root?->setAttribute( 'data-mlg-lang', $this->target->code );
		$root?->setAttribute( 'data-mlg-prefix', Router::basePath() . '/' . $this->target->code );
		if ( $this->translateHead ) {
			$root?->setAttribute( 'lang', $this->target->bcp47 );
			$doc->querySelector( 'meta[property="og:locale"]' )?->setAttribute( 'content', $this->target->ogLocale() );
		}
		if ( null !== $this->finish ) {
			( $this->finish )( $doc, count( $missing ) );
		}

		return $doc->saveHtml();
	}

	/** Internal links keep the page's language; `[data-mlg-lang]` (switcher) and `[data-mlg-no-rewrite]` keep theirs. */
	private function rewriteLinks( \Dom\HTMLDocument $doc ): void {
		foreach ( self::LINKS as $selector => $attr ) {
			foreach ( $doc->querySelectorAll( $selector ) as $el ) {
				if ( $el->hasAttribute( 'data-mlg-lang' ) || null !== $el->closest( '[data-mlg-no-rewrite]' ) ) {
					continue;
				}
				$value = (string) $el->getAttribute( $attr );
				$url   = Router::url( $value, $this->target->code );
				if ( $url !== $value ) {
					$el->setAttribute( $attr, $url );
				}
			}
		}
	}

	/** Blog post part: left to the browser translation. */
	private function blogPart( \Dom\Element $el ): bool {
		if ( ! $this->browserParts ) {
			return false;
		}
		if ( ! isset( $this->parts[ $el ] ) ) {
			$this->parts[ $el ] = $el->matches( '[data-mlg-skip]' ) || ( null !== $this->blog && ( $this->blog )( $el ) );
		}

		return $this->parts[ $el ];
	}

	private function skipped( \Dom\Element $el ): bool {
		return in_array( $el->localName, self::SKIP_TAGS, true ) || ( '' !== $this->skip && $el->matches( $this->skip ) ) || $this->blogPart( $el );
	}

	private function walk( \Dom\Element $el ): void {
		if ( $this->skipped( $el ) ) {
			if ( $this->blogPart( $el ) ) {
				$el->setAttribute( 'data-mlg-browser', '' );
				if ( ! $el->hasAttribute( 'lang' ) ) {
					$el->setAttribute( 'lang', $this->source->bcp47 );
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
