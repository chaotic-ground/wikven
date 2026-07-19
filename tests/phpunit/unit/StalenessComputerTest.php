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
}
