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

	/** A run where every pass finished and they saw the same site has nothing to say. */
	public function testPassesThatAgreeAreNoFailure() {
		$this->assertSame(
			[],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(74)],
				'citizen' => ['exit' => 0, 'output' => SkinPass::wrote(74)],
				'minerva' => ['exit' => 0, 'output' => SkinPass::wrote(74)]
			])
		);
	}

	/** One pass is a run too, and has nobody to disagree with. */
	public function testOnePassThatFinishedIsEnough() {
		$this->assertSame(
			[],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(7)]
			])
		);
	}

	/**
	 * The failure this class exists for: the pass returned success and never reached the end, and
	 * the exit code is the one thing that cannot tell you so.
	 */
	public function testAPassThatReturnedSuccessWithoutFinishingIsAFailure() {
		$this->assertSame(
			['Wikven: build failed for skin citizen (stopped before the end of its work)'],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(74)],
				'citizen' => ['exit' => 0, 'output' => "Cached page 'index' (id 1)...\n"]
			])
		);
	}

	/** An exit code that does say so is still read, and named for what it is. */
	public function testAPassThatReturnedFailureIsNamedByItsCode() {
		$this->assertSame(
			['Wikven: build failed for skin minerva (exit 137)'],
			SkinPass::failures([
				'minerva' => ['exit' => 137, 'output' => ''],
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(74)]
			])
		);
	}

	/** Every pass that went wrong is named, because a reader fixing one wants to see the rest. */
	public function testAllTheFailedPassesAreNamedAtOnce() {
		$this->assertSame(
			[
				'Wikven: build failed for skin vector-2022 (stopped before the end of its work), '
					. 'citizen (stopped before the end of its work)'
			],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => ''],
				'citizen' => ['exit' => 0, 'output' => '']
			])
		);
	}

	/**
	 * Every pass renders the same wiki, frozen before any of them started, so passes that report
	 * different numbers have not all rendered it -- even though each one finished and said so.
	 */
	public function testPassesThatDisagreeOnHowMuchOfTheSiteThereIsAreAFailure() {
		$this->assertSame(
			[
				'Wikven: the skin passes disagree on how much of the site there is: '
					. 'vector-2022 wrote 74, citizen wrote 15'
			],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(74)],
				'citizen' => ['exit' => 0, 'output' => SkinPass::wrote(15)]
			])
		);
	}

	/** A pass that did not finish is the better complaint, so it is the one made. */
	public function testAPassThatStoppedIsReportedRatherThanTheDisagreementItCauses() {
		$this->assertSame(
			['Wikven: build failed for skin citizen (stopped before the end of its work)'],
			SkinPass::failures([
				'vector-2022' => ['exit' => 0, 'output' => SkinPass::wrote(74)],
				'citizen' => ['exit' => 0, 'output' => 'nothing'],
				'minerva' => ['exit' => 0, 'output' => SkinPass::wrote(15)]
			])
		);
	}
}
