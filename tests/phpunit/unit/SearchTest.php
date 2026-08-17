<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Search;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Search
 */
class SearchTest extends MediaWikiUnitTestCase {
	public function testASitePathGainsTheSkinDirectoryAboveTheBundle() {
		$this->assertSame('/wikven/citizen/pagefind/', Search::bundlePathUnder('/wikven/pagefind/', 'citizen'));
	}

	public function testABundleAtTheSiteRootIsHandledTheSameWay() {
		$this->assertSame('/minerva/pagefind/', Search::bundlePathUnder('/pagefind/', 'minerva'));
	}

	public function testATrailingSlashIsNotRequiredAndIsAlwaysGivenBack() {
		$this->assertSame('/wikven/citizen/pagefind/', Search::bundlePathUnder('/wikven/pagefind', 'citizen'));
	}

	public function testADeeperBundlePathKeepsEverythingAboveTheBundle() {
		$this->assertSame(
			'/w/static/citizen/index/',
			Search::bundlePathUnder('/w/static/index/', 'citizen')
		);
	}

	public function testARootAnchoredPathIsTheOneThatCanBeCopied() {
		$this->assertTrue(Search::isBundlePathRootAnchored('/pagefind/'));
		$this->assertTrue(Search::isBundlePathRootAnchored('/wikven/pagefind/'));
	}

	/** A bundle on another host says nothing about where this site's root is, so it is left alone. */
	public function testABundleOffThisSiteIsNotRootAnchored() {
		$this->assertFalse(Search::isBundlePathRootAnchored('//cdn.example.org/pagefind/'));
		$this->assertFalse(Search::isBundlePathRootAnchored('https://cdn.example.org/pagefind/'));
		$this->assertFalse(Search::isBundlePathRootAnchored('pagefind/'));
		$this->assertFalse(Search::isBundlePathRootAnchored(''));
	}
}
