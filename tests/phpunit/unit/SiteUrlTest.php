<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SiteUrl;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SiteUrl
 */
class SiteUrlTest extends MediaWikiUnitTestCase {
	public function testAWrittenBaseGainsTheTrailingSlashEveryCallerJoinsOn() {
		$this->assertSame('https://example.org/wiki/', SiteUrl::normalize('https://example.org/wiki'));
		$this->assertSame('https://example.org/wiki/', SiteUrl::normalize('https://example.org/wiki/'));
	}

	/**
	 * The failure this exists to catch: a base kept without its slash is joined to "index.html" as
	 * "https://example.org/wikiindex.html", and every URL in a sitemap names a page that is not
	 * there.
	 */
	public function testTheSlashIsWhatKeepsTheLastPathSegment() {
		$this->assertSame(
			'https://example.org/wiki/index.html',
			rtrim(SiteUrl::normalize('https://example.org/wiki'), '/') . '/index.html'
		);
	}

	public function testSaidNothingReadsAsSaidNothing() {
		$this->assertSame('', SiteUrl::normalize(''));
		$this->assertSame('', SiteUrl::normalize('   '));
	}

	/**
	 * Everything this produces is read by a crawler, which fetches neither of these, so a base in
	 * another scheme would fill a sitemap with URLs nothing can follow.
	 */
	public function testASchemeACrawlerCannotFetchIsNotABase() {
		$this->assertSame('', SiteUrl::normalize('file:///srv/dist/'));
		$this->assertSame('', SiteUrl::normalize('mailto:someone@example.org'));
	}

	public function testSomethingThatIsNotAUrlIsNotABase() {
		$this->assertSame('', SiteUrl::normalize('example.org/wiki'));
		$this->assertSame('', SiteUrl::normalize('/wiki/'));
	}

	public function testCoreIsHandedTheSchemeAndHostAndNotThePath() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org/wiki/';
		$this->assertSame('https://example.org', SiteUrl::canonicalServer());
	}

	/** A port is part of the host half, and dropping it would name a server nothing answers on. */
	public function testAPortStaysWithTheHost() {
		$GLOBALS['wgWikvenSiteUrl'] = 'http://localhost:8080/wiki/';
		$this->assertSame('http://localhost:8080', SiteUrl::canonicalServer());
	}

	public function testWithNoBaseThereIsNoAbsoluteUrlToGive() {
		$GLOBALS['wgWikvenSiteUrl'] = '';
		$this->assertFalse(SiteUrl::known());
		$this->assertSame('', SiteUrl::canonicalServer());
		$this->assertSame('', SiteUrl::forFile('index.html'));
	}

	/**
	 * The name arrives already url-encoded from OutputName, so it is joined rather than encoded
	 * again: encoding it twice is how a percent sign in a file name becomes %25.
	 */
	public function testAFileNameIsJoinedRatherThanEncodedASecondTime() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org/wiki/';
		$this->assertSame(
			'https://example.org/wiki/File%3ABakery_oven.jpg.html',
			SiteUrl::forFile('File%3ABakery_oven.jpg.html')
		);
	}
}
