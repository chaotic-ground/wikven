<?php

namespace MediaWiki\Extension\Wikven;

/** Rewrites a page's output links: depth reparenting, and Special:MyLanguage resolution. */
class RelativeUrl {
	/**
	 * Add a "../" per level to every root-relative reference in a page moved $depth subdirectories down.
	 *
	 * Wikven links everything relative to the output root ("./x", or "../x" one level above it, as the
	 * non-main-skin canonical does). A subpage title such as "Manual/Config" caches to a flat file but
	 * is exported into a real "Manual/" directory; its references then need a "../" per level so links
	 * and files agree on a static host. Absolute, protocol-relative and data: URLs carry no leading
	 * "./" and are left alone. Covers href/src/srcset attributes and CSS url().
	 */
	public static function reparent(string $html, int $depth): string {
		if ($depth < 1) {
			return $html;
		}
		$up = str_repeat('../', $depth);
		$rebase = static function (string $dots) use ($up): string {
			return $dots === '..' ? $up . '../' : $up;
		};

		$html = preg_replace_callback(
			'#\b(href|src)="(\.\.?)/#',
			static function (array $m) use ($rebase): string {
				return $m[1] . '="' . $rebase($m[2]);
			},
			$html
		);
		$html = preg_replace_callback(
			'#\bsrcset="([^"]*)"#',
			static function (array $m) use ($rebase): string {
				return (
					'srcset="'
					. preg_replace_callback(
						'#(^|,\s*)(\.\.?)/#',
						static function (array $u) use ($rebase): string {
							return $u[1] . $rebase($u[2]);
						},
						$m[1]
					)
					. '"'
				);
			},
			$html
		);
		return preg_replace_callback(
			'#\burl\((["\']?)(\.\.?)/#',
			static function (array $m) use ($rebase): string {
				return 'url(' . $m[1] . $rebase($m[2]);
			},
			$html
		);
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
