<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration\Hooks;

use MediaWiki\Extension\Wikven\Hooks\Indexer;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Indexer
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
	 * Built the way MediaWiki builds it, the handler asks Translate, which this suite does not
	 * install -- so it finds no translation pages and leaves the index alone. That is also what a
	 * wiki without content translation gets, and it must not be a wiki that loses pages.
	 */
	public function testWithoutTranslateNothingIsLeftOut() {
		$index = true;
		( new Indexer() )->onSifterSearchIndexPage(Title::makeTitle(NS_MAIN, 'Installation/en'), $index);

		$this->assertTrue($index);
	}
}
