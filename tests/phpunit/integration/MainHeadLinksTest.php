<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\AtEase\AtEase;
use Wikimedia\TestingAccessWrapper;

/**
 * What a page's head says about its own address and about its translations.
 *
 * Its own class because the answer is only as good as the pages behind it, and MediaWiki decides
 * whether a test class gets a database once, for the whole class.
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
	 * addresses, and a skin copy a third.
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
	 * has none to give. Naming the build container's address would be worse than saying nothing.
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
	 * is one directory up, so pointing at it needs no notion of where the site is published.
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
	 * Translate knowing it. Without this it would be the one page saying nothing about them.
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
	 * as a group only where the group agrees on its membership.
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
	 * language, and an alternate naming a page the export does not have is a set a search engine
	 * throws away whole.
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
	 * @param Title $title The page being rendered.
	 * @param string $skin The skin rendering it; the main one unless a test says otherwise.
	 * @return array<string,string>
	 */
	private function addressTags(Title $title, string $skin = 'vector-2022'): array {
		return TestingAccessWrapper::newFromObject($this->main())->addressTags($title, $skin);
	}

	/**
	 * The same links read as what each one says: the language it claims, or "canonical", against
	 * the page it names. What a set has to get right is which page answers for which language.
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

	/** An OutputPage for a page of the site, asking for the module styles a test names. */
	private function outputFor(array $moduleStyles): OutputPage {
		$context = new RequestContext();
		$context->setTitle(Title::newFromText('Getting Started'));
		$out = new OutputPage($context);
		$out->addModuleStyles($moduleStyles);
		return $out;
	}

	/**
	 * The head of an exported page: the links that answer only inside a live MediaWiki go, and each
	 * module's styles arrive as a file beside the page rather than as a load.php request.
	 */
	public function testTheHeadLosesItsApiLinksAndGainsItsStylesheets() {
		$directory = $this->getNewTempDirectory();
		$this->overrideConfigValues([
			'WikvenHtmlDirectory' => $directory,
			'WikvenAssetDirectory' => 'assets'
		]);
		$tags = [
			'alternative-edit' => '<link rel="alternate" type="application/x-wiki">',
			'opensearch' => '<link rel="search">',
			'rsd' => '<link rel="EditURI">',
			'universal-edit-button' => '<link rel="alternate">',
			'meta-generator' => '<meta name="generator" content="MediaWiki">'
		];
		$out = $this->outputFor(['mediawiki.skinning.interface']);

		$this->main()->onOutputPageAfterGetHeadLinksArray($tags, $out);

		$this->assertSame(
			['meta-generator', 'mediawiki.skinning.interface'],
			array_keys($tags),
			'the four links a static host cannot answer are gone, the module is linked'
		);
		$this->assertStringContainsString(
			'assets/mediawiki.skinning.interface.css',
			$tags['mediawiki.skinning.interface']
		);
		// The file the build writes the styles into has to be there for the pass that fills it.
		$this->assertFileExists("$directory/assets/mediawiki.skinning.interface.css");
	}

	/**
	 * What is not linked: a module nothing registered, and one whose styles belong to a reader
	 * rather than to the page -- a static site has no reader to have them.
	 */
	public function testAModuleThatIsNobodysOrEverybodysOwnIsNotLinked() {
		$this->overrideConfigValues([
			'WikvenHtmlDirectory' => $this->getNewTempDirectory(),
			'WikvenAssetDirectory' => 'assets'
		]);
		$tags = [];
		$out = $this->outputFor(['wikven.no.such.module', 'user.styles']);

		$this->main()->onOutputPageAfterGetHeadLinksArray($tags, $out);

		$this->assertSame([], $tags);
	}

	/** Outside a build there is no static site to place the file in, and none is made. */
	public function testAWikiThatIsNotBakingWritesNoStylesheetFile() {
		$this->overrideConfigValue('WikvenHtmlDirectory', '');
		$tags = [];

		$this->main()->onOutputPageAfterGetHeadLinksArray($tags, $this->outputFor(['mediawiki.skinning.interface']));

		$this->assertSame(['mediawiki.skinning.interface'], array_keys($tags));
	}

	/** A site with no licenses page has no family of copies for a page to belong to. */
	public function testWithoutALicensesPageThereIsNoFamilyOfCopies() {
		$this->overrideConfigValues([
			'WikvenSiteUrl' => 'https://example.org/docs',
			'WikvenLicensesPage' => ''
		]);

		$this->assertSame(
			['link-canonical'],
			array_keys($this->addressTags(Title::newFromText('Licenses')))
		);
	}

	/** A page marked for translation, with one translation page written beside it. */
	private function markedForTranslationInto(string $titleText, string $language): void {
		$title = Title::newFromText($titleText);
		$status = $this->editPage(
			$title,
			ContentHandler::makeContent("<translate>\n<!--T:1-->\nHi.\n</translate>", $title),
			__METHOD__,
			NS_MAIN,
			$this->getTestSysop()->getUser()
		);
		TranslatablePage::newFromTitle($title)->addMarkedTag($status->value['revision-record']->getId());
		$this->getExistingTestPage(Title::newFromText("$titleText/$language"));
	}

	/**
	 * The other family: a page Translate marked. Whichever of the set is rendered, the set named is
	 * the same, and the source page owns the language it was written in rather than its "/en" copy.
	 *
	 * @dataProvider provideTranslatedPagesOfOneSet
	 */
	public function testEveryPageOfATranslatedSetNamesTheWholeSet(string $rendered, string $canonical) {
		$this->markTestSkippedIfExtensionNotLoaded('Translate');
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');
		$this->markedForTranslationInto('Installation', 'ko');

		$this->assertSame(
			[
				'canonical' => $canonical,
				'en' => 'Installation.html',
				'ko' => 'Installation/ko.html',
				'x-default' => 'Installation.html'
			],
			$this->addressed(Title::newFromText($rendered))
		);
	}

	public static function provideTranslatedPagesOfOneSet(): array {
		return [
			'the source page' => ['Installation', 'Installation.html'],
			'a translation of it' => ['Installation/ko', 'Installation/ko.html']
		];
	}

	/** A page Translate has never heard of is a page of one language, which says nothing. */
	public function testAPageThatIsNotTranslatedNamesNoSet() {
		$this->markTestSkippedIfExtensionNotLoaded('Translate');
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');

		$this->assertSame(
			['canonical' => 'Getting_Started.html'],
			$this->addressed(Title::newFromText('Getting Started'))
		);
	}

	/**
	 * A directory the build cannot make is reported rather than printed into the page: the pass
	 * that fills these files is the one to fail, where the reason is still known.
	 */
	public function testAnAssetDirectoryThatCannotBeMadeStillLeavesThePageAlone() {
		$directory = $this->getNewTempDirectory();
		file_put_contents("$directory/assets", 'a file, where the directory has to go');
		$this->overrideConfigValues([
			'WikvenHtmlDirectory' => $directory,
			'WikvenAssetDirectory' => 'assets'
		]);
		$tags = [];
		$out = $this->outputFor(['mediawiki.skinning.interface']);

		// The failed mkdir warns on its way to the false the code reads, and PHPUnit fails on a warning.
		AtEase::suppressWarnings();
		try {
			$this->main()->onOutputPageAfterGetHeadLinksArray($tags, $out);
		} finally {
			AtEase::restoreWarnings();
		}

		$this->assertSame(['mediawiki.skinning.interface'], array_keys($tags));
	}
}
