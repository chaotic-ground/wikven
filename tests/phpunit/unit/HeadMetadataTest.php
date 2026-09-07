<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Output\HeadMetadata;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Output\HeadMetadata
 */
class HeadMetadataTest extends MediaWikiUnitTestCase {
	/** Where "MARK" sits in $html, which is where each test asks about. */
	private static function markIn(string $html): int {
		$at = strpos($html, 'MARK');
		self::assertNotFalse($at, 'the fixture has no MARK to ask about');
		return $at;
	}

	private static function holdsTheMarkIn(string $html): bool {
		return HeadMetadata::of($html)->holds(self::markIn($html));
	}

	public function testAMetaElementInTheHeadIsReadAwayFromThePage() {
		$this->assertTrue(
			self::holdsTheMarkIn('<html><head><meta property="og:image" content="MARK"></head></html>')
		);
	}

	public function testASchemaOrgBlockInTheHeadIsReadAwayFromThePage() {
		$this->assertTrue(
			self::holdsTheMarkIn(
				'<html><head><script type="application/ld+json">{"image":"MARK"}</script></head></html>'
			)
		);
	}

	/**
	 * The rest of the head is fetched by the browser rendering this page, exactly as the body's
	 * pictures are, so a copy beside the page is what it should be given.
	 */
	public function testAFaviconIsNot() {
		$this->assertFalse(
			self::holdsTheMarkIn('<html><head><link rel="icon" href="MARK"></head></html>')
		);
	}

	public function testAStylesheetIsNot() {
		$this->assertFalse(
			self::holdsTheMarkIn('<html><head><link rel="stylesheet" href="MARK"></head></html>')
		);
	}

	public function testThePageBodyIsNot() {
		$this->assertFalse(
			self::holdsTheMarkIn('<html><head><title>A page</title></head><body><img src="MARK"></body></html>')
		);
	}

	/**
	 * A page about HTML shows tags as text. MediaWiki escapes those, so nothing in the body can
	 * look like a <meta> to this -- but the body is not searched either way.
	 */
	public function testTextAfterTheHeadThatLooksLikeAMetaElementIsNot() {
		$this->assertFalse(
			self::holdsTheMarkIn('<html><head></head><body><pre>&lt;meta content="MARK"&gt;</pre></body></html>')
		);
	}

	/** Half a head is still a head; the alternative is deciding a page has no metadata at all. */
	public function testAHeadThatWasNeverClosedStillHasMetadataInIt() {
		$this->assertTrue(self::holdsTheMarkIn('<html><head><meta content="MARK">'));
	}

	public function testAPageWithNoHeadHasNothingReadAwayFromIt() {
		$this->assertFalse(self::holdsTheMarkIn('<img src="MARK">'));
	}

	/** Every span is found, not just the first: a head carries a dozen meta tags. */
	public function testEachSpanIsFoundWhereverItSitsAmongTheOthers() {
		$html =
			'<html><head><meta charset="utf-8">'
			. '<link rel="icon" href="/images/logo.svg">'
			. '<meta property="og:image" content="MARK">'
			. '<script type="application/ld+json">{"image":"second"}</script>'
			. '</head></html>';
		$metadata = HeadMetadata::of($html);
		$this->assertTrue($metadata->holds(self::markIn($html)));
		$this->assertTrue($metadata->holds(strpos($html, 'second')));
		$this->assertFalse($metadata->holds(strpos($html, '/images/logo.svg')));
	}
}
