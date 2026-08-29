<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration\Hooks;

use MediaWiki\Extension\Wikven\Hooks\Declarer;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Declarer
 * @covers \MediaWiki\Extension\Wikven\LicensesPage
 */
class DeclarerTest extends MediaWikiIntegrationTestCase {
	/**
	 * The rule: a language copy the build wrote is in that language, and nothing else is touched.
	 *
	 * A page MediaWiki is not told about is in the content language, which is what sent a Korean
	 * page out as English (#561). The pages that need telling are the ones build.php writes under
	 * the licenses page, and they are the only ones this may answer for: everything below is
	 * either one of those or something with an owner of its own.
	 *
	 * @dataProvider provideTitles
	 */
	public function testOnlyTheBuildsOwnLanguageCopiesAreDeclared(string $title, ?string $expected) {
		$this->overrideConfigValue('WikvenLicensesPage', 'Licenses');

		$pageLang = $this->languageOf($title);

		$this->assertSame($expected ?? 'en', $pageLang->getCode());
	}

	public static function provideTitles(): array {
		return [
			'a copy the build wrote' => ['Licenses/ko', 'ko'],
			'a copy in a script with a bundled font' => ['Licenses/km', 'km'],
			// The page at the unsuffixed title is written in the content language already.
			'the licenses page itself' => ['Licenses', null],
			// A subpage is only a language copy where the segment names a language; a site is free
			// to write "Licenses/FAQ" and expect nothing to happen to it.
			'a subpage that is not a language' => ['Licenses/FAQ', null],
			'a subpage named for no known tag' => ['Licenses/zzzz', null],
			// Nothing outside the licenses page is this handler's to answer for. Translate speaks
			// for its own translation pages, and it takes the same hook to do it.
			'a translation page elsewhere' => ['Installation/ko', null],
			'a page named like one' => ['Other licenses/ko', null]
		];
	}

	/** Set the name empty and the build writes no page, so there is no copy to answer for. */
	public function testNoLicensesPageMeansNothingToDeclare() {
		$this->overrideConfigValue('WikvenLicensesPage', '');

		$this->assertSame('en', $this->languageOf('Licenses/ko')->getCode());
	}

	/**
	 * A site that writes its own licenses page gets no copies from the build, so a subpage under it
	 * is the site's or Translate's. Answering for it would overrule whoever does own it.
	 */
	public function testASourcePageOfThatNameSilencesTheHandler() {
		$source = $this->getNewTempDirectory();
		file_put_contents("$source/Licenses.wikitext", "Ours, thanks.\n");
		$this->overrideConfigValue('WikvenLicensesPage', 'Licenses');
		$this->overrideConfigValue('WikvenSourceDirectory', $source);

		$this->assertSame('en', $this->languageOf('Licenses/ko')->getCode());
	}

	/**
	 * A copy the site provided itself is the site's, whatever the build would have written there.
	 * build.php leaves it alone, and so must this.
	 */
	public function testASourceCopyIsLeftToTheSite() {
		$source = $this->getNewTempDirectory();
		mkdir("$source/Licenses");
		file_put_contents("$source/Licenses/ko.wikitext", "우리 것입니다.\n");
		$this->overrideConfigValue('WikvenLicensesPage', 'Licenses');
		$this->overrideConfigValue('WikvenSourceDirectory', $source);

		$this->assertSame('en', $this->languageOf('Licenses/ko')->getCode());
	}

	/** Run the hook over a title and answer with the language it left behind. */
	private function languageOf(string $title): Language {
		$services = MediaWikiServices::getInstance();
		$declarer = new Declarer($services->getLanguageFactory(), $services->getLanguageNameUtils());

		$pageLang = $services->getLanguageFactory()->getLanguage('en');
		$declarer->onPageContentLanguage(Title::newFromText($title), $pageLang, null);

		return $pageLang;
	}
}
