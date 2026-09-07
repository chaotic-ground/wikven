<?php

namespace MediaWiki\Extension\Wikven\Output;

/** Rewrites a page's output links: depth reparenting, and Special:MyLanguage resolution. */
class RelativeUrl {
	/** The assignment MediaWiki writes a page's JavaScript config into, opening brace included. */
	private const CONFIG_ASSIGNMENT = 'RLCONF={';

	/**
	 * Add a "../" per level to every root-relative reference in a page moved $depth subdirectories down.
	 *
	 * A subpage such as "Manual/Config" caches flat but is exported into a real directory. Covers
	 * href/src/srcset, CSS url(), RLCONF and the schema.org block.
	 */
	public static function reparent(string $html, int $depth): string {
		if ($depth < 1) {
			return $html;
		}
		$up = str_repeat('../', $depth);
		$rebase = static function (string $dots) use ($up): string {
			return $dots === '..' ? $up . '../' : $up;
		};

		$tags = self::tagRanges($html);
		$html = preg_replace_callback(
			'#(\s)(href|src)="(\.\.?)/#',
			static function (array $m) use ($rebase, $tags): string {
				if (!self::insideAny($m[0][1], $tags)) {
					return $m[0][0];
				}
				return $m[1][0] . $m[2][0] . '="' . $rebase($m[3][0]);
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		$tags = self::tagRanges($html);
		$html = preg_replace_callback(
			'#(\s)srcset="([^"]*)"#',
			static function (array $m) use ($rebase, $tags): string {
				if (!self::insideAny($m[0][1], $tags)) {
					return $m[0][0];
				}
				return (
					$m[1][0]
					. 'srcset="'
					. preg_replace_callback(
						'#(^|,\s*)(\.\.?)/#',
						static function (array $u) use ($rebase): string {
							return $u[1] . $rebase($u[2]);
						},
						$m[2][0]
					)
					. '"'
				);
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		$styleContexts = array_merge(self::tagRanges($html), self::ranges($html, '#<style\b[^>]*>.*?</style>#is'));
		$html = preg_replace_callback(
			'#url\((["\']?)(\.\.?)/#',
			static function (array $m) use ($rebase, $styleContexts): string {
				if (!self::insideAny($m[0][1], $styleContexts)) {
					return $m[0][0];
				}
				return 'url(' . $m[1][0] . $rebase($m[2][0]);
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		$html = self::reparentConfigVars($html, $rebase);
		$html = self::reparentJsonLd($html, $rebase);

		return self::rebasePrintFooter($html, $up);
	}

	/**
	 * Rebase the root-relative URLs a page carries in its JavaScript config.
	 *
	 * "./Page.html" means "from the output root", a claim only true of a page at the root. In an
	 * attribute the passes above correct it; in RLCONF nothing was.
	 *
	 * @param string $html A rendered page.
	 * @param callable(string):string $rebase Takes the matched "." or "..", returns its replacement.
	 */
	private static function reparentConfigVars(string $html, callable $rebase): string {
		$offset = 0;
		while (true) {
			$start = strpos($html, self::CONFIG_ASSIGNMENT, $offset);
			if ($start === false) {
				break;
			}
			$open = $start + strlen(self::CONFIG_ASSIGNMENT) - 1;
			$end = self::objectEnd($html, $open);
			if ($end === null) {
				break;
			}
			$rebased = preg_replace_callback(
				'#"(\.\.?)/#',
				static function (array $m) use ($rebase): string {
					return '"' . $rebase($m[1]);
				},
				substr($html, $open, $end - $open)
			);
			$html = substr_replace($html, $rebased, $open, $end - $open);
			$offset = $open + strlen($rebased);
		}
		return $html;
	}

	/**
	 * Rebase the root-relative URLs a page carries in its schema.org block.
	 *
	 * The claim reparentConfigVars() answers, one escaping further along: json_encode() spells
	 * "./assets/..." as ".\/assets\/...", which none of the passes above sees.
	 *
	 * @param string $html A rendered page.
	 * @param callable(string):string $rebase Takes the matched "." or "..", returns its replacement.
	 */
	private static function reparentJsonLd(string $html, callable $rebase): string {
		return preg_replace_callback(
			'~<script type="application/ld\+json">.*?</script>~s',
			static function (array $block) use ($rebase): string {
				return preg_replace_callback(
					'~"(\.\.?)\\\\/~',
					static function (array $m) use ($rebase): string {
						return '"' . str_replace('/', '\\/', $rebase($m[1]));
					},
					$block[0]
				);
			},
			$html
		);
	}

	/**
	 * The offset just past the "}" closing the object literal that opens at $open, or null if the
	 * text runs out first.
	 *
	 * Braces inside a string are not counted, so a config value holding one does not end the
	 * object early.
	 */
	private static function objectEnd(string $text, int $open): ?int {
		$depth = 0;
		$inString = false;
		$length = strlen($text);
		for ($i = $open; $i < $length; $i++) {
			$char = $text[$i];
			if ($inString) {
				if ($char === '\\') {
					$i++;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
			} elseif ($char === '{') {
				$depth++;
			} elseif ($char === '}') {
				$depth--;
				if ($depth === 0) {
					return $i + 1;
				}
			}
		}
		return null;
	}

	/**
	 * Rebase the printfooter's "Retrieved from" link, the one reference that reaches a page having
	 * lost the "./" the rest of this class goes by.
	 *
	 * Skin::printSource() expands the URL a second time, and dot-segment removal drops the "./".
	 */
	private static function rebasePrintFooter(string $html, string $up): string {
		return preg_replace_callback(
			'~<div[^>]*\bclass="printfooter"[^>]*>.*?</div>~s',
			static function (array $footer) use ($up): string {
				return preg_replace_callback(
					'~(\shref=")([^"]*)"~',
					static function (array $href) use ($up): string {
						if (!self::isRootRelative($href[2])) {
							return $href[0];
						}
						return $href[1] . $up . $href[2] . '"';
					},
					$footer[0]
				);
			},
			$html
		);
	}

	/**
	 * Whether $href is a bare path from the output root -- what the printfooter's link is left as.
	 *
	 * Everything else names something the page's depth cannot move. A title such as
	 * "File:Oven.jpg.html" reads like a scheme, hence the test for "://".
	 */
	private static function isRootRelative(string $href): bool {
		if ($href === '') {
			return false;
		}
		foreach (['/', '#', '?', './', '../'] as $prefix) {
			if (str_starts_with($href, $prefix)) {
				return false;
			}
		}
		return !str_contains($href, '://');
	}

	/** Byte ranges of the raw "<tag ...>" spans in $html, each as [start, endExclusive]. */
	private static function tagRanges(string $html): array {
		return self::ranges($html, '#<[a-zA-Z][^<>]*>#s');
	}

	/**
	 * @return list<array{int,int}>
	 */
	private static function ranges(string $text, string $pattern): array {
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
	private static function insideAny(int $offset, array $ranges): bool {
		foreach ($ranges as [$start, $end]) {
			if ($offset >= $start && $offset < $end) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve Translate's "Special:MyLanguage/Target" links, that special page not being exported,
	 * to a static target: "Target/<lang>.html" where a translation exists, else "Target.html".
	 *
	 * The canonical spelling alone reaches here, both sides writing the marker that way.
	 *
	 * @param string $html
	 * @param string|null $lang The page's language, or null for a source page (always the source target).
	 * @param callable(string):bool $hasTranslation Whether target link $1 has a translation in $lang.
	 */
	public static function resolveMyLanguage(string $html, ?string $lang, callable $hasTranslation): string {
		return preg_replace_callback(
			'~(href="(?:\.\./)*(?:\./)?)Special(?::|%253A)MyLanguage/([^"#]+)\.html(#[^"]*)?"~',
			static function (array $m) use ($lang, $hasTranslation): string {
				$base = $lang !== null && $hasTranslation($m[2]) ? "/$lang.html" : '.html';
				return $m[1] . $m[2] . $base . ( $m[3] ?? '' ) . '"';
			},
			$html
		);
	}
}
