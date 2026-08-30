<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\LicensesPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\LicensesPage
 */
class LicensesPageTest extends MediaWikiIntegrationTestCase {
	/** A page the build has copies to write for: translatable, so the site is built in ko. */
	private const TRANSLATABLE = "<languages/>\n<translate>\n<!--T:1-->\nHi.\n</translate>\n";

	/**
	 * The copies are the ones the build wrote, in every language the source tree carries.
	 *
	 * retranslateChrome re-renders these with their own language as the interface language, so this
	 * is the list of pages whose chrome the build is entitled to change.
	 */
	public function testTheCopiesAreTheOnesTheBuildWrote() {
		$source = $this->sourceTreeTranslatedIntoKorean();

		$copies = LicensesPage::generatedCopies($source, $this->isKnownLanguage());

		$this->assertSame(['ko'], array_keys($copies));
		$this->assertSame('Licenses/ko', $copies['ko']->getPrefixedText());
	}

	/**
	 * A copy the site provided itself is the site's, and re-rendering it is not the build's to do.
	 *
	 * This is the page the Declarer hook keeps its hands off (#561): English prose about Korean
	 * licensing terms, sitting at the title a Korean copy would have had. Re-rendering it in ko
	 * would wrap Korean chrome around English prose and leave the html lang saying en, because the
	 * hook still -- rightly -- calls the page English.
	 */
	public function testASourceCopyIsNotTheBuildsToRecache() {
		$source = $this->sourceTreeTranslatedIntoKorean();
		mkdir("$source/Licenses");
		file_put_contents("$source/Licenses/ko.wikitext", "What Korean law asks of us.\n");

		$this->assertSame([], LicensesPage::generatedCopies($source, $this->isKnownLanguage()));
	}

	/** A site that wrote its own licenses page got no copies from the build, so there are none. */
	public function testASourceLicensesPageLeavesNoCopies() {
		$source = $this->sourceTreeTranslatedIntoKorean();
		file_put_contents("$source/Licenses.wikitext", "Ours, thanks.\n");

		$this->assertSame([], LicensesPage::generatedCopies($source, $this->isKnownLanguage()));
	}

	/** Set the name empty and the build writes no page, so there is nothing under it either. */
	public function testNoLicensesPageMeansNoCopies() {
		$source = $this->sourceTreeTranslatedIntoKorean();
		$this->overrideConfigValue('WikvenLicensesPage', '');

		$this->assertSame([], LicensesPage::generatedCopies($source, $this->isKnownLanguage()));
	}

	/** A source tree with one translated page, which is what makes ko a language the site is built in. */
	private function sourceTreeTranslatedIntoKorean(): string {
		$source = $this->getNewTempDirectory();
		mkdir("$source/Intro");
		file_put_contents("$source/Intro.wikitext", self::TRANSLATABLE);
		file_put_contents("$source/Intro/ko.wikitext", "<!--T:1-->\n안녕.\n");
		$this->overrideConfigValue('WikvenLicensesPage', 'Licenses');
		$this->overrideConfigValue('WikvenSourceDirectory', $source);

		return $source;
	}

	/** @return callable(string):bool */
	private function isKnownLanguage(): callable {
		return [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];
	}
}
