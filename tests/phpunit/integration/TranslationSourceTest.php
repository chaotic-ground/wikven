<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Extension\Wikven\SourceFile;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationSource
 */
class TranslationSourceTest extends MediaWikiIntegrationTestCase {
	/**
	 * isTranslatable() uses the MediaWiki parser's tag extraction, so this is an integration test.
	 *
	 * @dataProvider provideIsTranslatable
	 */
	public function testIsTranslatable(string $text, bool $expected) {
		$this->assertSame($expected, TranslationSource::isTranslatable($text));
	}

	public static function provideIsTranslatable(): array {
		return [
			'a real translate block' => ["<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>", true],
			// containsMarkup() matches any translate tag, so nowrap is one it offers for marking.
			'a translate block with an attribute' => [
				"<languages/>\n<translate nowrap>\n<!--T:1-->\nHi.\n</translate>",
				true
			],
			'a tag that merely starts the same way' => ['<translatex>Hi.</translatex>', false],
			'plain text' => ['Just prose, no markup.', false],
			// Translate's parser hook runs on raw wikitext, so only <nowiki> hides a tag from it.
			'translate inside syntaxhighlight' => [
				"x\n<syntaxhighlight>\n<translate>\n<!--T:1-->\nBody\n</translate>\n</syntaxhighlight>",
				true
			],
			'translate inside nowiki' => ['Wrap it in <code><nowiki><translate></nowiki></code>.', false],
			'translate inside pre' => ['<pre><translate>example</translate></pre>', true],
			'a real block beside one shown in nowiki' => [
				"<translate>Real</translate>\n<nowiki><translate>x</translate></nowiki>",
				true
			]
		];
	}

	/**
	 * hasFixedDisplayTitle() shares isTranslatable()'s tag extraction, so it is an integration test too.
	 *
	 * @dataProvider provideHasFixedDisplayTitle
	 */
	public function testHasFixedDisplayTitle(string $text, bool $expected) {
		$this->assertSame($expected, TranslationSource::hasFixedDisplayTitle($text));
	}

	public static function provideHasFixedDisplayTitle(): array {
		return [
			'no magic word' => ["<translate>\n<!--T:1-->\nHi.\n</translate>", false],
			'a real magic word' => ["<translate>Hi.</translate>\n{{DISPLAYTITLE:Wikven}}", true],
			'spaced out' => ['{{ DISPLAYTITLE : Wikven }}', true],
			// The page documenting page translation shows the magic word; that is prose, not a title.
			'shown inside nowiki' => ['Set it with <nowiki>{{DISPLAYTITLE:Wikven}}</nowiki>.', false],
			'shown inside syntaxhighlight' => [
				"<syntaxhighlight lang=\"wikitext\">\n{{DISPLAYTITLE:Wikven}}\n</syntaxhighlight>",
				false
			]
		];
	}

	public function testBaseFilesLeaveOutATranslationThatQuotesATranslateTag() {
		// A <translate> a translation quotes is as real to Translate as to wikven, so nothing
		// downstream would object to "Intro/ko" being marked as a translatable page of its own.
		$dir = sys_get_temp_dir() . '/wikven-base-files-' . uniqid();
		$page = "<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>";
		mkdir("$dir/Intro", 0777, true);
		file_put_contents("$dir/Intro.wikitext", $page);
		file_put_contents("$dir/Intro/ko.wikitext", "<!--T:1-->\n안녕. 이렇게 씁니다: $page");
		// A subpage whose name is not a language code is a page in its own right, not a translation.
		file_put_contents("$dir/Intro/Details.wikitext", $page);

		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];
		$found = TranslationSource::baseFiles($dir, $isKnownLanguage);

		array_map('unlink', glob("$dir/Intro/*") ?: []);
		array_map('unlink', glob("$dir/*.wikitext") ?: []);
		rmdir("$dir/Intro");
		rmdir($dir);

