<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\BuildFor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\BuildFor
 */
class BuildForTest extends MediaWikiUnitTestCase {
	protected function tearDown(): void {
		unset($GLOBALS['wgWikvenBuildFor']);
		parent::tearDown();
	}

	public function testABuildIsForASiteUnlessItIsToldOtherwise() {
		unset($GLOBALS['wgWikvenBuildFor']);
		$this->assertSame(BuildFor::SITE, BuildFor::current());
		$this->assertFalse(BuildFor::skinPreview());
	}

	public function testTheSkinPreviewAudienceIsRecognised() {
		$GLOBALS['wgWikvenBuildFor'] = BuildFor::SKIN_PREVIEW;
		$this->assertSame(BuildFor::SKIN_PREVIEW, BuildFor::current());
		$this->assertTrue(BuildFor::skinPreview());
	}

	/**
	 * Reading an unknown value as a site is the reading that publishes something a static host can
	 * answer for. Guessing at skin-preview would ship a site with the chrome left unhandled.
	 */
	public function testAnUnrecognisedAudienceReadsAsASite() {
		foreach (['sit', '', 'SKIN-PREVIEW', true, 1, ['skin-preview']] as $written) {
			$GLOBALS['wgWikvenBuildFor'] = $written;
			$this->assertSame(BuildFor::SITE, BuildFor::current());
			$this->assertFalse(BuildFor::skinPreview());
		}
	}

	/** The list lint reads to say what it expected, so it must be the list current() accepts. */
	public function testEveryNamedAudienceIsOneCurrentAccepts() {
		foreach (BuildFor::all() as $audience) {
			$GLOBALS['wgWikvenBuildFor'] = $audience;
			$this->assertSame($audience, BuildFor::current());
		}
	}
}
