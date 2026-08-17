<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Wikimedia\AtEase\AtEase;

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
	 * Where a skin pass publishes and loads its own copy of the search index, or null where it
	 * reads the one that is already there.
	 *
	 * Pagefind reports a result's URL relative to the crawl root and the client resolves it against
	 * the directory the bundle sits in, so a copy of the site that loads the root's bundle sends
	 * every result out of the copy (#399). The answer is the same index, read from inside the copy.
	 *
	 * @param string $skin The skin this pass renders.
	 * @param string $mainSkin The skin whose output is the export root.
	 * @param string $bundlePath The site's own bundle path, as configured.
	 * @param string $indexSource The directory the index was built into.
	 * @param string $htmlDir This pass's output directory.
	 * @return ?array{path:string,directory:string} The bundle path this pass's pages load from, and
	 *   the directory the index is to be copied into.
	 */
	public static function bundleCopyFor(
		string $skin,
		string $mainSkin,
		string $bundlePath,
		string $indexSource,
		string $htmlDir
	): ?array {
		// The main skin's output is the export root, so its pages already resolve results against
		// the directory the index is in. Nothing to copy, and nowhere else to put it.
		if ($skin === '' || $skin === $mainSkin || $indexSource === '' || $htmlDir === '') {
			return null;
		}
		// A bundle served from another host says nothing about where this site's root is, and is
		// not wikven's to copy -- the test SifterSearch's ClientConfig::anchored() makes, for the
		// same reason.
		if (!str_starts_with($bundlePath, '/') || str_starts_with($bundlePath, '//')) {
			return null;
		}
		return [
			'path' => self::bundlePathUnder($bundlePath, $skin),
			'directory' => rtrim($htmlDir, '/') . '/' . basename(rtrim($indexSource, '/'))
		];
	}

	/**
	 * The same bundle path with a skin's directory in front of the bundle.
	 *
	 * The path names the bundle directory itself ("/wikven/pagefind/"), and its parent is the root
	 * the client resolves every result against, so putting the skin's directory between the two
	 * ("/wikven/citizen/pagefind/") makes the skin's own copy that root -- which is the whole of
	 * the fix, the bundle bytes being identical either way.
	 */
	private static function bundlePathUnder(string $bundlePath, string $skin): string {
		$path = rtrim($bundlePath, '/');
		$cut = strrpos($path, '/');
		$root = $cut === false ? '' : substr($path, 0, $cut + 1);
		$bundle = $cut === false ? $path : substr($path, $cut + 1);
		return "$root$skin/$bundle/";
	}

	/**
	 * Put a copy of the built index at $destination, for the pages that sit beside it.
	 *
	 * The index is built once, over content that is the same in every copy, so this copies rather
	 * than rebuilds: a Pagefind pass per skin would cost the whole site again for bytes that come
	 * out identical. A copy is also the same bytes every bake, which a rebuild need not be.
	 *
	 * Written with plain filesystem calls rather than wfMkdirParents(), so the one part of this
	 * that can fail on a real tree is reachable from a unit test.
	 *
	 * @return ?string What could not be copied, ready to report, or null when the copy is in place
	 *   or there was nothing to do.
	 */
	public static function publishBundle(string $source, string $destination): ?string {
		$source = rtrim($source, '/');
		$destination = rtrim($destination, '/');
		if ($source === '' || $destination === '' || $source === $destination || !is_dir($source)) {
			return null;
		}
		if (!self::makeDirectory($destination)) {
			return "Wikven: could not create directory $destination";
		}

		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($entries as $entry) {
			$target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);
			if ($entry->isDir()) {
				if (!self::makeDirectory($target)) {
					return "Wikven: could not create directory $target";
				}
			} elseif (!copy($entry->getPathname(), $target)) {
				return "Wikven: could not copy {$entry->getPathname()} to $target";
			}
		}
		return null;
	}

	/**
	 * mkdir -p, answering true for a directory that is already there or that lost a race for it.
	 *
	 * wfMkdirParents() is this, and is not used: it reports its own failure with trigger_error(),
	 * which a test harness that turns warnings into errors raises before the caller here can say
	 * which copy could not be published. PHP's own warning is suppressed for the same reason, and
	 * the existence check is repeated after a failed mkdir(), as core does, so a pass that lost a
	 * race for the directory still sees one.
	 */
	private static function makeDirectory(string $path): bool {
		if (is_dir($path)) {
			return true;
		}
		AtEase::suppressWarnings();
		$made = mkdir($path, 0777, true);
		AtEase::restoreWarnings();
		return $made || is_dir($path);
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
