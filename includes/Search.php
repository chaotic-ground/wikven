<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Registration\ExtensionRegistry;

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
	 * Whether submitting a plain search form reaches results.
	 *
	 * SifterSearch retargets the skin's form at its results page, and only when one is configured
	 * (it is not by default). Skins whose box is wired up by the on-focus typeahead do not care:
	 * that module takes the submit to the top Pagefind result instead. A skin left with nothing
	 * but the form -- Citizen, whose own search is its command palette -- has only this path.
	 */
	public static function hasResultsPage(): bool {
		return self::isActive() && (string)( $GLOBALS['wgSifterSearchResultsPage'] ?? '' ) !== '';
	}
}
