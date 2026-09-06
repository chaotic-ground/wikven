<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Where in a rendered page a reference is read by something that never saw the page.
 *
 * A picture in the body and the same picture named in the head are one file and two references. The
 * body's is read by a browser that already has the page, so the copy beside it resolves, and the
 * export stays a directory anyone can move or open from disk. The head's -- an og:image, the image
 * of a schema.org block -- is read by a crawler that was handed the tag and nothing else, and a
 * path beside a page it never fetched resolves against whatever that crawler happens to be doing.
 *
 * Which of the two a reference is cannot always be read off the reference. Where this wiki stored
 * the file, the shape says it: MediaWiki hands the body File::getUrl() and metadata
 * File::getFullUrl(), so a scheme and a host arriving here is MediaWiki having already answered
 * (see UploadReference). A file a foreign repository serves is a whole URL to both, because the
 * repository is somewhere else whoever is asking -- the distinction is gone before the page is
 * written, and the only thing that still knows is where in the page the reference sits. That is
 * this.
 *
 * Two spans count. A <meta> element: everything a crawler is told about a page arrives as one, and
 * nothing rendering the page fetches its content=. A schema.org <script type="application/ld+json">
 * block: the same claims, in JSON. What is deliberately not here is the rest of the head -- a
 * stylesheet, a favicon, a preload -- which the browser fetches while rendering this page, exactly
 * as it fetches the body's pictures, and which a whole URL would pin to one host for nothing.
 *
 * Only the head is searched. A <meta> has no business outside it, and a page that talks about HTML
 * is full of text that looks like tags: MediaWiki writes that through htmlspecialchars(), so
 * "&lt;meta" could not be mistaken for a tag here anyway, but a rule that holds only because of
 * someone else's escaping is one worth not depending on.
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
