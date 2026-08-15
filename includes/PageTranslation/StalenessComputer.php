<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

use InvalidArgumentException;

/**
 * Unit-level staleness of a translation against its source page.
 *
 * Both the source (a <translate>-marked page) and each translation carry the same
 * <!--T:n--> unit markers; a translation marker also records the source unit hash it
 * was synced to (<!--T:n @<hash>-->). A translation unit is stale when the current
 * source unit no longer hashes to that stamp. This is pure string work, shared by the
 * CI check and the build-time materialize step, so both judge staleness identically.
 *
 * A page's title is a unit too, under the reserved id "title": it behaves like every other unit
 * except that its source text is the page's own title rather than a span of the wikitext, so the
 * callers that know which page they are looking at pass that title in.
 */
class StalenessComputer {
	public const OK = 'ok';
	public const STALE = 'stale';
	public const UNTRANSLATED = 'untranslated';
	public const ORPHAN = 'orphan';

	/**
	 * Reserved unit id for a page's translated title, wikven's spelling of Translate's own
	 * "Page display title" unit.
	 *
	 * Like Translate's, this unit is not part of the page contents: its source text is the page's
	 * own title, which the source wikitext never repeats, so it exists only in a translation file
	 * ("<!--T:title @<hash>-->" followed by the translated title).
	 *
	 * mark() only ever hands out numbers, so the tooling never produces this id; the unit scan does
	 * accept it, though, so a source page could still carry a hand-written "<!--T:title-->". That
	 * unit would collide with the page title and be lost, so sourceUnits() rejects it outright and
	 * usesReservedId() lets the check report it.
	 */
	public const TITLE_UNIT_ID = 'title';

	private const HASH_LENGTH = 8;

	/**
	 * Verbatim spans in a translation file, whose contents are shown rather than read as units. A
	 * translation carries bare markers and no <translate> block, so it is wikven's own format and
	 * this is wikven's own rule; source pages go by Translate's, which is blockRanges().
	 */
	private const VERBATIM_TAGS = 'syntaxhighlight|source|nowiki|pre';

	/**
	 * A <translate> block, its contents captured. Exactly the two spellings parse() accepts: it takes
	 * no other attribute, so anything else is a tag containsMarkup() offers for marking and parse()
	 * then refuses, which checkTranslations reports rather than this quietly reading as a block.
	 */
	private const TRANSLATE_BLOCK = '#(<translate(?: nowrap)?>)(.*?)(</translate>)#s';

	/** What TranslatablePageParser::armourNowiki() hides: exactly this, no attributes, no other tag. */
	private const ARMOURED_NOWIKI = '#<nowiki>.*?</nowiki>#s';

	/** Short content hash of a source unit; the value carried as @<hash> in a translation marker. */
	public static function hashUnit(string $unitText): string {
		return substr(hash('sha256', self::normalize($unitText)), 0, self::HASH_LENGTH);
	}

	/**
	 * Assign <!--T:n--> markers to the still-unmarked units inside a page's <translate> blocks.
	 *
	 * Units are the blank-line-separated blocks Translate segments on; an already-marked unit keeps
	 * its number and new ones continue from the highest on the page. The marker goes on its own line
	 * before the unit, which both the unit scan and Translate's own re-parse honour. Idempotent.
	 */
	public static function mark(string $text): string {
		$blocks = self::blockRanges($text);

		// Only a marker inside a block is a unit, so only those numbers are taken; one shown elsewhere
		// on the page must not push the real units along.
		preg_match_all('/<!--T:(\d+)/', $text, $existing, PREG_OFFSET_CAPTURE);
		$numbers = [];
		foreach ($existing[0] as $i => $marker) {
			if (self::containingRange($marker[1], $blocks) !== null) {
				$numbers[] = (int)$existing[1][$i][0];
			}
		}
		$next = $numbers === [] ? 1 : max($numbers) + 1;

		$marked = '';
		$offset = 0;
		foreach ($blocks as [$start, $end]) {
			$marked .=
				substr($text, $offset, $start - $offset) . self::markBlock(substr($text, $start, $end - $start), $next);
			$offset = $end;
		}
		return $marked . substr($text, $offset);
	}

