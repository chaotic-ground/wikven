<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice
 */
class TranslationAdviceTest extends MediaWikiUnitTestCase {
	public function testNothingFoundIsNoComment() {
		$this->assertNull(TranslationAdvice::comment([]));
	}

	public function testItCarriesTheMarkerSoTheActionFindsItsOwnComment() {
		$body = TranslationAdvice::comment([
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '3', 'lang' => 'ko']
		]);
		$this->assertStringContainsString(TranslationAdvice::MARKER, $body);
		$this->assertStringContainsString(TranslationAdvice::MARKER, TranslationAdvice::allClear());
	}

	public function testAUnitIsListedUnderItsFileWithItsLanguage() {
		$body = TranslationAdvice::comment([
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '3', 'lang' => 'ko'],
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '7', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('- `docs/Pages/ko.wikitext` — T:3 (ko); T:7 (ko)', $body);
	}

	/** The command is the part a contributor cannot guess, so each group has to name one. */
	public function testEachGroupSaysWhatToRun() {
		$stale = TranslationAdvice::comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('translate stamp', $stale);

		$unmarked = TranslationAdvice::comment([
			['kind' => 'unmarked', 'file' => 'a.wikitext', 'detail' => '2 unit(s)']
		]);
		$this->assertStringContainsString('translate mark', $unmarked);
		$this->assertStringContainsString('2 unit(s)', $unmarked);

		$orphan = TranslationAdvice::comment([
			['kind' => 'orphan', 'file' => 'a/ko.wikitext', 'unit' => '9', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('translate scaffold', $orphan);
	}

	public function testStalenessAloneSaysItFailsNothing() {
		$body = TranslationAdvice::comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko'],
			['kind' => 'untranslated', 'file' => 'a/ko.wikitext', 'unit' => '2', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('None of this fails the check', $body);
	}

	public function testABrokenPageSaysWhatCanFail() {
		$body = TranslationAdvice::comment([
			['kind' => 'parse', 'file' => 'a.wikitext', 'detail' => 'pt-shake-position'],
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('can fail the check', $body);
		$this->assertStringContainsString('pt-shake-position', $body);
	}

	/**
	 * The order is the reader's, not the finder's: what stops a page being translated at all comes
	 * before what is only a translation of a page that is otherwise fine.
	 */
	public function testTheBrokenPageComesFirstHoweverItWasFound() {
		$body = TranslationAdvice::comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko'],
			['kind' => 'parse', 'file' => 'a.wikitext', 'detail' => 'pt-shake-position']
		]);
		$this->assertLessThan(
			strpos($body, 'Translated, but not stamped'),
			strpos($body, 'The source page cannot be read')
		);
	}

	public function testAKindNobodyReportsIsNotAHeading() {
		$this->assertNull(TranslationAdvice::comment([
			['kind' => 'ok', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']
		]));
	}
}
