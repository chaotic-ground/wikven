<?php

namespace MediaWiki\Extension\Wikven\Output;

/**
 * The modules MediaWiki loads by looking at a rendered page, decided here rather than in a browser.
 *
 * Core queues most of a page's JavaScript while rendering, and the build reads that queue out of
 * the HTML. mediawiki.page.ready instead waits for a browser and asks load.php, which an export
 * has none of, so the feature was silently absent (#483).
 */
class LazyModules {
	/**
	 * mediawiki.page.ready config flag => the module it loads, and the selector it looks for.
	 *
	 * 'tag' narrows the search to one element, as 'table.sortable' does; null matches any element,
	 * as '.mw-collapsible' does.
	 */
	public const MODULES = [
		'collapsible' => ['module' => 'jquery.makeCollapsible', 'class' => 'mw-collapsible', 'tag' => null],
		'sortable' => ['module' => 'jquery.tablesorter', 'class' => 'sortable', 'tag' => 'table']
	];

	/**
	 * The lazy modules a rendered page needs.
	 *
	 * @param string $html One page's rendered HTML.
	 * @param array $readyConfig mediawiki.page.ready's config, for the flags that gate each feature.
	 * @return string[] Module names, in the order MODULES lists them.
	 */
	public static function forPage(string $html, array $readyConfig): array {
		$needed = [];
		foreach (self::MODULES as $flag => $feature) {
			if (( $readyConfig[$flag] ?? false ) && self::hasClass($html, $feature['class'], $feature['tag'])) {
				$needed[] = $feature['module'];
			}
		}
		return $needed;
	}

	/**
	 * Whether the HTML carries an element with this class, optionally only on one tag.
	 *
	 * Matched as a whole token, so "mw-collapsible" does not answer for "mw-collapsible-content".
	 *
	 * @param string $html
	 * @param string $class The class to look for.
	 * @param ?string $tag Only look at this element, or null for any.
	 */
	public static function hasClass(string $html, string $class, ?string $tag = null): bool {
		$open = $tag === null ? '<[a-zA-Z][^>]*' : '<' . preg_quote($tag, '/') . '\b[^>]*';
		$pattern = '/' . $open . '\sclass\s*=\s*(["\'])([^"\']*)\1/i';
		if (!preg_match_all($pattern, $html, $matches)) {
			return false;
		}
		foreach ($matches[2] as $value) {
			if (in_array($class, preg_split('/\s+/', trim($value)) ?: [], true)) {
				return true;
			}
		}
		return false;
	}
}
