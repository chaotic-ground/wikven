<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/** Whether SifterSearch provides a working static search box: loaded and indexing into a bundle. */
class Search {
	/**
	 * SifterSearch's index rebuild, which the build defers to the end.
	 *
	 * Queued for every revision inserted and rebuilding the whole bundle each time, so a mid-import
	 * drain pays for a full Pagefind pass and leaves dead index files behind.
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
	 * Not gated on isActive(): this answers where a search link can land, whether or not an index
	 * was built for it.
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
	 * SifterSearch retargets the skin's form at its results page, and only when one is configured.
	 * A skin left with nothing but the form -- Citizen -- has only this path.
	 */
	public static function hasResultsPage(): bool {
		return self::isActive() && self::resultsPage() !== null;
	}

	/**
	 * Where a skin copy loads the Pagefind bundle from.
	 *
	 * The client resolves a result's URL against the bundle's parent, so one bundle at the root
	 * answers every copy with the root's pages.
	 *
	 * @param string $bundlePath The site's own bundle path, e.g. "/wikven/pagefind/".
	 * @param string $directory The copy's directory name, e.g. "citizen".
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
	 * Pagefind writes the map in whatever order it iterated, so two bakes disagree on this file
	 * alone (#411).
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
