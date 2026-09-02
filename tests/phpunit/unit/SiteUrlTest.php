<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SiteUrl;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SiteUrl
 */
class SiteUrlTest extends MediaWikiUnitTestCase {
	public function testAWrittenBaseGainsTheTrailingSlashEveryCallerJoinsOn() {
		$this->assertSame('https://example.org/wiki/', SiteUrl::fromWritten('https://example.org/wiki')->base());
		$this->assertSame('https://example.org/wiki/', SiteUrl::fromWritten('https://example.org/wiki/')->base());
	}

	/**
	 * The failure the trailing slash exists to catch: a base kept without it resolves "index.html"
	 * to "https://example.org/index.html", and every URL in a sitemap names a page that is not
	 * there.
	 */
	public function testTheSlashIsWhatKeepsTheLastPathSegment() {
		$this->assertSame(
			'https://example.org/wiki/index.html',
			SiteUrl::fromWritten('https://example.org/wiki')->forFile('index.html')
		);
	}

	public function testSaidNothingReadsAsSaidNothing() {
		$this->assertFalse(SiteUrl::fromWritten('')->isKnown());
		$this->assertFalse(SiteUrl::fromWritten('   ')->isKnown());
	}

	/**
	 * Everything built here is read by a crawler, which fetches neither of these, so a base in
	 * another scheme would fill a sitemap with URLs nothing can follow.
	 */
	public function testASchemeACrawlerCannotFetchIsNotABase() {
		$this->assertFalse(SiteUrl::fromWritten('file:///srv/dist/')->isKnown());
		$this->assertFalse(SiteUrl::fromWritten('mailto:someone@example.org')->isKnown());
	}

	public function testSomethingThatIsNotAUrlIsNotABase() {
		$this->assertFalse(SiteUrl::fromWritten('example.org/wiki')->isKnown());
		$this->assertFalse(SiteUrl::fromWritten('/wiki/')->isKnown());
		$this->assertFalse(SiteUrl::fromWritten('not a url')->isKnown());
		$this->assertFalse(SiteUrl::fromWritten('http://')->isKnown());
	}

	/**
	 * A base is about to become a directory that file names hang off, and a query or a fragment
	 * cannot be one: appending the trailing slash after either gives ".../wiki?x=1/index.html".
	 * Both are things a site can reasonably paste out of an address bar, so they are dropped rather
	 * than refused.
	 */
	public function testAQueryOrFragmentCannotBeTheDirectoryFileNamesHangOff() {
		$this->assertSame(
			'https://example.org/wiki/',
			SiteUrl::fromWritten('https://example.org/wiki?x=1')->base()
		);
		$this->assertSame(
			'https://example.org/wiki/',
			SiteUrl::fromWritten('https://example.org/wiki#frag')->base()
		);
	}

	/**
	 * A scheme and a host are case-insensitive, and a crawler handed one spelling here and another
	 * elsewhere counts them as two origins.
	 */
	public function testTheSchemeAndHostReachACrawlerNormalised() {
		$this->assertSame('https://example.org/wiki/', SiteUrl::fromWritten('HTTPS://Example.ORG/wiki')->base());
	}

	/** A password written into the base would otherwise be copied into every URL built from it. */
	public function testACredentialInTheBaseIsNotCarriedIntoEveryUrl() {
		$this->assertSame(
			'https://example.org/wiki/',
			SiteUrl::fromWritten('https://user:pw@example.org/wiki')->base()
		);
	}

	public function testCoreIsHandedTheSchemeAndHostAndNotThePath() {
		$this->assertSame(
			'https://example.org',
			SiteUrl::fromWritten('https://example.org/wiki/')->canonicalServer()
		);
	}

	/** A port is part of the host half, and dropping it would name a server nothing answers on. */
	public function testAPortStaysWithTheHost() {
		$this->assertSame(
			'http://localhost:8080',
			SiteUrl::fromWritten('http://localhost:8080/wiki/')->canonicalServer()
		);
		$this->assertSame(
			'https://[::1]:8080',
			SiteUrl::fromWritten('https://[::1]:8080/wiki/')->canonicalServer()
		);
	}

	/** The port a scheme already implies is noise, and writing it invites a second spelling. */
	public function testThePortASchemeAlreadyImpliesIsNotWrittenTwice() {
		$this->assertSame(
			'https://example.org',
			SiteUrl::fromWritten('https://example.org:443/wiki')->canonicalServer()
		);
	}

	public function testWithNoBaseThereIsNoAbsoluteUrlToGive() {
		$siteUrl = SiteUrl::fromWritten('');
		$this->assertFalse($siteUrl->isKnown());
		$this->assertSame('', $siteUrl->base());
		$this->assertSame('', $siteUrl->canonicalServer());
		$this->assertSame('', $siteUrl->forFile('index.html'));
	}

	/**
	 * The name arrives already url-encoded from OutputName, so it is resolved rather than encoded
	 * again: encoding it twice is how a percent sign in a file name becomes %25.
	 */
	public function testAFileNameIsJoinedRatherThanEncodedASecondTime() {
		$this->assertSame(
			'https://example.org/wiki/File%3ABakery_oven.jpg.html',
			SiteUrl::fromWritten('https://example.org/wiki/')->forFile('File%3ABakery_oven.jpg.html')
		);
	}

	/**
	 * The failure a bare RFC 3986 resolution walks into: a reference whose first segment holds a
	 * colon is a URL with a scheme, and the default file-name spelling keeps colons -- this name is
	 * a real one on wikven's own documentation site. Resolved bare it comes back
	 * "file:Note_icon.svg.html", which is relative, lower-cased, and not the page.
	 */
	public function testAColonInAFileNameIsNotReadAsAScheme() {
		$this->assertSame(
			'https://example.org/wiki/File:Note_icon.svg.html',
			SiteUrl::fromWritten('https://example.org/wiki/')->forFile('File:Note_icon.svg.html')
		);
	}

	/** A translated page is a real subdirectory of the export, and stays one. */
	public function testANameWithAPathSeparatorKeepsIt() {
		$this->assertSame(
			'https://example.org/wiki/Configuration/ko.html',
			SiteUrl::fromWritten('https://example.org/wiki/')->forFile('Configuration/ko.html')
		);
	}
}
