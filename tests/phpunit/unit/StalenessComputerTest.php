<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use InvalidArgumentException;
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

	public function testMarkDoesNotSplitOnALineThatOnlyLooksBlank() {
		// Translate's sectioniser needs the two newlines adjacent, so it reads this as one unit.
		$this->assertSame(
			"<translate>\n<!--T:1-->\nFirst.\n \nSecond.\n</translate>",
			StalenessComputer::mark("<translate>\nFirst.\n \nSecond.\n</translate>")
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

	public function testScaffoldPutsThePageTitleAheadOfTheNumberedUnits() {
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$this->assertSame(
			"<!--T:title-->\n\n<!--T:1-->\n\n",
			StalenessComputer::scaffold($source, null, 'Getting Started')
		);
	}

	public function testAnUnfilledPageTitleIsUntranslated() {
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$skeleton = StalenessComputer::scaffold($source, null, 'Getting Started');
		$this->assertSame(
			[
				['id' => StalenessComputer::TITLE_UNIT_ID, 'status' => StalenessComputer::UNTRANSLATED],
				['id' => '1', 'status' => StalenessComputer::UNTRANSLATED]
			],
			StalenessComputer::analyze($source, $skeleton, 'Getting Started')
		);
	}

	public function testRenamingThePageMakesTheTranslatedTitleStale() {
		// The title unit's source text is the page title itself, so a rename is what dates it, the
		// way editing a paragraph dates the unit that holds it.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$draft = "<!--T:title-->\n시작하기\n\n<!--T:1-->\n안녕.\n";
		$translation = StalenessComputer::restamp($source, $draft, 'Getting Started');
		$this->assertStringContainsString('<!--T:title @', $translation);
		$this->assertSame(
			[StalenessComputer::OK, StalenessComputer::OK],
			array_column(StalenessComputer::analyze($source, $translation, 'Getting Started'), 'status')
		);
		$this->assertSame(
			[StalenessComputer::STALE, StalenessComputer::OK],
			array_column(StalenessComputer::analyze($source, $translation, 'Starting Out'), 'status')
		);
	}

	public function testATitleUnitIsAnOrphanWhenThePageTitleIsNotTranslatable() {
		// A page that fixes its own display title asks for no title unit, so one left in a
		// translation is reported rather than silently applied.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$translation = StalenessComputer::restamp($source, "<!--T:title @00000000-->\n시작하기\n\n<!--T:1-->\n안녕.\n");
		$this->assertSame(
			[
				['id' => '1', 'status' => StalenessComputer::OK],
				['id' => StalenessComputer::TITLE_UNIT_ID, 'status' => StalenessComputer::ORPHAN]
			],
			StalenessComputer::analyze($source, $translation)
		);
	}

	public function testSourceUnitsAddTheTitleOnlyWhenOneIsGiven() {
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$this->assertSame([1], array_keys(StalenessComputer::sourceUnits($source)));
		$units = StalenessComputer::sourceUnits($source, 'Getting Started');
		$this->assertSame([StalenessComputer::TITLE_UNIT_ID, 1], array_keys($units));
		$this->assertSame('Getting Started', $units[StalenessComputer::TITLE_UNIT_ID]['text']);
	}

	public function testASourcePageMayNotMarkAUnitWithTheReservedTitleId() {
		// mark() only ever writes numbers, but the marker grammar accepts any alphanumeric id, so a
		// hand-written <!--T:title--> can reach here. Merging it with the page title would drop one
		// of the two without a word, so it is refused instead.
		$source = "<translate>\n<!--T:title-->\nReal content, not a page title.\n</translate>";
		$this->assertTrue(StalenessComputer::usesReservedId($source));

		$this->expectException(InvalidArgumentException::class);
		StalenessComputer::sourceUnits($source, 'Some Page');
	}

	public function testTheReservedIdIsRefusedEvenWithoutATranslatableTitle() {
		// A page that fixes its own display title has no title unit today, but it must not be able to
		// keep a unit that would vanish the moment the page gained one.
		$source = "<translate>\n<!--T:title-->\nReal content, not a page title.\n</translate>";
		$this->expectException(InvalidArgumentException::class);
		StalenessComputer::sourceUnits($source);
	}

	public function testTheReservedIdShownInACodeExampleIsNotAClaim() {
		// The page documenting page translation shows <!--T:title--> in a <nowiki> example; that is
		// prose, so it neither trips the check nor blocks the page's own title unit.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>\n<nowiki><!--T:title-->\n소개</nowiki>";
		$this->assertFalse(StalenessComputer::usesReservedId($source));
		$this->assertSame(
			[StalenessComputer::TITLE_UNIT_ID, 1],
			array_keys(StalenessComputer::sourceUnits($source, 'Getting Started'))
		);
	}

	public function testScaffoldAddsTheTitleToATranslationThatPredatesIt() {
		// Re-scaffolding an existing translation appends the missing title marker, as it does for any
		// other new unit, without disturbing what is already translated.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$this->assertSame(
			"<!--T:1-->\n안녕.\n\n<!--T:title-->\n\n",
			StalenessComputer::scaffold($source, "<!--T:1-->\n안녕.\n", 'Getting Started')
		);
	}

	public function testASourceMarkerOutsideEveryBlockIsNotAUnit() {
		// Translate reads markers only inside a <translate> block, so one shown in a code example --
		// as the page documenting page translation does -- is prose wherever it sits.
		$text =
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n\n"
			. "<syntaxhighlight lang=\"wikitext\">\n<!--T:2-->\nExample.\n</syntaxhighlight>";
		$this->assertSame([1], array_keys(StalenessComputer::sourceUnits($text)));
	}

	public function testASourceBlockInsideACodeExampleStillCounts() {
		// Translate's parser hook runs before <syntaxhighlight> is stripped, so a whole pair written
		// there is one it parses.
		$text = "<syntaxhighlight>\n<translate>\n<!--T:1-->\nBody\n</translate>\n</syntaxhighlight>";
		$this->assertSame([1], array_keys(StalenessComputer::sourceUnits($text)));
	}

	public function testNowikiInsideABlockHidesNothing() {
		// parse() unarmours a block's contents before segmenting them, so <nowiki> shelters a marker
		// from the block scan but not from the unit scan. Translate rejects the page; wikven must see
		// the same two units for the check to be able to say so.
		$text = "<translate>\n<!--T:1-->\nUse <nowiki><!--T:9--></nowiki> here.\n</translate>";
		$this->assertSame([1, 9], array_keys(StalenessComputer::sourceUnits($text)));
	}

	public function testAWholeBlockArmouredByNowikiIsInvisible() {
		// armourNowiki() runs before the block scan, so a pair shown in <nowiki> is no block at all --
		// and its <!--T:1--> must not shadow the real unit 1, which the body assertion is what catches.
		$text =
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n"
			. "<nowiki><translate>\n<!--T:1-->\nShown.\n</translate></nowiki>";
		$units = StalenessComputer::sourceUnits($text);
		$this->assertSame([1], array_keys($units));
		$this->assertStringStartsWith("\nReal.\n", $units[1]['text']);
	}

	public function testMarkNumbersABlockShownInACodeExample() {
		// Translate numbers it and saves the result back to the wiki, so wikven must number it too.
		$example = "<syntaxhighlight>\n<translate>\nExample.\n</translate>\n</syntaxhighlight>";
		$this->assertSame(
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n\n"
			. "<syntaxhighlight>\n<translate>\n<!--T:2-->\nExample.\n</translate>\n</syntaxhighlight>",
			StalenessComputer::mark("<translate>\nReal.\n</translate>\n\n" . $example)
		);
	}

	public function testMarkLeavesABlockArmouredByNowikiAlone() {
		$text = "<translate>\nReal.\n</translate>\n<nowiki><translate>\nShown.\n</translate></nowiki>";
		$this->assertSame(
			"<translate>\n<!--T:1-->\nReal.\n</translate>\n<nowiki><translate>\nShown.\n</translate></nowiki>",
			StalenessComputer::mark($text)
		);
	}

	public function testAnUnclosedCodeTagDoesNotStopLaterBlocksBeingRead() {
		// Only <nowiki> armours anything for Translate, so an unclosed <pre> earlier on the page leaves
		// every later block, and every marker in one, exactly as real.
		$text = "<pre>\n<translate>\nShown.\n</translate>\n<translate>\n<!--T:4-->\nReal.\n</translate>";
		$this->assertSame([4], array_keys(StalenessComputer::sourceUnits($text)));
		$this->assertStringEndsWith(
			"<translate>\n<!--T:4-->\nReal.\n</translate>",
			StalenessComputer::mark($text)
		);
	}

	public function testSourceUnitsSpanSeveralBlocksAndIgnoreMarkersBetweenThem() {
		// Each block contributes its own units; a marker written between two of them belongs to
		// neither, so the numbering of the real ones is untouched.
		$text =
			"<translate>\n<!--T:1-->\nOne.\n</translate>\n"
			. "<pre>\n<!--T:8-->\nExample.\n</pre>\n"
			. "<translate>\n<!--T:2-->\nTwo.\n</translate>\n"
			. "<nowiki><!--T:9--></nowiki>\n"
			. "<translate>\n<!--T:3-->\nThree.\n</translate>";
		$this->assertSame([1, 2, 3], array_keys(StalenessComputer::sourceUnits($text)));
	}

	public function testASourceUnitEndsWhereItsBlockDoes() {
		// Translate segments each block's contents on their own, so the last unit of one stops at
		// </translate> instead of running on to the next marker and taking the page furniture with it.
		$text =
			"<translate>\n<!--T:1-->\nOne.\n</translate>\n"
			. "{{DISPLAYTITLE:Wikven}}\n"
			. "<translate>\n<!--T:2-->\nTwo.\n</translate>\n[[Category:Docs]]";
		$units = StalenessComputer::sourceUnits($text);
		$this->assertSame("\nOne.\n", $units[1]['text']);
		$this->assertSame("\nTwo.\n", $units[2]['text']);
	}

	public function testEditingOutsideEveryBlockStalesNothing() {
		// What the unit bound is for: a category or a template call is not part of any unit, so
		// changing one must not send every translation of the page back to the translator.
		$before = "<translate>\n<!--T:1-->\nHello.\n</translate>\n[[Category:Docs]]";
		$after = "<translate>\n<!--T:1-->\nHello.\n</translate>\n[[Category:Guides]]";
		$translation = StalenessComputer::restamp($before, "<!--T:1-->\n안녕.\n");
		$this->assertSame(
			[StalenessComputer::OK],
			array_column(StalenessComputer::analyze($after, $translation), 'status')
		);
	}

	public function testAnalyzeIgnoresAnExampleMarkerInACodeBlock() {
		// A translation covering only the real unit is fully up to date; the example marker shown in
		// the code block is neither a missing source unit nor an orphan.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>\n<pre>\n<!--T:2-->\nExample.\n</pre>";
		$fresh = StalenessComputer::restamp($source, "<!--T:1-->\n안녕.\n");
		$this->assertSame(
			[StalenessComputer::OK],
			array_column(StalenessComputer::analyze($source, $fresh), 'status')
		);
	}

	public function testRestampLeavesAMarkerTheTranslationMerelyShowsAlone() {
		// Stamping one would edit the sentence around it whenever its id matched a real unit.
		$source = "<translate>\n<!--T:1-->\nHello.\n</translate>";
		$translation = "<!--T:1-->\n안녕.\n\n<code><nowiki><!--T:1--></nowiki></code> 표시자.";
		$this->assertStringEndsWith(
			'<code><nowiki><!--T:1--></nowiki></code> 표시자.',
			StalenessComputer::restamp($source, $translation)
		);
	}

	/**
	 * A translation file carries bare markers and no <translate> block, so it is wikven's own format:
	 * every verbatim tag hides a marker there, not just the <nowiki> Translate armours.
	 *
	 * @dataProvider provideVerbatimTags
	 */
	public function testTranslationUnitsIgnoreMarkersInsideEveryVerbatimTag(string $example) {
		$text = "<!--T:1-->\n안녕.\n\n" . $example;
		$this->assertSame([1], array_keys(StalenessComputer::translationUnits($text)));
	}

	public static function provideVerbatimTags(): array {
		return [
			'nowiki' => ["<nowiki><!--T:2-->\nExample.</nowiki>"],
			'source' => ["<source lang=\"wikitext\">\n<!--T:2-->\nExample.\n</source>"],
			'pre' => ["<pre>\n<!--T:2-->\nExample.\n</pre>"],
			'syntaxhighlight' => ["<syntaxhighlight>\n<!--T:2-->\nExample.\n</syntaxhighlight>"],
			// MediaWiki tag names are case-insensitive, so the scan must be too.
			'uppercase' => ["<NOWIKI><!--T:2-->\nExample.</NOWIKI>"],
			'mixed case' => ["<SyntaxHighlight>\n<!--T:2-->\nExample.\n</SyntaxHighlight>"],
			'self-closing' => ['<nowiki />']
		];
	}

	public function testAnUnclosedVerbatimTagRunsToTheEndOfATranslation() {
		// MediaWiki renders an unclosed <nowiki> as verbatim to the end of the page, and reading it
		// that way is the safer failure mode: the text after it stays examples rather than becoming
		// counted units.
		$text = "<!--T:1-->\n안녕.\n<nowiki>\n<!--T:2-->\nStill an example.";
		$this->assertSame([1], array_keys(StalenessComputer::translationUnits($text)));
	}

	/**
	 * A closed or self-closing verbatim tag ends where it ends. Only a genuinely unclosed one runs
	 * on, so a later real unit is still counted.
	 *
	 * @dataProvider provideSelfContainedVerbatim
	 */
	public function testAClosedVerbatimSpanDoesNotSwallowLaterUnits(string $span) {
		$text = "<!--T:1-->\n하나.\n" . $span . "\n<!--T:2-->\n둘.";
		$this->assertSame([1, 2], array_keys(StalenessComputer::translationUnits($text)));
	}

	public static function provideSelfContainedVerbatim(): array {
		return [
			'self-closing' => ['<nowiki />'],
			'closed pair' => ['<nowiki>x</nowiki>'],
			'with attributes' => ['<syntaxhighlight lang="yaml">a</syntaxhighlight>']
		];
	}

	public function testACommentMerelyMentioningAVerbatimTagDoesNotSwallowLaterUnits() {
		// An HTML comment is inert to MediaWiki's parser, so a tag name it merely mentions -- as a
		// reviewer note might -- must not read as a real, unclosed opener; that would otherwise run
		// to the end of the page and hide every later unit.
		$text = "<!--T:1-->\n하나.\n<!-- reviewer: do not wrap this in <nowiki> -->\n<!--T:2-->\n둘.";
		$this->assertSame([1, 2], array_keys(StalenessComputer::translationUnits($text)));
	}
}