	/** Number the still-unmarked units of one <translate> block's contents, continuing from $next. */
	private static function markBlock(string $contents, int &$next): string {
		// The two newlines must be adjacent, matching Translate's own sectioniser
		// ('~(^\s*|\s*\n\n\s*|\s*$)~'): a line of spaces or tabs between them is still the same unit to
		// Translate, and marking it as two would put a <!--T:n--> mid-unit, which Translate rejects
		// (pt-shake-position).
		$units = preg_split('/(\n\n)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);
		$marked = '';
		foreach ($units as $index => $segment) {
			// Odd indices are the blank-line separators between units; keep them verbatim.
			if (( $index % 2 ) === 1 || trim($segment) === '' || str_contains($segment, '<!--T:')) {
				$marked .= $segment;
				continue;
			}
			preg_match('/^(\s*)(.*)$/s', $segment, $parts);
			$marked .= $parts[1] . '<!--T:' . $next++ . "-->\n" . $parts[2];
		}
		return $marked;
	}

	/**
	 * Split a translation file into units keyed by their <!--T:n--> marker id.
	 *
	 * @return array<string,array{hash:?string,text:string}> id => [synced-source hash, unit text]
	 */
	public static function translationUnits(string $text): array {
		$verbatim = self::verbatimRanges($text);
		return self::unitsAt($text, static function (int $offset) use ($verbatim): bool {
			return !self::insideVerbatim($offset, $verbatim);
		});
	}

	/**
	 * Split a source page into units keyed by their <!--T:n--> marker id.
	 *
	 * @return array<string,array{hash:?string,text:string}> id => [null, unit text]
	 */
	private static function sourcePageUnits(string $text): array {
		$blocks = self::blockRanges($text);
		return self::unitsAt($text, static function (int $offset) use ($blocks): bool {
			return self::containingRange($offset, $blocks) !== null;
		});
	}

	/**
	 * Units keyed by marker id, counting only the markers whose offset $keep accepts. A unit's body
	 * runs to the next counted marker, so a skipped one leaves the surrounding unit whole.
	 *
	 * @param string $text
	 * @param callable(int):bool $keep
	 * @return array<string,array{hash:?string,text:string}>
	 */
	private static function unitsAt(string $text, callable $keep): array {
		$pattern = '/<!--T:(?<id>[A-Za-z0-9]+)(?:\s+@(?<hash>[0-9a-f]{' . self::HASH_LENGTH . '}))?\s*-->/';
		if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			return [];
		}

		$markers = [];
		foreach ($matches as $marker) {
			if ($keep($marker[0][1])) {
				$markers[] = $marker;
			}
		}

		$units = [];
		$count = count($markers);
		for ($i = 0; $i < $count; $i++) {
			$marker = $markers[$i];
			$bodyStart = $marker[0][1] + strlen($marker[0][0]);
			$bodyEnd = ( $i + 1 ) < $count ? $markers[$i + 1][0][1] : strlen($text);
			// An unstamped marker (source page, or a not-yet-stamped translation) has no hash group.
			$stamped = isset($marker['hash']) && $marker['hash'][1] !== -1;
			$units[$marker['id'][0]] = [
				'hash' => $stamped ? $marker['hash'][0] : null,
				'text' => substr($text, $bodyStart, $bodyEnd - $bodyStart)
			];
		}
		return $units;
	}

	/**
	 * Whether a source page marks one of its own units with the reserved title id.
	 *
	 * No source page may: that id belongs to the page title, which is not part of the wikitext, so
	 * a unit wearing it has nowhere to go. Reported by the check, and refused by sourceUnits().
	 */
	public static function usesReservedId(string $sourceText): bool {
		return isset(self::sourcePageUnits($sourceText)[self::TITLE_UNIT_ID]);
	}

	/**
	 * Every unit a translation of this page owes: the page-title unit, when the page has a
	 * translatable title, followed by the <!--T:n--> units of its wikitext.
	 *
	 * The title unit comes first, as it does in Translate, and carries the page title as its source
	 * text, so renaming the page restamps to a different hash and the translated title goes stale.
	 *
	 * @param string $sourceText
	 * @param ?string $pageTitle The page's own title, or null for a page with no translatable title.
	 * @return array<string,array{hash:?string,text:string}> id => [synced-source hash (null here), unit text]
	 * @throws InvalidArgumentException If the source page marks a unit with the reserved title id.
	 */
	public static function sourceUnits(string $sourceText, ?string $pageTitle = null): array {
		$units = self::sourcePageUnits($sourceText);
		// Refused whether or not this page has a translatable title: merging the two would drop one
		// of them silently, and a page that gains a translatable title later must not lose a unit
		// the moment it does.
		if (isset($units[self::TITLE_UNIT_ID])) {
			$reserved = self::TITLE_UNIT_ID;
			throw new InvalidArgumentException(
				"<!--T:{$reserved}--> is reserved for the page title and cannot mark a unit of a source page;"
				. ' renumber that unit.'
			);
		}
		if ($pageTitle === null) {
			return $units;
		}
		return [self::TITLE_UNIT_ID => ['hash' => null, 'text' => $pageTitle]] + $units;
	}

