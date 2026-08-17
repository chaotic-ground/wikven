<?php

namespace MediaWiki\Extension\Wikven;

/** Rewrites a page's output links: depth reparenting, and Special:MyLanguage resolution. */
class RelativeUrl {
	/** The assignment MediaWiki writes a page's JavaScript config into, opening brace included. */
	private const CONFIG_ASSIGNMENT = 'RLCONF={';

	/**
	 * Add a "../" per level to every root-relative reference in a page moved $depth subdirectories down.
	 *
	 * Wikven links everything relative to the output root ("./x", or "../x" one level above it, as the
	 * non-main-skin canonical does). A subpage title such as "Manual/Config" caches to a flat file but
	 * is exported into a real "Manual/" directory; its references then need a "../" per level so links
	 * and files agree on a static host. Absolute, protocol-relative and data: URLs carry no leading
	 * "./" and are left alone. Covers href/src/srcset attributes, CSS url(), and the page's own
	 * JavaScript config (see reparentConfigVars()).
	 *
	 * Each candidate is required to sit inside a real "<tag ...>" span (or, for url(), inside a
	 * <style> block too): MediaWiki escapes preformatted text with htmlspecialchars(..., ENT_NOQUOTES),
	 * which turns a documentation example's own "<a href=\"./intro\">" into "&lt;a href=\"./intro\"&gt;"
	 * -- the quotes survive, but neither escaped angle bracket is a real "<" or ">", so nothing there
	 * reads as a tag span and the example's href is correctly left alone.
	 *
	 * The printfooter's link arrives without the "./" it was written with, so it is rebased on its
	 * own; see rebasePrintFooter() below.
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

		return self::rebasePrintFooter($html, $up);
	}

	/**
	 * Rebase the root-relative URLs a page carries in its JavaScript config.
	 *
	 * Title::getLocalURL() answers "./Page.html" here (Hooks\Main::onGetLocalURL), and a URL that
	 * shape means "from the output root" -- a claim only true of a page sitting at the root. In an
	 * attribute the passes above correct it; the same string handed to the client through
	 * mw.config rides in the page's RLCONF object, where nothing was correcting it. Core writes one
	 * itself for a redirect (wgInternalRedirectTargetUrl), and any extension that asks a Title for
	 * its URL and exports it as a config var writes another.
	 *
	 * Only that object is rewritten, and in it only strings that begin with "./" or "../". The rest
	 * of the page cannot be treated this way, since a bare string carries no marker telling a URL
	 * of ours from a path a page merely shows -- these very docs write "./Page.html" as text.
	 * RLCONF is a span MediaWiki wrote, not the wikitext, and everything in it is data for scripts.
	 *
	 * What this cannot reach is a local URL baked into a ResourceLoader module's bundle: one file
	 * serves every page at every depth, so there is no depth to correct it by, and an extension
	 * shipping one has to anchor it itself (#425).
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
	 * The offset just past the "}" closing the object literal that opens at $open, or null if the
	 * text runs out first.
	 *
	 * Braces inside a string are not counted, so a config value holding one -- a message with a
	 * "{{PLURAL:}}" left in it, a regular expression -- does not end the object early, and the
	 * object's own end is found however many nested objects it holds. The alternative, a non-greedy
	 * match up to the next "};", stops at the first config value that contains that pair.
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
	 * Skin::printSource() builds it from Title::getCanonicalURL() and expands the result a second
	 * time; each expansion runs UrlUtils' dot-segment removal over the URL, which drops the leading
	 * "./" that Hooks\Main::onGetLocalURL wrote. What survives is still a path from the output root
	 * -- it just no longer says so, so the passes above walk past it, and from a page exported into
	 * a subdirectory it resolves inside that subdirectory and answers 404. A top-level page is
	 * unaffected, its link being right from the root by accident.
	 *
	 * A bare relative href is exactly the shape that carries no marker telling a link of ours from
	 * a path a page merely shows, which is why only core's printfooter div is rebased: everything
	 * in there is the line core has just built out of this page's own URL.
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
	 * Everything else names something the depth of the page cannot move: an absolute URL, a path
	 * from the host root, a fragment or query on the page itself, or -- for a link that kept its
	 * marker, and so was rebased by reparent() already -- a path the "./" prefix has spoken for.
	 * A namespaced title such as "File:Oven.jpg.html" reads like a scheme and is not one, hence
	 * the test for "://" rather than for a colon.
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
	 * Resolve Translate's "Special:MyLanguage/Target" links (that special page is not exported) to a
	 * static target: "Target/<lang>.html" when a translation for the page's language exists, else the
	 * source "Target.html". The link's existing relative prefix (already depth-correct after reparent)
	 * and any "#fragment" are kept, so the target stays reachable from any page depth.
	 *
	 * @param string $html
	 * @param string|null $lang The page's language, or null for a source page (always the source target).
	 * @param callable(string):bool $hasTranslation Whether target page $1 has a translation in $lang.
	 */
	public static function resolveMyLanguage(string $html, ?string $lang, callable $hasTranslation): string {
		return preg_replace_callback(
			'~(href="(?:\.\./)*(?:\./)?)Special:MyLanguage/([^"#]+)\.html(#[^"]*)?"~',
			static function (array $m) use ($lang, $hasTranslation): string {
				$base = $lang !== null && $hasTranslation($m[2]) ? "/$lang.html" : '.html';
				return $m[1] . $m[2] . $base . ( $m[3] ?? '' ) . '"';
			},
			$html
		);
	}
}
