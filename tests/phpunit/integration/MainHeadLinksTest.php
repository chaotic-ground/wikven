<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * What a page's head says about its own address and about its translations.
 *
 * Its own class because the answer is only as good as the pages behind it: a set names a
 * translation where one was really written, so the code asks each candidate whether it exists and
 * the tests have to write one. MediaWiki decides whether a test class gets a database once, for
 * the whole class, so this cannot live beside the tests in MainTest that need none.
 *
 * @group Database
 * @covers \MediaWiki\Extension\Wikven\Hooks\Main
 */
class MainHeadLinksTest extends MediaWikiIntegrationTestCase {
	private function main(): Main {
		return new Main($this->getServiceContainer()->getMainConfig());
	}

	/**
	 * A page says which of its addresses is the real one, whole.
	 *
	 * A host that answers /Getting_Started for Getting_Started.html gives the same document two
	 * addresses, and a skin copy gives it a third. Naming the one settles all of them at once.
	 */
	public function testAPageNamesTheWholeAddressItIsPublishedAt() {
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');

		$tags = $this->addressTags(Title::newFromText('Getting Started'));

		$this->assertSame(
			['link-canonical' => '<link rel="canonical" href="https://example.org/docs/Getting_Started.html">'],
			$tags
		);
	}

	/**
	 * An hreflang value has to be a whole url, and a site that has not said where it is published
	 * has none to give. Naming the build container's own address would be worse than saying
	 * nothing, so a page that would carry a set carries none.
	 */
	public function testWithoutASiteUrlThereIsNoSetToWrite() {
		$this->licensedSiteTranslatedIntoKorean();
		$this->getExistingTestPage(Title::newFromText('Licenses/ko'));
		$this->overrideConfigValue('WikvenSiteUrl', '');
		$this->setMwGlobals('wgWikvenMainSkin', 'vector-2022');

		$this->assertSame([], $this->addressTags(Title::newFromText('Licenses')));
	}

	/**
	 * With no whole address to give, a skin copy can still say which page it duplicates: that page
	 * is in the export one directory up, so pointing at it needs no notion of where the site is
	 * published. This is what the export said before it said anything else.
	 */
	public function testWithoutASiteUrlASkinCopyStillNamesThePageItDuplicates() {
		$this->overrideConfigValue('WikvenSiteUrl', '');
		$this->setMwGlobals('wgWikvenMainSkin', 'vector-2022');

		$tags = $this->addressTags(Title::newFromText('Getting Started'), 'citizen');

		$this->assertSame(
			['link-canonical' => '<link rel="canonical" href="../Getting_Started.html">'],
			$tags
		);
	}

	/**
	 * The main skin's own copy has nothing to say there. The addresses it would be distinguishing
	 * itself from are a host's invention, and naming one of them needs the site's own address.
	 */
	public function testWithoutASiteUrlTheMainSkinsCopySaysNothing() {
		$this->overrideConfigValue('WikvenSiteUrl', '');
		$this->setMwGlobals('wgWikvenMainSkin', 'vector-2022');

		$this->assertSame([], $this->addressTags(Title::newFromText('Getting Started')));
	}

	/**
	 * The licenses page is the one page on a site that is genuinely several languages without
	 * Translate knowing it: the build writes the page and a copy per language the source tree is
	 * translated into. Without this it would be the one page saying nothing about them.
	 */
	public function testTheLicensesPageNamesItsTranslations() {
		$this->licensedSiteTranslatedIntoKorean();
		$this->getExistingTestPage(Title::newFromText('Licenses/ko'));

		$this->assertSame(
			[
				'canonical' => 'Licenses.html',
				'en' => 'Licenses.html',
				'ko' => 'Licenses/ko.html',
				'x-default' => 'Licenses.html'
			],
			$this->addressed(Title::newFromText('Licenses'))
		);
	}

	/**
	 * Every page of a set names the whole set, itself included, because a search engine reads them
	 * as a group only where the group agrees on its own membership. What differs between them is
	 * the canonical url, which is each page's own.
	 */
	public function testALicensesCopyNamesTheSameSetAsThePageItself() {
		$this->licensedSiteTranslatedIntoKorean();
		$this->getExistingTestPage(Title::newFromText('Licenses/ko'));

		$page = $this->addressed(Title::newFromText('Licenses'));
		$copy = $this->addressed(Title::newFromText('Licenses/ko'));

		$this->assertSame('Licenses/ko.html', $copy['canonical']);
		unset($page['canonical'], $copy['canonical']);
		$this->assertSame($page, $copy);
	}

	/**
	 * A language the source tree is translated into is not by itself a licenses page in that
	 * language. build.php writes a copy only for a language it can translate the page's own
	 * messages into, and an alternate naming a page the export does not have is a link to a 404 --
	 * in the head of every other page in the set, which is a set a search engine throws away whole.
	 */
	public function testALicensesCopyTheBuildDidNotWriteIsNotNamed() {
		$this->licensedSiteTranslatedIntoKorean();

		$tags = $this->addressTags(Title::newFromText('Licenses'));

		$this->assertSame(['link-canonical'], array_keys($tags));
	}

	/** A subpage of the licenses page in no language of the site is a page of its own. */
	public function testAPageUnderTheLicensesPageInNoLanguageIsNotACopy() {
		$this->licensedSiteTranslatedIntoKorean();

		$tags = $this->addressTags(Title::newFromText('Licenses/Notes'));

		$this->assertSame(['link-canonical'], array_keys($tags));
	}

	/**
	 * @param string $skin The skin rendering the page; the main one unless a test says otherwise.
	 * @return array<string,string>
	 */
	private function addressTags(Title $title, string $skin = 'vector-2022'): array {
		return TestingAccessWrapper::newFromObject($this->main())->addressTags($title, $skin);
	}

	/**
	 * The same links read as what each one says: the language it claims, or "canonical", against
	 * the page it names with the site's own address taken off the front. The markup itself is
	 * pinned by testAPageNamesTheWholeAddressItIsPublishedAt; what a set has to get right is which
	 * page answers for which language, and that is what this shows.
	 *
	 * @return array<string,string>
	 */
	private function addressed(Title $title): array {
		$said = [];
		foreach ($this->addressTags($title) as $tag) {
			preg_match('/rel="([^"]*)"(?: hreflang="([^"]*)")? href="([^"]*)"/', $tag, $matched);
			$said[$matched[2] === '' ? $matched[1] : $matched[2]] = str_replace(
				'https://example.org/docs/',
				'',
				$matched[3]
			);
		}
		return $said;
	}

	/** A site with a licenses page, published, and one page translated into Korean. */
	private function licensedSiteTranslatedIntoKorean(): void {
		$source = $this->getNewTempDirectory();
		mkdir("$source/Intro");
		file_put_contents("$source/Intro.wikitext", "<translate>\n<!--T:1-->\nHi.\n</translate>\n");
		file_put_contents("$source/Intro/ko.wikitext", "<!--T:1-->\n안녕.\n");
		$this->overrideConfigValue('WikvenLicensesPage', 'Licenses');
		$this->overrideConfigValue('WikvenSourceDirectory', $source);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');
	}
}
