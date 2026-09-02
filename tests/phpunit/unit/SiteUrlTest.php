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
		$this->assertSame('', SiteUrl::normalize('not a url'));
		$this->assertSame('', SiteUrl::normalize('http://'));
	}

	/**
	 * A base is about to become a directory that file names hang off, and a query or a fragment
	 * cannot be one: appending the trailing slash after either gives ".../wiki?x=1/index.html".
	 * Both are things a site can reasonably paste out of an address bar, so they are dropped rather
	 * than refused.
	 */
	public function testAQueryOrFragmentCannotBeTheDirectoryFileNamesHangOff() {
		$this->assertSame('https://example.org/wiki/', SiteUrl::normalize('https://example.org/wiki?x=1'));
		$this->assertSame('https://example.org/wiki/', SiteUrl::normalize('https://example.org/wiki#frag'));
	}

	/**
	 * A scheme and a host are case-insensitive, and a crawler that is handed one spelling here and
	 * another elsewhere counts them as two origins.
	 */
	public function testTheSchemeAndHostReachACrawlerNormalised() {
		$this->assertSame('https://example.org/wiki/', SiteUrl::normalize('HTTPS://Example.ORG/wiki'));
	}

	/** A password written into the base would otherwise be copied into every URL built from it. */
	public function testACredentialInTheBaseIsNotCarriedIntoEveryUrl() {
		$this->assertSame(
			'https://example.org/wiki/',
			SiteUrl::normalize('https://user:pw@example.org/wiki')
		);
	}

	public function testCoreIsHandedTheSchemeAndHostAndNotThePath() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org/wiki/';
		$this->assertSame('https://example.org', SiteUrl::canonicalServer());
	}

	/** A port is part of the host half, and dropping it would name a server nothing answers on. */
	public function testAPortStaysWithTheHost() {
		$GLOBALS['wgWikvenSiteUrl'] = 'http://localhost:8080/wiki/';
		$this->assertSame('http://localhost:8080', SiteUrl::canonicalServer());

		$GLOBALS['wgWikvenSiteUrl'] = 'https://[::1]:8080/wiki/';
		$this->assertSame('https://[::1]:8080', SiteUrl::canonicalServer());
	}

	/** The port a scheme already implies is noise, and writing it invites a second spelling. */
	public function testThePortASchemeAlreadyImpliesIsNotWrittenTwice() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org:443/wiki';
		$this->assertSame('https://example.org', SiteUrl::canonicalServer());
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

	/**
	 * The failure a bare RFC 3986 resolution walks into: a reference whose first segment holds a
	 * colon is a URL with a scheme, and the default file-name spelling keeps colons -- this name is
	 * a real one on wikven's own documentation site. Resolved bare it comes back
	 * "file:Note_icon.svg.html", which is relative, lower-cased, and not the page.
	 */
	public function testAColonInAFileNameIsNotReadAsAScheme() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org/wiki/';
		$this->assertSame(
			'https://example.org/wiki/File:Note_icon.svg.html',
			SiteUrl::forFile('File:Note_icon.svg.html')
		);
	}

	/** A translated page is a real subdirectory of the export, and stays one. */
	public function testANameWithAPathSeparatorKeepsIt() {
		$GLOBALS['wgWikvenSiteUrl'] = 'https://example.org/wiki/';
		$this->assertSame(
			'https://example.org/wiki/Configuration/ko.html',
			SiteUrl::forFile('Configuration/ko.html')
		);
	}
}
