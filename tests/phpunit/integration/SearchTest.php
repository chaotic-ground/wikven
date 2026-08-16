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
}
