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
	 * Read as a title, because that is what SifterSearch does with the setting. Not gated on
	 * isActive(): this answers where a search link can land, and the page a site named is that
	 * place whether or not an index was built for it.
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
	 * (it is not by default). Skins wired up by the on-focus typeahead do not care; one left with
	 * nothing but the form -- Citizen -- has only this path.
	 */
	public static function hasResultsPage(): bool {
		return self::isActive() && self::resultsPage() !== null;
	}

	/**
	 * Where a skin copy loads the Pagefind bundle from, given the path the site serves its own at.
	 *
	 * A result carries the URL the page had under the crawl root and the client resolves it against
	 * the bundle's parent, so one bundle at the export root answers every copy with the root copy's
	 * pages (#399).
	 *
	 * @param string $bundlePath The site's own bundle path, e.g. "/wikven/pagefind/".
	 * @param string $directory The copy's directory name, which is the skin's, e.g. "citizen".
	 * @return ?string null where the site's path says nothing about where this site's root is.
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
	 * Pagefind writes the map in whatever order it iterated the languages, so two bakes of one
	 * source disagree on this file while agreeing on every index it points at (#411). Key order
	 * carries no meaning in JSON.
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
