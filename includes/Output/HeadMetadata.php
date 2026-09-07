<?php

namespace MediaWiki\Extension\Wikven\Output;

/**
 * Where in a rendered page a reference is read by something that never saw the page.
 *
 * The body's pictures are read by a browser that already has the page; an og:image is read by a
 * crawler handed the tag alone. Where the shape no longer says which is which, where the
 * reference sits is all that does.
 */
final class HeadMetadata {
	/** @var list<array{int, int}> Start and end offset of each span, in the page it was read from. */
	private readonly array $spans;

	// Written out rather than promoted, as in SiteUrl: a promoted property leaves the constructor
	// body empty, and mago writes an empty body as "{}" where phpcs wants the brace on its own line.
	private function __construct(array $spans) {
		$this->spans = $spans;
	}

	/** The spans of $html that are read away from it. */
	public static function of(string $html): self {
		if (!preg_match('~<head\b[^>]*>~i', $html, $open, PREG_OFFSET_CAPTURE)) {
			return new self([]);
		}
		$from = $open[0][1] + strlen($open[0][0]);
		// A page with no </head> is not one this build wrote, but half a head is still a head: take
		// what there is rather than decide the page has no metadata at all.
		$closed = stripos($html, '</head>', $from);
		$head = substr($html, $from, ( $closed === false ? strlen($html) : $closed ) - $from);

		$spans = [];
		foreach ([
			'~<meta\b[^>]*>~i',
			'~<script\b[^>]*\btype="application/ld\+json"[^>]*>.*?</script>~is'
		] as $pattern) {
			preg_match_all($pattern, $head, $matches, PREG_OFFSET_CAPTURE);
			foreach ($matches[0] as $match) {
				$spans[] = [$from + $match[1], $from + $match[1] + strlen($match[0])];
			}
		}

		return new self($spans);
	}

	/**
	 * Whether the reference at $offset is one of them.
	 *
	 * @param int $offset A byte offset into the page this was read from.
	 */
	public function holds(int $offset): bool {
		foreach ($this->spans as [$start, $end]) {
			if ($offset >= $start && $offset < $end) {
				return true;
			}
		}
		return false;
	}
}
