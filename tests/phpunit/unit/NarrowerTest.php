<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Wikven\Hooks\Narrower;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Narrower
 */
class NarrowerTest extends MediaWikiUnitTestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-narrower-' . getmypid() . '-' . uniqid();
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

	private function write(string $relative, string $text): void {
		$path = "$this->directory/$relative";
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}
		file_put_contents($path, $text);
	}

	/** A page written in <translate>, and a translation of it carrying the same unit marker. */
	private function translatedInto(string ...$languages): void {
		$this->write('Page.wikitext', "<translate>\n<!--T:1-->\nHello.\n</translate>");
		foreach ($languages as $language) {
			$this->write("Page/$language.wikitext", "<!--T:1-->\nHello there.\n");
		}
	}

	private function narrower(string $source, string $documentation = ''): Narrower {
		$languageNameUtils = $this->createMock(LanguageNameUtils::class);
		$languageNameUtils->method('isKnownLanguageTag')
			->willReturnCallback(static function (string $code): bool {
				return preg_match('/^[a-z]{2}$/', $code) === 1;
			});
		$config = new HashConfig([
			'WikvenSourceDirectory' => $source,
			'LanguageCode' => 'en',
			'TranslateDocumentationLanguageCode' => $documentation
		]);
		return new Narrower($config, $languageNameUtils);
	}

	private function narrow(Narrower $narrower, array $list): array {
		$narrower->onTranslateSupportedLanguages($list, 'en');
		return $list;
	}

	private function everyLanguage(): array {
		return ['en' => 'English', 'ko' => 'Korean', 'km' => 'Khmer', 'fr' => 'French'];
	}

	public function testKeepsTheLanguagesTheSourceIsTranslatedInto() {
		$this->translatedInto('ko', 'km');
		$narrowed = $this->narrow($this->narrower($this->directory), $this->everyLanguage());
		$this->assertSame(['en', 'ko', 'km'], array_keys($narrowed));
	}

	public function testKeepsTheWikiLanguageWhereNothingIsTranslated() {
		$this->write('Page.wikitext', "Plain.\n");
		$narrowed = $this->narrow($this->narrower($this->directory), $this->everyLanguage());
		$this->assertSame(['en' => 'English'], $narrowed);
	}

	public function testKeepsTheCodeMessagesAreDocumentedUnder() {
		$this->translatedInto('ko');
		$narrower = $this->narrower($this->directory, 'qqq');
		$narrowed = $this->narrow($narrower, $this->everyLanguage() + ['qqq' => 'Documentation']);
		$this->assertSame(['en', 'ko', 'qqq'], array_keys($narrowed));
	}

	public function testLeavesTheListAloneWithoutASourceTree() {
		$narrowed = $this->narrow($this->narrower(''), $this->everyLanguage());
		$this->assertSame($this->everyLanguage(), $narrowed);
	}

	public function testLeavesTheListAloneWhereTheSourceDirectoryIsNotThere() {
		$narrowed = $this->narrow($this->narrower("$this->directory/gone"), $this->everyLanguage());
		$this->assertSame($this->everyLanguage(), $narrowed);
	}

	public function testReadsTheSourceTreeOnce() {
		$this->translatedInto('ko');
		$narrower = $this->narrower($this->directory);
		$this->narrow($narrower, $this->everyLanguage());
		$this->translatedInto('ko', 'fr');
		$this->assertSame(['en', 'ko'], array_keys($this->narrow($narrower, $this->everyLanguage())));
	}
}
