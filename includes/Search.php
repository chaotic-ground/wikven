<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/** Whether SifterSearch provides a working static search box: loaded and indexing into a bundle. */
class Search {
	/**
	 * SifterSearch's index rebuild, which the build defers to the end.
	 *
	 * It is queued for every revision inserted and rebuilds the whole bundle each time it runs, so
	 * anything that drains the queue mid-import pays for a full Pagefind pass over the content so
	 * far, and leaves another generation of hashed index files behind in the output.
	 */
	public const INDEX_JOB = 'sifterSearchBuildIndex';

	/**
	 * Whether the bundle path is a path from this site's own root.
	 *
	 * That is the only shape a per-copy bundle can be derived from, and the same test
	 * SifterSearch's ClientConfig::anchored() makes for the same reason: a bundle served from
	 * another host says nothing about where this site's root is. Wikven cannot copy such a bundle
	 * into a skin's directory either, since it is not wikven that publishes it.
	 */
	public static function isBundlePathRootAnchored(string $bundlePath): bool {
		return str_starts_with($bundlePath, '/') && !str_starts_with($bundlePath, '//');
	}

	/**
	 * The same bundle path with a skin's directory in front of the bundle, which is where that
	 * skin's copy of the index is published.
	 *
	 * The path names the bundle directory itself ("/wikven/pagefind/"), and its parent is the root
	 * the client resolves every result against, so putting the skin's directory between the two
	 * ("/wikven/citizen/pagefind/") makes the skin's own copy that root -- which is the whole of
	 * the fix, the bundle bytes being identical either way.
	 */
	public static function bundlePathUnder(string $bundlePath, string $skin): string {
		$path = rtrim($bundlePath, '/');
		$cut = strrpos($path, '/');
		$root = $cut === false ? '' : substr($path, 0, $cut + 1);
		$bundle = $cut === false ? $path : substr($path, $cut + 1);
		return "$root$skin/$bundle/";
	}

	public static function isActive(): bool {
		return (
			ExtensionRegistry::getInstance()->isLoaded('SifterSearch')
			&& (string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' ) !== ''
		);
	}

	/**
	 * The page SifterSearch lists a query's matches on, or null where the site names none.
	 *
	 * Read as a title, because that is what SifterSearch does with the setting: a value that is not
	 * one names no page, and its own handler gives up on it rather than wiring anything to it.
	 *
	 * Not gated on isActive(), unlike hasResultsPage() below: what this answers is where a search
	 * link can land, and the page a site named is that place whether or not an index was built for
	 * it. Where search is off entirely the box is hidden anyway, so nothing offers such a link.
	 */
	public static function resultsPage(): ?Title {
		$page = (string)( $GLOBALS['wgSifterSearchResultsPage'] ?? '' );
		if ($page === '') {
			return null;
		}
		return Title::newFromText($page);
	}

	/**
	 * Whether submitting a plain search form reaches results.
	 *
	 * SifterSearch retargets the skin's form at its results page, and only when one is configured
	 * (it is not by default). Skins whose box is wired up by the on-focus typeahead do not care:
	 * that module takes the submit to the top Pagefind result instead. A skin left with nothing
	 * but the form -- Citizen, whose own search is its command palette -- has only this path.
	 */
	public static function hasResultsPage(): bool {
		return self::isActive() && self::resultsPage() !== null;
	}
}
