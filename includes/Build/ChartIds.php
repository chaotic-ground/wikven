<?php

namespace MediaWiki\Extension\Wikven\Build;

/** Renumbers the identifiers a chart renderer counts out per process rather than per chart. */
class ChartIds {
	/**
	 * Give a page's chart identifiers numbers of the page's own.
	 *
	 * The renderer numbers them from counters its process keeps, and a bake asks it once per skin
	 * pass, in parallel, so a chart comes back numbered differently each build (#781).
	 *
	 * @param string $html A rendered page.
	 * @return string The same page, numbered from zero.
	 */
	public static function renumber(string $html): string {
		if (!str_contains($html, '__zr')) {
			return $html;
		}
		$instances = [];
		$members = [];
		$renumbered = preg_replace_callback(
			// "<hash>__zr4-cls-36" and "<hash>__zr4-c0", which is every shape the renderer writes.
			'/__zr(\d+)-(cls-|c)(\d+)/',
			static function (array $match) use (&$instances, &$members): string {
				[, $instance, $kind, $number] = $match;
				$instances[$instance] ??= count($instances);
				$renamed = $instances[$instance];
				$members["$renamed-$kind"][$number] ??= count($members["$renamed-$kind"] ?? []);
				return '__zr' . $renamed . '-' . $kind . $members["$renamed-$kind"][$number];
			},
			$html
		);
		// Only on a pattern too big to compile or a subject that is not valid UTF-8, neither of
		// which a rendered page is; the page is left alone rather than blanked if it happens.
		return $renumbered ?? $html;
	}
}
