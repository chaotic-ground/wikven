<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * Unit-level staleness of a translation against its source page.
 *
 * Both the source (a <translate>-marked page) and each translation carry the same
 * <!--T:n--> unit markers; a translation marker also records the source unit hash it
 * was synced to (<!--T:n @<hash>-->). A translation unit is stale when the current
 * source unit no longer hashes to that stamp. This is pure string work, shared by the
 * CI check and the build-time materialize step, so both judge staleness identically.
 */
class StalenessComputer {
	public const OK = 'ok';
	public const STALE = 'stale';
	public const UNTRANSLATED = 'untranslated';
	public const ORPHAN = 'orphan';

	private const HASH_LENGTH = 8;

	/**
	 * Verbatim spans whose contents are shown, not parsed. A <!--T:n--> or <translate> that appears
	 * inside one -- as on the page documenting page translation -- is an example, not real markup, so
	 * unit splitting and marking skip it. This mirrors the tags TranslationSource::isTranslatable and
	 * #239 treat as verbatim.
	 */
	private const VERBATIM_TAGS = 'syntaxhighlight|source|nowiki|pre';

	/** Short content hash of a source unit; the value carried as @<hash> in a translation marker. */
	public static function hashUnit(string $unitText): string {
		return substr(hash('sha256', self::normalize($unitText)), 0, self::HASH_LENGTH);
	}

	/**
	 * Assign <!--T:n--> markers to the still-unmarked units inside a page's <translate> blocks.
	 *
	 * Units are the blank-line-separated blocks Translate segments on; an already-marked unit keeps
	 * its number and new ones continue from the highest on the page. The marker goes on its own line
	 * before the unit, which both splitUnits() and Translate's own re-parse honour. Idempotent.
	 */
	public static function mark(string $text): string {
		$verbatim = self::verbatimRanges($text);

		// Only count real markers when picking the next number; a <!--T:n--> shown inside an example
		// must not push the numbering of the actual units along.
		preg_match_all('/<!--T:(\d+)/', $text, $existing, PREG_OFFSET_CAPTURE);
		$numbers = [];
		foreach ($existing[0] as $i => $marker) {
			if (!self::insideVerbatim($marker[1], $verbatim)) {
				$numbers[] = (int)$existing[1][$i][0];
			}
		}
		$next = $numbers === [] ? 1 : max($numbers) + 1;

		return preg_replace_callback(
			'#(<translate(?:\s[^>]*)?>)(.*?)(</translate>)#s',
			static function (array $block) use (&$next, $verbatim): string {
				// A <translate> pair inside a verbatim span is an example; leave it exactly as written.
				if (self::insideVerbatim($block[0][1], $verbatim)) {
					return $block[0][0];
				}
				$units = preg_split('/(\n[ \t]*\n)/', $block[2][0], -1, PREG_SPLIT_DELIM_CAPTURE);
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
				return $block[1][0] . $marked . $block[3][0];
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Split page text into units keyed by their <!--T:n--> marker id.
	 *
	 * @return array<string,array{hash:?string,text:string}> id => [synced-source hash (translations only), unit text]
	 */
	public static function splitUnits(string $text): array {
		$pattern = '/<!--T:(?<id>[A-Za-z0-9]+)(?:\s+@(?<hash>[0-9a-f]{' . self::HASH_LENGTH . '}))?\s*-->/';
		if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			return [];
		}

		// Drop markers that sit inside a verbatim span: they are shown as an example, not real units.
		// A real unit's body still spans any verbatim block that falls between two real markers, so an
		// existing translation's boundaries -- and its @<hash> stamps -- are unchanged.
		$verbatim = self::verbatimRanges($text);
		$markers = [];
		foreach ($matches as $marker) {
			if (!self::insideVerbatim($marker[0][1], $verbatim)) {
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
	 * Compare a source page and one translation, unit by unit.
	 *
	 * @return list<array{id:string,status:string}> source units in order, then any orphans
	 */
	public static function analyze(string $sourceText, ?string $translationText): array {
		$source = self::splitUnits($sourceText);
		$translation = $translationText === null ? [] : self::splitUnits($translationText);

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
	 */
	public static function restamp(string $sourceText, string $translationText): string {
		$source = self::splitUnits($sourceText);
		return preg_replace_callback(
			'/<!--T:(?<id>[A-Za-z0-9]+)(?:\s+@[0-9a-f]{' . self::HASH_LENGTH . '})?\s*-->/',
			static function ($marker) use ($source) {
				$id = $marker['id'];
				if (!isset($source[$id])) {
					return $marker[0];
				}
				return '<!--T:' . $id . ' @' . self::hashUnit($source[$id]['text']) . '-->';
			},
			$translationText
		);
	}

	/**
	 * Build (or extend) a translation skeleton: a <!--T:n--> marker with an empty body for every
	 * source unit not already present. Empty bodies read as "not yet translated"; the translator
	 * fills them and runs stamp. An existing translation is kept intact with only new-unit markers
	 * appended, so it is safe to re-run as the source gains units.
	 */
	public static function scaffold(string $sourceText, ?string $existingTranslation = null): string {
		$existing = $existingTranslation === null ? [] : self::splitUnits($existingTranslation);
		$additions = '';
		foreach (self::splitUnits($sourceText) as $id => $unit) {
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
	 * Byte ranges of the verbatim spans in a page, each as [start, endExclusive].
	 *
	 * A self-contained regex rather than MediaWiki's tag extractor, so this class stays pure string
	 * work usable outside a MediaWiki bootstrap; it recognises paired and self-closing forms with
	 * optional attributes, in any letter case, which is all the docs pages use.
	 *
	 * An unclosed verbatim tag runs to the end of the page, matching how MediaWiki renders it. That
	 * is the safer reading: the alternative -- treating the span as absent -- would hand the rest of
	 * the page back to the marker scan, turning example text into counted units.
	 *
	 * @return list<array{int,int}>
	 */
	private static function verbatimRanges(string $text): array {
		// The attribute run is lazy so a self-closing tag closes on its own "/>" instead of the
		// attributes swallowing the slash and leaving the tag to match as unclosed.
		$pattern = '#<(' . self::VERBATIM_TAGS . ')(?:\s[^>]*?)?(?:/\s*>|>.*?</\1\s*>|>.*)#is';
		if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
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
		foreach ($ranges as [$start, $end]) {
			if ($offset >= $start && $offset < $end) {
				return true;
			}
		}
		return false;
	}

	/** Strip <translate> wrapper tags and surrounding whitespace so the hash tracks unit content only. */
	private static function normalize(string $unitText): string {
		return trim(preg_replace('/<\/?translate[^>]*>/', '', $unitText));
	}
}
