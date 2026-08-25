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
		$this->assertSame(1, substr_count($body, '## Translations in this change'));
		$this->assertStringContainsString('[ko] Translations in this change', $body);
		// English leads, and the two renderings are told apart rather than run together.
		$this->assertLessThan(strpos($body, '[ko]'), strpos($body, "\n---\n"));

		$clear = $this->advice()->allClear(['en', 'ko']);
		$this->assertStringContainsString('[ko] `translate check` found nothing', $clear);
	}

	/**
	 * A check reads the whole tree; a comment on one change should not. A page that fell behind
	 * long before this change belongs to the annotations, not to whoever is reading this diff.
	 */
	public function testAScopedCommentIsOnlyAboutWhatTheChangeTouches() {
		$body = $this->advice()
			->about(['docs/Pages/ko.wikitext'])
			->comment([
				[
					'kind' => 'stale',
					'file' => 'docs/Pages/ko.wikitext',
					'source' => 'docs/Pages.wikitext',
					'unit' => '3',
					'lang' => 'ko'
				],
				[
					'kind' => 'stale',
					'file' => 'docs/Licenses/km.wikitext',
					'source' => 'docs/Licenses.wikitext',
					'unit' => '2',
					'lang' => 'km'
				]
			]);
		$this->assertStringContainsString('docs/Pages/ko.wikitext', $body);
		$this->assertStringNotContainsString('km.wikitext', $body);
		// And says so, rather than letting the reader think the rest of the wiki is clean.
		$this->assertStringContainsString('the pages this change touches', $body);
	}

	/**
	 * Editing an English page is what puts its translations behind, so the person who edited it
	 * is told about them even though they never opened the translation file.
	 */
	public function testTouchingASourcePageCarriesItsTranslations() {
		$body = $this->advice()
			->about(['docs/Pages.wikitext'])
			->comment([
				[
					'kind' => 'stale',
					'file' => 'docs/Pages/ko.wikitext',
					'source' => 'docs/Pages.wikitext',
					'unit' => '3',
					'lang' => 'ko'
				]
			]);
		$this->assertStringContainsString('docs/Pages/ko.wikitext', $body);
	}

	/**
	 * And the other way round: a source page nobody can read is why a translation of it renders
	 * as English, so whoever sent that translation is owed the reason.
	 */
	public function testTouchingATranslationCarriesItsSourcePage() {
		$body = $this->advice()
			->about(['docs/Pages/ko.wikitext'])
			->comment([
				['kind' => 'parse', 'file' => 'docs/Pages.wikitext', 'detail' => 'pt-shake-position']
			]);
		$this->assertStringContainsString('docs/Pages.wikitext', $body);
	}

	public function testAChangeThatTouchesNoneOfThemIsToldNothingIsWrongWithIt() {
		$advice = $this->advice()->about(['docs/index.wikitext']);
		$this->assertNull($advice->comment([
			[
				'kind' => 'stale',
				'file' => 'docs/Licenses/km.wikitext',
				'source' => 'docs/Licenses.wikitext',
				'unit' => '2',
				'lang' => 'km'
			]
		]));
		// The all-clear is narrowed with it: the wiki may well have pages waiting for a
		// translation, and this comment is in no position to say otherwise.
		$this->assertStringContainsString('about this change', $advice->allClear());
		$this->assertStringContainsString('every source page', $this->advice()->allClear());
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