	/**
	 * Compare a source page and one translation, unit by unit.
	 *
	 * @param string $sourceText
	 * @param ?string $translationText
	 * @param ?string $pageTitle The page's own title, when its title is translatable too.
	 * @return list<array{id:string,status:string}> source units in order, then any orphans
	 */
	public static function analyze(string $sourceText, ?string $translationText, ?string $pageTitle = null): array {
		$source = self::sourceUnits($sourceText, $pageTitle);
		$translation = $translationText === null ? [] : self::translationUnits($translationText);

		$result = [];
		foreach ($source as $id => $unit) {
			if (!isset($translation[$id]) || trim($translation[$id]['text']) === '') {
				// Absent, or present but empty (a scaffolded unit not yet filled in).
				$status = self::UNTRANSLATED;
			} elseif ($translation[$id]['hash'] !== self::hashUnit($unit['text'])) {
				$status = self::STALE;
			} else {
				$status = self::OK;
			}
			$result[] = ['id' => (string)$id, 'status' => $status];
		}
		foreach ($translation as $id => $unit) {
			if (!isset($source[$id])) {
				$result[] = ['id' => (string)$id, 'status' => self::ORPHAN];
			}
		}
		return $result;
	}

	/**
	 * Rewrite a translation's marker stamps to the current source unit hashes.
	 *
	 * Run after translating so every unit reads as up to date; orphan units (no matching source
	 * unit) keep their marker untouched for the author to resolve.
	 *
	 * @param string $sourceText
	 * @param string $translationText
	 * @param ?string $pageTitle The page's own title, when its title is translatable too.
	 */
	public static function restamp(string $sourceText, string $translationText, ?string $pageTitle = null): string {
		$source = self::sourceUnits($sourceText, $pageTitle);
		// A marker the translation merely shows is prose, not a unit: stamping one would edit the
		// sentence around it whenever its id happened to match a real unit.
		$verbatim = self::verbatimRanges($translationText);
		return preg_replace_callback(
			'/<!--T:(?<id>[A-Za-z0-9]+)(?:\s+@[0-9a-f]{' . self::HASH_LENGTH . '})?\s*-->/',
			static function ($marker) use ($source, $verbatim) {
				$id = $marker['id'][0];
				if (!isset($source[$id]) || self::insideVerbatim($marker[0][1], $verbatim)) {
					return $marker[0][0];
				}
				return '<!--T:' . $id . ' @' . self::hashUnit($source[$id]['text']) . '-->';
			},
			$translationText,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Build (or extend) a translation skeleton: a <!--T:n--> marker with an empty body for every
	 * source unit not already present. Empty bodies read as "not yet translated"; the translator
	 * fills them and runs stamp. An existing translation is kept intact with only new-unit markers
	 * appended, so it is safe to re-run as the source gains units.
	 *
	 * @param string $sourceText
	 * @param ?string $existingTranslation
	 * @param ?string $pageTitle The page's own title, when its title is translatable too.
	 */
	public static function scaffold(
		string $sourceText,
		?string $existingTranslation = null,
		?string $pageTitle = null
	): string {
		$existing = $existingTranslation === null ? [] : self::translationUnits($existingTranslation);
		$additions = '';
		foreach (self::sourceUnits($sourceText, $pageTitle) as $id => $unit) {
			if (!isset($existing[$id])) {
				$additions .= '<!--T:' . $id . "-->\n\n";
			}
		}
		if ($additions === '') {
			return $existingTranslation ?? '';
		}
		if ($existingTranslation === null || trim($existingTranslation) === '') {
			return $additions;
		}
		return rtrim($existingTranslation, "\n") . "\n\n" . $additions;
	}

	/**
	 * Byte ranges of the <translate> block contents in a source page, each as [start, endExclusive].
	 *
	 * Translate armours <nowiki> before it looks for a block, so a whole pair shown in one is
	 * invisible to it; blanking the span with a same-length filler keeps every offset the page's own.
	 * A <nowiki> merely inside a block still yields its markers, because Translate unarmours a block's
	 * contents before segmenting them.
	 *
	 * @return list<array{int,int}>
	 */
	private static function blockRanges(string $text): array {
		$masked = preg_replace_callback(
			self::ARMOURED_NOWIKI,
			static function (array $span): string {
				return str_repeat("\x00", strlen($span[0]));
			},
			$text
		);

		$ranges = [];
		$offset = 0;
		$length = strlen($masked);
		while ($offset < $length && preg_match(self::TRANSLATE_BLOCK, $masked, $match, PREG_OFFSET_CAPTURE, $offset)) {
			$ranges[] = [$match[2][1], $match[2][1] + strlen($match[2][0])];
			$offset = $match[0][1] + strlen($match[0][0]);
		}
		return $ranges;
	}

	/**
	 * Byte ranges of the verbatim spans in a page, each as [start, endExclusive].
	 *
	 * A self-contained regex rather than MediaWiki's tag extractor, so this class stays pure string
	 * work usable outside a MediaWiki bootstrap; it recognises paired and self-closing forms with
	 * optional attributes, in any letter case, which is all the docs pages use.
	 *
	 * An unclosed verbatim tag runs to the end of the page, matching how MediaWiki renders it. That
	 * is the safer reading: the alternative -- treating the span as absent -- would hand the rest of
	 * the page back to the marker scan, turning example text into counted units. But an HTML comment
	 * is inert to MediaWiki's parser, so a tag name it merely mentions -- e.g. "<!-- don't wrap this
	 * in <nowiki> -->" -- must not seed a span in the first place: read as a real opener, that "runs
	 * to the end of the page" rule would swallow every marker after it. Scanning match by match,
	 * instead of in one preg_match_all pass, lets a match like that be skipped in place, so a genuine
	 * span later in the page is still found.
	 *
	 * @return list<array{int,int}>
	 */
	private static function verbatimRanges(string $text): array {
		$comments = self::commentRanges($text);
		// The attribute run is lazy so a self-closing tag closes on its own "/>" instead of the
		// attributes swallowing the slash and leaving the tag to match as unclosed.
		$pattern = '#<(' . self::VERBATIM_TAGS . ')(?:\s[^>]*?)?(?:/\s*>|>.*?</\1\s*>|>.*)#is';

		$ranges = [];
		$offset = 0;
		$length = strlen($text);
		while ($offset < $length && preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
			$start = $match[0][1];
			$comment = self::containingRange($start, $comments);
			if ($comment !== null) {
				// A false opener inside a comment; resume right after it instead of accepting a
				// match that would otherwise run to the end of the page.
				$offset = $comment[1];
				continue;
			}
			$end = $start + strlen($match[0][0]);
			$ranges[] = [$start, $end];
			$offset = $end;
		}
		return $ranges;
	}

	/** Byte ranges of the HTML comments in a page, each as [start, endExclusive]. */
	private static function commentRanges(string $text): array {
		if (!preg_match_all('/<!--.*?-->/s', $text, $matches, PREG_OFFSET_CAPTURE)) {
			return [];
		}
		$ranges = [];
		foreach ($matches[0] as $match) {
			$ranges[] = [$match[1], $match[1] + strlen($match[0])];
		}
		return $ranges;
	}

	/**
	 * @param int $offset
	 * @param list<array{int,int}> $ranges
	 */
	private static function insideVerbatim(int $offset, array $ranges): bool {
		return self::containingRange($offset, $ranges) !== null;
	}

	/**
	 * @param int $offset
	 * @param list<array{int,int}> $ranges
	 * @return ?array{int,int} The range in $ranges containing $offset, or null.
	 */
	private static function containingRange(int $offset, array $ranges): ?array {
		foreach ($ranges as $range) {
			if ($offset >= $range[0] && $offset < $range[1]) {
				return $range;
			}
		}
		return null;
	}

	/** Strip <translate> wrapper tags and surrounding whitespace so the hash tracks unit content only. */
	private static function normalize(string $unitText): string {
		return trim(preg_replace('/<\/?translate[^>]*>/', '', $unitText));
	}
}
