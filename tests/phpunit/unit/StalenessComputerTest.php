<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer
 */
class StalenessComputerTest extends MediaWikiUnitTestCase {
	public function testMarkNumbersUnmarkedUnits() {
		$this->assertSame(
			"<translate>\n<!--T:1-->\nFirst.\n\n<!--T:2-->\nSecond.\n</translate>",
			StalenessComputer::mark("<translate>\nFirst.\n\nSecond.\n</translate>")
		);
	}

	public function testMarkTreatsAHeadingAsItsOwnUnit() {
		$this->assertSame(
			"<translate>\n<!--T:1-->\nIntro.\n\n<!--T:2-->\n== Heading ==\n\n<!--T:3-->\nBody.\n</translate>",
			StalenessComputer::mark("<translate>\nIntro.\n\n== Heading ==\n\nBody.\n</translate>")
		);
	}

	public function testMarkKeepsExistingNumbersAndContinuesFromTheHighest() {
		$this->assertSame(
			"<translate>\n<!--T:5-->\nOld.\n\n<!--T:6-->\nNew.\n</translate>",
			StalenessComputer::mark("<translate>\n<!--T:5-->\nOld.\n\nNew.\n</translate>")
		);
	}

	public function testMarkNumbersContinueAcrossBlocks() {
		$this->assertSame(
			"<translate>\n<!--T:1-->\nA.\n</translate>\nx\n<translate>\n<!--T:2-->\nB.\n</translate>",
			StalenessComputer::mark("<translate>\nA.\n</translate>\nx\n<translate>\nB.\n</translate>")
		);
	}

	public function testMarkIsIdempotentAndIgnoresTextOutsideTranslate() {
		$marked = StalenessComputer::mark("outside.\n\n<translate>\nInside.\n</translate>");
		$this->assertSame("outside.\n\n<translate>\n<!--T:1-->\nInside.\n</translate>", $marked);
		$this->assertSame($marked, StalenessComputer::mark($marked));
	}

	public function testAnalyzeFlagsAChangedSourceUnitStale() {
		$source = "<translate>\n<!--T:1-->\nHello.\n\n<!--T:2-->\nWorld.\n</translate>";
		$fresh = StalenessComputer::restamp($source, "<!--T:1-->\n안녕.\n\n<!--T:2-->\n세계.\n");
		$this->assertSame(
			[StalenessComputer::OK, StalenessComputer::OK],
			array_column(StalenessComputer::analyze($source, $fresh), 'status')
		);

		$changed = str_replace('World.', 'Everyone.', $source);
		$this->assertSame(
			[StalenessComputer::OK, StalenessComputer::STALE],
			array_column(StalenessComputer::analyze($changed, $fresh), 'status')
		);
	}

	public function testAnalyzeReportsMissingAndOrphanUnits() {
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$translation = "<!--T:9 @00000000-->\nOrphan.\n";
		$statuses = array_column(StalenessComputer::analyze($source, $translation), 'status');
		$this->assertContains(StalenessComputer::UNTRANSLATED, $statuses);
		$this->assertContains(StalenessComputer::ORPHAN, $statuses);
	}

	public function testScaffoldWritesEmptyMarkersCountedAsUntranslated() {
		$source = "<translate>\n<!--T:1-->\nHello.\n\n<!--T:2-->\nWorld.\n</translate>";
		$skeleton = StalenessComputer::scaffold($source);
		$this->assertSame("<!--T:1-->\n\n<!--T:2-->\n\n", $skeleton);
		$this->assertSame(
			[StalenessComputer::UNTRANSLATED, StalenessComputer::UNTRANSLATED],
			array_column(StalenessComputer::analyze($source, $skeleton), 'status')
		);
	}

	public function testScaffoldKeepsTranslatedUnitsAndAppendsOnlyNewOnes() {
		$source = "<translate>\n<!--T:1-->\nHello.\n\n<!--T:2-->\nWorld.\n</translate>";
		$existing = "<!--T:1-->\n안녕.\n";
		$this->assertSame(
			"<!--T:1-->\n안녕.\n\n<!--T:2-->\n\n",
			StalenessComputer::scaffold($source, $existing)
		);
	}

	public function testAFilledUnitBesideAnEmptyOneIsTranslatedNotUntranslated() {
		$source = "<translate>\n<!--T:1-->\nHello.\n\n<!--T:2-->\nWorld.\n</translate>";
		// T:1 filled and stamped, T:2 left empty by the scaffold.
		$partial = StalenessComputer::restamp($source, "<!--T:1-->\n안녕.\n\n<!--T:2-->\n\n");
		$this->assertSame(
			[StalenessComputer::OK, StalenessComputer::UNTRANSLATED],
			array_column(StalenessComputer::analyze($source, $partial), 'status')
		);
	}

	public function testSplitUnitsIgnoresMarkersShownInsideACodeExample() {
		// The page documenting page translation shows <!--T:n--> markers in code examples; those are
		// not real units, so a marker inside a verbatim span never becomes one.
		$text =
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n\n"
			. "<syntaxhighlight lang=\"wikitext\">\n<!--T:2-->\nExample.\n</syntaxhighlight>";
		$this->assertSame([1], array_keys(StalenessComputer::splitUnits($text)));
	}

	public function testMarkLeavesATranslatePairShownInsideACodeExampleUntouched() {
		// A <translate>...</translate> pair inside a code example must be copied verbatim, and the
		// <!--T:1--> it contains must not push the real unit's number along.
		$example =
			"<syntaxhighlight lang=\"wikitext\">\n"
			. "<translate>\n<!--T:1-->\nExample.\n</translate>\n</syntaxhighlight>";
		$this->assertSame(
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n\n" . $example,
			StalenessComputer::mark("<translate>\nReal.\n</translate>\n\n" . $example)
		);
	}

	public function testAnalyzeIgnoresAnExampleMarkerInACodeBlock() {
		// A translation covering only the real unit is fully up to date; the example marker shown in
		// the code block is neither a missing source unit nor an orphan.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>\n" . "<pre>\n<!--T:2-->\nExample.\n</pre>";
		$fresh = StalenessComputer::restamp($source, "<!--T:1-->\n안녕.\n");
		$this->assertSame(
			[StalenessComputer::OK],
			array_column(StalenessComputer::analyze($source, $fresh), 'status')
		);
	}
}
