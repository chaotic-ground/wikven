<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWikiUnitTestCase;

/**
 * The parts that read the source tree rather than the wiki: which files are translations, and of
 * what. The tag extraction they go through works without a service container, and the maintenance
 * scripts that call them run before there is one.
 *
 * @covers \MediaWiki\Extension\Wikven\PageTranslation\TranslationSource
 */
class TranslationSourceTest extends MediaWikiUnitTestCase {
	private string $directory;

	/** Every two-letter code, which is what a build hands these as the wiki's language list. */
	private function known(): callable {
		return static function (string $code): bool {
			return preg_match('/^[a-z]{2}$/', $code) === 1;
		};
	}

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-translation-source-' . getmypid() . '-' . uniqid();
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void {
		$this->remove($this->directory);
		parent::tearDown();
	}

	private function remove(string $path): void {
		if (is_dir($path)) {
			foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
				$this->remove("$path/$entry");
			}
			rmdir($path);
			return;
		}
		if (is_file($path)) {
			unlink($path);
		}
	}

	/** Write a file under the source directory, making the directories it sits in. */
	private function write(string $relative, string $text): void {
		$path = "$this->directory/$relative";
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}
		file_put_contents($path, $text);
	}

	private function translatable(string $title): string {
		return "<translate>\n<!--T:1-->\n$title.\n</translate>";
	}

	/** A translation is a sibling file named for its language and carrying the source's markers. */
	private function translation(string $text): string {
		return "<!--T:1-->\n$text.";
	}

	/**
	 * The language list is read from the files because there is no setting to read: a second list
	 * would be one to fall out of step with the tree.
	 */
	public function testTheLanguagesAreTheOnesTheTreeCarries() {
		$this->write('Installation.wikitext', $this->translatable('Installation'));
		$this->write('Installation/ko.wikitext', $this->translation('설치'));
		$this->write('Installation/km.wikitext', $this->translation('ការដំឡើង'));
		$this->write('Guide.wikitext', $this->translatable('Guide'));
		$this->write('Guide/ko.wikitext', $this->translation('안내'));

		$this->assertSame(
			['km', 'ko'],
			TranslationSource::languages($this->directory, $this->known())
		);
	}

	/** A page nobody has translated yet contributes no language, and neither does an empty tree. */
	public function testATreeWithNoTranslationsCarriesNoLanguages() {
		$this->write('Installation.wikitext', $this->translatable('Installation'));

		$this->assertSame([], TranslationSource::languages($this->directory, $this->known()));
	}

	/**
	 * Whatever else a page's directory holds is the page's own: an image it uses, a subpage named
	 * for something other than a language.
	 */
	public function testOnlyWikitextNamedForALanguageIsATranslation() {
		$this->write('Installation.wikitext', $this->translatable('Installation'));
		$this->write('Installation/ko.wikitext', $this->translation('설치'));
		$this->write('Installation/diagram.png', 'not wikitext');
		$this->write('Installation/Advanced.wikitext', 'a subpage of its own');
		$this->write('Installation/nested/ko.wikitext', 'not a translation either');

		$this->assertSame(
			['ko'],
			array_keys(TranslationSource::translationFiles(
				"$this->directory/Installation.wikitext",
				$this->known()
			))
		);
	}

	/**
	 * The magic word is localized, so every spelling in the content language is checked -- and
	 * where there is no wiki to ask, the English one still works. That is the case the maintenance
	 * scripts run in.
	 */
	public function testTheEnglishMagicWordIsReadWithoutAWikiToAsk() {
		$this->assertTrue(TranslationSource::hasFixedDisplayTitle('{{DISPLAYTITLE:Wikven}}'));
		$this->assertFalse(TranslationSource::hasFixedDisplayTitle('Nothing sets a title here.'));
	}
}
