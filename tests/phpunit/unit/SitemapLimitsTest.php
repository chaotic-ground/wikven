<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SitemapLimits;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SitemapLimits
 */
class SitemapLimitsTest extends MediaWikiUnitTestCase {
	/** The shape this project's own documentation bake produces: 72 URLs in 6,008 bytes. */
	public function testASiteNowhereNearEitherCapSaysNothing() {
		$this->assertNull(SitemapLimits::exceeded('sitemap.xml', 72, 6008));
	}

	/**
	 * The protocol says "no more than" and "no larger than", so the cap itself is inside it. A site
	 * of exactly 50,000 pages hearing that it has too many would be sent looking for a fault that
	 * is not there.
	 */
	public function testASiteExactlyAtBothCapsIsWithinThem() {
		$this->assertNull(SitemapLimits::exceeded('sitemap.xml', SitemapLimits::URLS, SitemapLimits::BYTES));
	}

	/** One page over, and the count is what a crawler will refuse the file for. */
	public function testMorePagesThanOneSitemapMayHoldIsSaid() {
		$this->assertSame(
			'Wikven: sitemap.xml names 50001 pages, over the 50000 a single sitemap may hold. It is written '
			. 'anyway and a crawler will reject it; splitting it is not built yet.',
			SitemapLimits::exceeded('sitemap.xml', 50_001, 4_200_000)
		);
	}

	/**
	 * The gap this class was added for. At about 1,000 bytes per URL -- a location around 900
	 * characters, which deep percent-encoded titles could reach -- a site passes the byte cap while
	 * well under the count.
	 */
	public function testASitemapPastTheByteCapUnderTheUrlCapIsSaid() {
		$this->assertSame(
			'Wikven: sitemap.xml is 60000000 bytes, over the 52428800 a single sitemap may be. It is written '
			. 'anyway and a crawler will reject it; splitting it is not built yet.',
			SitemapLimits::exceeded('sitemap.xml', 49_000, 60_000_000)
		);
	}

	/** Past both is one complaint: two sentences differing by a clause read as two problems. */
	public function testASitemapPastBothCapsIsOneComplaintNamingBoth() {
		$past = SitemapLimits::exceeded('sitemap.xml', 80_000, 90_000_000);

		$this->assertStringContainsString('names 80000 pages, over the 50000', (string)$past);
		$this->assertStringContainsString('is 90000000 bytes, over the 52428800', (string)$past);
		$this->assertSame(1, substr_count((string)$past, 'splitting it is not built yet'));
	}

	/**
	 * The file is written past either cap, so the complaint has to say so: a reader who is told
	 * only that something is too big will go looking for a file that is on disk all along.
	 */
	public function testTheComplaintSaysTheFileIsWrittenAnyway() {
		$this->assertStringContainsString(
			'is written anyway and a crawler will reject it',
			(string)SitemapLimits::exceeded('sitemap.xml', 50_001, 6008)
		);
	}

	/**
	 * "50MB" is ambiguous and the protocol is not: it writes "50MB (52,428,800 bytes)", which is
	 * the binary megabyte. Reading it as 50,000,000 would call a 51 MB sitemap invalid when it is
	 * inside the cap by 2.4 MB.
	 */
	public function testTheByteCapIsTheOneTheProtocolSpellsOut() {
		$this->assertSame(52_428_800, SitemapLimits::BYTES);
		$this->assertNull(SitemapLimits::exceeded('sitemap.xml', 100, 51_000_000));
	}
}
