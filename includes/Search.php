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

	/**
	 * Where a skin copy loads the Pagefind bundle from, given the path the site serves its own at.
	 *
	 * A result carries the URL the page had under the crawl root, and the client resolves that
	 * against the bundle's own parent directory, so the bundle's location is what decides which
	 * copy of the site a search answers with. One bundle at the export root answers every copy
	 * with the root copy's pages, which is a reader who chose a skin being taken out of it the
	 * moment they search (#399). SifterSearch anchors the results page's own URL at the same
	 * parent, so that leaves the copy too.
	 *
	 * The copy's bundle therefore sits beside the copy's pages: the site's path with the copy's
	 * directory inserted ahead of its last segment, so "/wikven/pagefind/" becomes
	 * "/wikven/citizen/pagefind/". Everything downstream is SifterSearch's own arithmetic on that
	 * one value, which is why nothing here has to know about results, forms or suggestions.
	 *
	 * @param string $bundlePath The site's own bundle path, e.g. "/wikven/pagefind/".
	 * @param string $directory The copy's directory name, which is the skin's, e.g. "citizen".
	 * @return ?string null where the site's path says nothing about where this site's root is: a
	 *   bundle served from another host, or a protocol-relative one, has no copy to put beside it.
	 */
	public static function copyBundlePath(string $bundlePath, string $directory): ?string {
		if ($directory === '' || !str_starts_with($bundlePath, '/') || str_starts_with($bundlePath, '//')) {
			return null;
		}
		// Cut as the string it is: dirname() answers with a filesystem path, whose root is a
		// backslash on Windows, and this is a URL path either way.
		$path = rtrim($bundlePath, '/');
		$cut = strrpos($path, '/');
		if ($cut === false) {
			return null;
		}
		$segment = substr($path, $cut + 1);
		if ($segment === '') {
			// "/" alone: a bundle at the site root has no directory of its own to reproduce.
			return null;
		}
		return substr($path, 0, $cut + 1) . $directory . '/' . $segment . '/';
	}

	/** The file in the bundle that names each language's index, and the key holding that map. */
	public const INDEX_ENTRY_FILE = 'pagefind-entry.json';
	private const INDEX_ENTRY_LANGUAGES = 'languages';

	/**
	 * The bundle's entry file with its language map in a fixed order, or null to leave it alone.
	 *
	 * Pagefind writes the map in whatever order it iterated the languages, which is not the same
	 * order twice, so two bakes of one source disagree on this file while agreeing on every index
	 * it points at -- the hashes and page counts come out identical. A site with one language never
	 * showed it, having nothing to order; a translated site does, and it broke the promise that two
	 * bakes are byte-identical (#411). This is the same work as StripBuildStamps: the build owns
	 * that promise, so the build settles what a tool upstream of it left unsettled.
	 *
	 * Only the order changes, and only of that one map. Key order carries no meaning in JSON, so
	 * nothing that reads the bundle can tell -- the client looks languages up by name.
	 *
	 * Re-encoding need not reproduce Pagefind's own bytes, only the same bytes every time, which is
	 * all the promise asks; the flags keep it close anyway, so a diff against an older bake stays
	 * readable. Anything this cannot parse, or that does not hold the map, is returned as null and
	 * left as it is: a bundle this does not understand is not one to rewrite.
	 *
	 * @param string $json The contents of pagefind-entry.json.
	 * @return ?string The re-encoded file, or null when there is nothing safe to do.
	 */
	public static function stableIndexEntry(string $json): ?string {
		$entry = json_decode($json, true);
		if (!is_array($entry) || !isset($entry[self::INDEX_ENTRY_LANGUAGES])) {
			return null;
		}
		$languages = $entry[self::INDEX_ENTRY_LANGUAGES];
		if (!is_array($languages) || $languages === []) {
			return null;
		}
		ksort($languages);
		$entry[self::INDEX_ENTRY_LANGUAGES] = $languages;

		$encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $encoded === false ? null : $encoded;
	}
}
