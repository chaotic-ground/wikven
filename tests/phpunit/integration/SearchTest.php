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
}
