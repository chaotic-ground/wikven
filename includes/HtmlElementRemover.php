<?php

namespace MediaWiki\Extension\Wikven;

use Wikimedia\RemexHtml\HTMLData;
use Wikimedia\RemexHtml\Tokenizer\Attributes;
use Wikimedia\RemexHtml\Tokenizer\NullTokenHandler;
use Wikimedia\RemexHtml\Tokenizer\Tokenizer;

/**
 * Removes whole elements, subtree included, from raw HTML by byte range.
 *
 * RemexHtml's tokenizer -- the same one MediaWiki's own parser uses -- finds real tag boundaries
 * and nesting depth regardless of tag name, attribute quoting, or a ">" inside an attribute value.
 * Only the matched ranges are spliced out with substr(); everything else is untouched, byte for
 * byte, unlike a tree-builder-based rewrite that would reserialize the whole document.
 */
class HtmlElementRemover {
	/**
	 * @param string $html
	 * @param callable(string,array<string,string>):bool $matches Given a start tag's lowercase name
	 *   and its attributes (name => value), whether that element and its subtree should be removed.
	 */
	public static function remove(string $html, callable $matches): string {
		$handler = new class($matches) extends NullTokenHandler {
			/** @var callable(string,array<string,string>):bool */
			private $matches;
			private array $voidElements;
			private int $depth = 0;
			private ?int $activeDepth = null;
			private int $activeStart = 0;
			/** @var list<array{int,int}> */
			public array $ranges = [];

			public function __construct(callable $matches) {
				$this->matches = $matches;
				$this->voidElements = HTMLData::TAGS['void'];
			}

			/** @inheritDoc */
			public function startTag($name, Attributes $attrs, $selfClose, $sourceStart, $sourceLength) {
				// A void element (e.g. <img>, <input>) or a self-closed one (<path .../>) never gets a
				// matching end tag, so it neither opens a span nor adds to the nesting depth.
				$isVoid = $selfClose || isset($this->voidElements[$name]);

				if ($this->activeDepth === null && ( $this->matches )($name, $attrs->getValues())) {
					if ($isVoid) {
						$this->ranges[] = [$sourceStart, $sourceStart + $sourceLength];
					} else {
						$this->activeDepth = $this->depth;
						$this->activeStart = $sourceStart;
					}
				}

				if (!$isVoid) {
					$this->depth++;
				}
			}

			/** @inheritDoc */
			public function endTag($name, $sourceStart, $sourceLength) {
				if ($this->depth > 0) {
					$this->depth--;
				}
				// Depth returning to the level it was at when a match opened means this end tag is the
				// one that closes it: every intervening open has already been matched by a close.
				if ($this->activeDepth !== null && $this->depth === $this->activeDepth) {
					$this->ranges[] = [$this->activeStart, $sourceStart + $sourceLength];
					$this->activeDepth = null;
				}
			}
		};

		( new Tokenizer($handler, $html, ['skipPreprocess' => true]) )->execute();

		$result = $html;
		// Back to front, so an earlier range's offsets are still valid as later ones are spliced out.
		foreach (array_reverse($handler->ranges) as [$start, $end]) {
			$result = substr($result, 0, $start) . substr($result, $end);
		}
		return $result;
	}
}
