<?php

namespace MediaWiki\Extension\Wikven\Build;

/** Removes the per-build stamps MediaWiki leaves in a rendered page. */
class BuildStamps {
	/**
	 * What a page records about the request that rendered it, rather than about the page.
	 *
	 * The ids are blanked rather than deleted, because mw.config.get() on a missing key returns
	 * null where scripts expect a number.
	 */
	private const STAMPS = [
		// A fresh id per request, and how long that request took.
		'/"wgRequestId":"[0-9a-f]+"/' => '"wgRequestId":""',
		'/"wgBackendResponseTime":\d+/' => '"wgBackendResponseTime":0',
		// Page and revision ids: stable within a bake, but not across bakes (see #269).
		'/"wgArticleId":\d+/' => '"wgArticleId":0',
		'/"wgRelevantArticleId":\d+/' => '"wgRelevantArticleId":0',
		'/"wgCurRevisionId":\d+/' => '"wgCurRevisionId":0',
		'/"wgRevisionId":\d+/' => '"wgRevisionId":0',
		// OutputTransform\Stages\RenderDebugInfo: a uuid per render, and the parser cache's own note.
		'/\n?<!-- Render ID [0-9a-f-]+ -->\n?/' => "\n",
		'/\n?<!-- Saved in parser cache with key .*?-->\n?/s' => "\n",
		// HTMLFileCache::saveToFileCache stamps the moment it wrote the file.
		'/\n?<!-- Cached(?:\/compressed)? \d+ -->\n?/' => "\n"
	];

	/**
	 * @param string $html A rendered page.
	 * @return string The page with the stamps removed or blanked.
	 */
	public static function strip(string $html): string {
		foreach (self::STAMPS as $pattern => $replacement) {
			$html = preg_replace($pattern, $replacement, $html);
		}
		return $html;
	}
}
