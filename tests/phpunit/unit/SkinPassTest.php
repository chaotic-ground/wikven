<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SkinPass;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SkinPass
 */
class SkinPassTest extends MediaWikiUnitTestCase {
	/** One rule asked in two directions, which is what keeps the two sides of it agreeing. */
	public function testAPassIsReadBackByWhatItSaid() {
		$this->assertSame(74, SkinPass::pagesWritten(SkinPass::wrote(74)));
	}

	/** The line is read out of everything the pass wrote, which is thousands of other lines. */
	public function testItIsFoundAmongThePassesOtherOutput() {
		$output =
			"Building page file cache from page_id 0!\n"
			. "Cached page 'index' (id 1)...[view: 38671 bytes; history: 0 bytes]\n"
			. SkinPass::wrote(74)
			. "\n";

		$this->assertSame(74, SkinPass::pagesWritten($output));
	}

	/**
	 * The failure this exists for. A pass that died halfway wrote everything up to the point it
	 * died, and under the standalone binary it returned 0 as well; the missing line is the only
	 * thing that separates it from a pass that finished.
	 */
	public function testAPassThatStoppedHalfwaySaysNothing() {
		$output =
			"Building page file cache from page_id 0!\n"
			. "Cached page 'File:card.png' (id 7)...[view: 37314 bytes; history: 0 bytes]\n"
			. "Error: Class \"…\\TranslationFamily\" not found\n";

		$this->assertNull(SkinPass::pagesWritten($output));
	}

	/** A pass with nothing to say at all is the same answer. */
	public function testNoOutputIsNoAnswer() {
		$this->assertNull(SkinPass::pagesWritten(''));
	}

	/** A site of one page is still a site, and zero is a number the build gets to weigh itself. */
	public function testAPassThatWroteNothingStillSaidSo() {
		$this->assertSame(0, SkinPass::pagesWritten(SkinPass::wrote(0)));
	}

	/** The line is the whole of a line: a pass quoting it in prose is not a pass reporting. */
	public function testTheLineIsNotMatchedInsideAnotherOne() {
		$this->assertNull(SkinPass::pagesWritten('see where Wikven: this pass wrote 74 page(s) is'));
	}
}
