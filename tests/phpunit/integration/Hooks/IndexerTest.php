<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration\Hooks;

use MediaWiki\Extension\Wikven\Hooks\Indexer;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * The database is for the one test below that lets the handler ask Translate for real: the answer
 * comes from whether the page above the one asked about is marked for translation, which is a
 * question for the database. MediaWiki reads this group from the class comment and nowhere else,
 * so it is declared here rather than on that test.
 *
 * @covers \MediaWiki\Extension\Wikven\Hooks\Indexer
 * @group Database
 */
class IndexerTest extends MediaWikiIntegrationTestCase {
	/**
	 * The rule, with the lookup stood in for: a translation page in the language it was translated
	 * from restates its source page and is left out; anything else stays (#454).
	 *
	 * @dataProvider provideTranslationPages
	 */
	public function testOnlyASourceLanguageTranslationIsLeftOut(
		string $title,
		?string $sourceLanguage,
		bool $expected
	) {
		$indexer = new Indexer(static function () use ($sourceLanguage): ?string {
			return $sourceLanguage;
		});

		$index = true;
		$indexer->onSifterSearchIndexPage(Title::makeTitle(NS_MAIN, $title), $index);

		$this->assertSame($expected, $index);
	}

	public static function provideTranslationPages(): array {
		return [
			// The duplicate: same English text as "Installation", under a second title.
			'a translation into the source language' => ['Installation/en', 'en', false],
			// A reader searching in Korean has nowhere else to find this one.
			'a translation into another language' => ['Installation/ko', 'en', true],
			'a translation of a Korean-sourced page' => ['설치/ko', 'ko', false],
			// null is the lookup saying "not a translation page", which is every ordinary page --
			// including a subpage somebody merely named after a language.
			'a page that is not a translation' => ['Installation', null, true],
			'a subpage named after a language, but not marked' => ['Installation/en', null, true],
			// A source page keeps its place even where its own language is the one being asked
			// about: it has no subpage segment to match, so nothing here can catch it.
			'a source page whose language matches' => ['Installation', 'en', true]
		];
	}

	/**
	 * Built the way MediaWiki builds it, the handler asks Translate rather than a stand-in. Neither
	 * answer may cost a page its place: where Translate is absent the lookup says so and stops, and
	 * where it is installed, a page nobody marked for translation is not a translation page. A wiki
	 * without content translation must not be a wiki that loses pages.
	 *
	 * Both answers are reached, because the jobs differ in what they install: the quibble jobs bring
	 * Translate along (quibble.yml names it), the phpunit jobs install MediaWiki alone.
	 */
	public function testTheHandlerAsBuiltLeavesAnUnmarkedPageAlone() {
		$index = true;
		( new Indexer() )->onSifterSearchIndexPage(Title::makeTitle(NS_MAIN, 'Installation/en'), $index);

		$this->assertTrue($index);
	}
}
