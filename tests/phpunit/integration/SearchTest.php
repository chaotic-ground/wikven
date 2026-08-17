<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Search;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Search
 */
class SearchTest extends MediaWikiIntegrationTestCase {
	/**
	 * Both answers are about SifterSearch, which is not installed in this suite, so what is
	 * pinned here is the shape of that: with no search extension there is neither a working box
	 * nor a results page to submit to, whatever the settings around them say.
	 */
	public function testWithoutSifterSearchNothingIsActive() {
		$this->setMwGlobals([
			'wgSifterSearchOutputDir' => '/tmp/pagefind',
			'wgSifterSearchResultsPage' => 'Search'
		]);

		$this->assertFalse(Search::isActive());
		$this->assertFalse(Search::hasResultsPage(), 'a results page needs a search to reach it');
	}

	/**
	 * The page itself is answered whatever the search around it is doing: it is where a search link
	 * lands, and a site that named one named it for that.
	 *
	 * @dataProvider provideResultsPages
	 */
	public function testResultsPageIsTheConfiguredTitle(?string $configured, ?string $expected) {
		$this->setMwGlobals('wgSifterSearchResultsPage', $configured);
		$page = Search::resultsPage();
		$this->assertSame($expected, $page?->getPrefixedText());
	}

	public static function provideResultsPages() {
		return [
			'a page' => ['Search', 'Search'],
			'a page outside the main namespace' => ['Help:Search', 'Help:Search'],
			'none named' => ['', null],
			// SifterSearch reads the setting as a title too, and wires up nothing when it is not one.
			'not a title' => ['<', null],
			'no value, as on a wiki without SifterSearch' => [null, null]
		];
	}

	/**
	 * The bundle's location is what decides which copy of the site a search answers with, so this
	 * is the whole of #399's fix: the copy's directory goes ahead of the bundle's own, and every
	 * URL SifterSearch derives from the path follows it in.
	 *
	 * @dataProvider provideCopyBundlePaths
	 */
	public function testCopyBundlePathPutsTheBundleBesideTheCopy(
		string $bundlePath,
		string $directory,
		?string $expected
	) {
		$this->assertSame($expected, Search::copyBundlePath($bundlePath, $directory));
	}

	public static function provideCopyBundlePaths() {
		return [
			'a site in a subdirectory' => ['/wikven/pagefind/', 'citizen', '/wikven/citizen/pagefind/'],
			'a site at the host root' => ['/pagefind/', 'minerva', '/minerva/pagefind/'],
			// SifterSearch's setting is a directory either way; a site may write it without the
			// trailing slash, and the copy's own is the same path with one segment more.
			'no trailing slash' => ['/wikven/pagefind', 'citizen', '/wikven/citizen/pagefind/'],
			'several directories down' => ['/a/b/pagefind/', 'citizen', '/a/b/citizen/pagefind/'],
			// Nothing here knows where such a bundle's site root is, so it is left where it is and
			// the copy goes on reading the one bundle -- today's behaviour, not a broken path.
			'another host' => ['https://cdn.example/pagefind/', 'citizen', null],
			'protocol-relative' => ['//cdn.example/pagefind/', 'citizen', null],
			'a relative path' => ['pagefind/', 'citizen', null],
			'nothing configured' => ['', 'citizen', null],
			// A bundle claiming the site root itself has no directory of its own to reproduce, and
			// a copy of every page under it would collide with the copy's own pages.
			'the site root' => ['/', 'citizen', null],
			'no copy directory' => ['/wikven/pagefind/', '', null]
		];
	}
}
