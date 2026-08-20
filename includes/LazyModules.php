<?php

namespace MediaWiki\Extension\Wikven;

/**
 * The modules MediaWiki loads by looking at a rendered page, decided here instead of in a browser.
 *
 * Core queues most of a page's JavaScript while rendering it, and the build reads that queue out of
 * the HTML. Two features are not queued. mediawiki.page.ready waits until the page is in a browser,
 * searches the DOM, and asks load.php for what it finds:
 *
 *     if ( config.collapsible ) {
 *         $collapsible = $content.find( '.mw-collapsible' );
 *         if ( $collapsible.length ) { modules.push( 'jquery.makeCollapsible' ); }
 *     }
 *     if ( config.sortable ) {
 *         $sortable = $content.find( 'table.sortable' );
 *         if ( $sortable.length ) { modules.push( 'jquery.tablesorter' ); }
 *     }
 *     if ( modules.length ) { mw.loader.using( modules ).then( ... ); }
 *
 * An export has no load.php, so that request fails and the feature is silently absent: no toggle on
 * a collapsible, no sorting on a sortable table, and content marked mw-collapsed shown rather than
 * hidden (#483). Nothing else about the page is wrong, which is what makes it easy to miss.
 *
 * The decision itself is kept: same flags, same selectors, same "only if the page has one". Only its
 * timing moves, from the browser to the build, because that is the one thing a static site cannot
 * do the way MediaWiki does. Whatever core adds to that list next is another line in MODULES.
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
	 * Read off the markup rather than parsed, because the caller has one string per page and a DOM
	 * for each would cost more than the question is worth. The class is matched as a whole token, so
	 * "mw-collapsible" does not answer for "mw-collapsible-content" -- the toggle and content
	 * wrappers core generates carry names that a substring match would take for the thing itself.
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
