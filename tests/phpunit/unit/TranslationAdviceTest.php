<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice
 */
class TranslationAdviceTest extends MediaWikiUnitTestCase {
	/**
	 * The advice with the real English messages behind it, so what is asserted below is the wording
	 * a contributor actually reads, and a key nobody added to i18n/en.json fails the test.
	 *
	 * A language other than English is answered with the same text under a tag, standing in for a
	 * translation of the messages: enough to see that each language gets its own rendering.
	 */
	private function advice(): TranslationAdvice {
		$messages = json_decode(file_get_contents(__DIR__ . '/../../../i18n/en.json'), true);
		return new TranslationAdvice(
			static function (string $key, string $language, array $parameters) use ($messages): string {
				$text = $messages[$key] ?? "⧼$key⧽";
				foreach ($parameters as $index => $parameter) {
					$text = str_replace('$' . ( $index + 1 ), (string)$parameter, $text);
				}
				return $language === 'en' ? $text : "[$language] $text";
			}
		);
	}

	public function testNothingFoundIsNoComment() {
		$this->assertNull($this->advice()->comment([]));
	}

	public function testItCarriesTheMarkerSoTheActionFindsItsOwnComment() {
		$body = $this->advice()->comment([
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '3', 'lang' => 'ko']
		]);
		$this->assertStringContainsString(TranslationAdvice::MARKER, $body);
		$this->assertStringContainsString(TranslationAdvice::MARKER, $this->advice()->allClear());
	}

	public function testAUnitIsListedUnderItsFileWithItsLanguage() {
		$body = $this->advice()->comment([
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '3', 'lang' => 'ko'],
			['kind' => 'stale', 'file' => 'docs/Pages/ko.wikitext', 'unit' => '7', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('- `docs/Pages/ko.wikitext` — T:3 (ko); T:7 (ko)', $body);
	}

	/** A finding on a source page has no language to name, only the unit. */
	public function testASourceUnitIsListedWithoutALanguage() {
		$body = $this->advice()->comment([
			['kind' => 'reserved', 'file' => 'docs/Pages.wikitext', 'unit' => 'title']
		]);
		$this->assertStringContainsString('- `docs/Pages.wikitext` — T:title' . "\n", $body);
	}

	/** The command is the part a contributor cannot guess, so each group has to name one. */
	public function testEachGroupSaysWhatToRun() {
		$stale = $this->advice()->comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('translate stamp', $stale);

		$unmarked = $this->advice()->comment([
			['kind' => 'unmarked', 'file' => 'a.wikitext', 'detail' => '2 unit(s)']
		]);
		$this->assertStringContainsString('translate mark', $unmarked);
		$this->assertStringContainsString('2 unit(s)', $unmarked);

		$orphan = $this->advice()->comment([
			['kind' => 'orphan', 'file' => 'a/ko.wikitext', 'unit' => '9', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('translate scaffold', $orphan);
	}

	public function testStalenessAloneSaysItFailsNothing() {
		$body = $this->advice()->comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko'],
			['kind' => 'untranslated', 'file' => 'a/ko.wikitext', 'unit' => '2', 'lang' => 'ko']
		]);
		$this->assertStringContainsString('None of this fails the check', $body);
	}

	public function testABrokenPageSaysWhatCanFail() {
		$body = $this->advice()->comment([
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
		$body = $this->advice()->comment([
			['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko'],
			['kind' => 'parse', 'file' => 'a.wikitext', 'detail' => 'pt-shake-position']
		]);
		$this->assertLessThan(
			strpos($body, 'Translated, but not stamped'),
			strpos($body, 'The source page cannot be read')
		);
	}

	public function testAKindNobodyReportsIsNotAHeading() {
		$this->assertNull($this->advice()->comment([
			['kind' => 'ok', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']
		]));
	}

	/** The point of the messages: the contributor reads the same advice in their own language. */
	public function testEachLanguageGetsItsOwnRendering() {
		$findings = [['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']];
		$body = $this->advice()->comment($findings, ['en', 'ko']);
		$this->assertSame(1, substr_count($body, '## Translations in this pull request'));
		$this->assertStringContainsString('[ko] Translations in this pull request', $body);
		// English leads, and the two renderings are told apart rather than run together.
		$this->assertLessThan(strpos($body, '[ko]'), strpos($body, "\n---\n"));

		$clear = $this->advice()->allClear(['en', 'ko']);
		$this->assertStringContainsString('[ko] `translate check` found nothing', $clear);
	}

	/** An untranslated language falls back to English, and saying it all twice reads as a bug. */
	public function testALanguageThatFallsBackIsNotRepeated() {
		$advice = new TranslationAdvice(static function (string $key): string {
			return "text of $key";
		});
		$body = $advice->comment(
			[['kind' => 'stale', 'file' => 'a/ko.wikitext', 'unit' => '1', 'lang' => 'ko']],
			['en', 'ko']
		);
		$this->assertStringNotContainsString("\n---\n", $body);
		$this->assertSame(1, substr_count($body, 'text of wikven-translations-title'));
	}
}
