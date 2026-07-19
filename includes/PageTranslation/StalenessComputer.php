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
		preg_match_all('/<!--T:(\d+)/', $text, $existing);
		$next = $existing[1] === [] ? 1 : max(array_map('intval', $existing[1])) + 1;

		return preg_replace_callback(
			'#(<translate(?:\s[^>]*)?>)(.*?)(</translate>)#s',
			static function (array $block) use (&$next): string {
				$units = preg_split('/(\n[ \t]*\n)/', $block[2], -1, PREG_SPLIT_DELIM_CAPTURE);
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
				return $block[1] . $marked . $block[3];
			},
			$text
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

		$units = [];
		$count = count($matches);
		for ($i = 0; $i < $count; $i++) {
			$marker = $matches[$i];
			$bodyStart = $marker[0][1] + strlen($marker[0][0]);
			$bodyEnd = ( $i + 1 ) < $count ? $matches[$i + 1][0][1] : strlen($text);
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

	/** Strip <translate> wrapper tags and surrounding whitespace so the hash tracks unit content only. */
	private static function normalize(string $unitText): string {
		return trim(preg_replace('/<\/?translate[^>]*>/', '', $unitText));
	}
}