		$this->assertSame(["$dir/Intro.wikitext", "$dir/Intro/Details.wikitext"], $found);
	}

	/**
	 * A page reaches its translations by the paths they were found at, because a page title cannot
	 * be turned back into one: "Getting_Started.wikitext" imports as "Getting Started", and a path
	 * rebuilt from that title points at a directory the source tree does not have, so the build
	 * would find no Korean to load and say nothing about it.
	 */
	public function testATranslationIsFoundAtThePathItsLanguageWasDiscoveredAt() {
		$dir = $this->getNewTempDirectory();
		mkdir("$dir/Getting_Started");
		file_put_contents("$dir/Getting_Started.wikitext", "<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>");
		file_put_contents("$dir/Getting_Started/ko.wikitext", "<!--T:1-->\n안녕하세요.");

		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];
		$files = TranslationSource::translationFiles("$dir/Getting_Started.wikitext", $isKnownLanguage);

		$this->assertSame(['ko' => "$dir/Getting_Started/ko.wikitext"], $files);
		$this->assertFileExists($files['ko'], 'the translation is where the language was discovered');
		$this->assertSame(
			'Getting Started',
			Title::newFromText(SourceFile::filenameToTitle('Getting_Started.wikitext'))?->getPrefixedText(),
			'the file name does not survive title normalization'
		);
	}

	/**
	 * Hundreds of language codes are also ordinary English words -- "id", "no", "is", "it", "as",
	 * "be" -- so a name alone had a translatable page's "API/id" subpage read as an Indonesian
	 * translation, and the page then went missing from the built site with nothing said about it.
	 * The unit markers are what settle it: a page written to stand on its own has no reason to
	 * carry a source page's unit numbers.
	 */
	public function testASubpageNamedForALanguageIsAPageOfItsOwnWithoutMarkers() {
		$dir = $this->getNewTempDirectory();
		mkdir("$dir/API");
		file_put_contents("$dir/API.wikitext", "<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>");
		// What "id" means here is an identifier, and the page says so in English.
		file_put_contents("$dir/API/id.wikitext", 'An id names one thing.');
		file_put_contents("$dir/API/ko.wikitext", "<!--T:1-->\n안녕하세요.");

		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		$this->assertSame(
			['ko'],
			TranslationSource::translationLanguages("$dir/API.wikitext", $isKnownLanguage),
			'only the marked file is a translation'
		);
		$this->assertSame(
			['id' => "$dir/API/id.wikitext"],
			TranslationSource::pagesNamedForALanguage("$dir/API.wikitext", $isKnownLanguage),
			'the unmarked one is reported as a page of its own'
		);
		$this->assertContains(
			"$dir/API/id.wikitext",
			TranslationSource::baseFiles($dir, $isKnownLanguage),
			'and it reaches the site, which is the whole point'
		);
		$this->assertFalse(
			TranslationSource::isTranslationFile("$dir/API/id.wikitext", $isKnownLanguage)
		);
		$this->assertTrue(
			TranslationSource::isTranslationFile("$dir/API/ko.wikitext", $isKnownLanguage)
		);
	}

	/**
	 * The cost the marker rule carries, kept in view: a translation written by hand, without
	 * running translate scaffold, is imported as an ordinary subpage. It is visible in the built
	 * site under its own name rather than lost, and translate check says so.
	 */
	public function testAnUnmarkedTranslationIsImportedUnderItsOwnName() {
		$dir = $this->getNewTempDirectory();
		mkdir("$dir/Pages");
		file_put_contents("$dir/Pages.wikitext", "<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>");
		file_put_contents("$dir/Pages/ko.wikitext", '안녕하세요.');

		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		$this->assertSame([], TranslationSource::translationLanguages("$dir/Pages.wikitext", $isKnownLanguage));
		$this->assertSame(
			['ko' => "$dir/Pages/ko.wikitext"],
			TranslationSource::pagesNamedForALanguage("$dir/Pages.wikitext", $isKnownLanguage)
		);
	}

	/** @dataProvider provideTranslatableTitle */
	public function testTranslatableTitle(string $baseFile, string $text, ?string $expected) {
		$this->assertSame($expected, TranslationSource::translatableTitle($baseFile, '/src', $text));
	}

	public static function provideTranslatableTitle(): array {
		$page = "<translate>\n<!--T:1-->\nHi.\n</translate>";
		return [
			'the title the file imports as' => ['/src/Getting Started.wikitext', $page, 'Getting Started'],
			'a one-word title' => ['/src/Pages.wikitext', $page, 'Pages'],
			'a namespaced page keeps its prefix' => [
				'/src/MediaWiki:Sidebar-docs.wikitext',
				$page,
				'MediaWiki:Sidebar-docs'
			],
			// A page that fixes its own display title fixes it in every language, so nothing is asked for.
			'a page with its own display title' => [
				'/src/index.wikitext',
				$page . "\n{{DISPLAYTITLE:Wikven}}",
				null
			],
			// Nothing to be relative to, so no title can be derived.
			'a file outside the source directory' => ['/elsewhere/Pages.wikitext', $page, null]
		];
	}
}
