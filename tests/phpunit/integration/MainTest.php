<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Main
 */
class MainTest extends MediaWikiIntegrationTestCase {
	private function main(): Main {
		return new Main($this->getServiceContainer()->getMainConfig());
	}

	/**
	 * The handler is constructible on a wiki that only ran wfLoadExtension(), without the
	 * build's WikvenSettings.php: the directories it reads are declared in extension.json,
	 * and default to empty, meaning "no static site to write".
	 */
	public function testConstructibleWithoutBuildSettings() {
		$config = $this->getServiceContainer()->getMainConfig();
		$this->assertSame('', $config->get('WikvenHtmlDirectory'));
		$this->assertSame('', $config->get('WikvenSourceDirectory'));
		$this->main();
	}

	/**
	 * A normal page link is rewritten to the relative ./Name.html the static host
	 * serves, instead of a path that only resolves inside a live MediaWiki.
	 */
	public function testNormalPageLink() {
		$title = Title::newFromText('Getting Started');
		$url = '/index.php/Getting_Started';
		$this->main()->onGetLocalURL($title, $url, '');
		$this->assertSame('./Getting_Started.html', $url);
	}

	/**
	 * A caller that asked for a whole URL gets one. getFullURL() runs its own hook after
	 * expanding what getLocalURL() returned, so the answer is recomputed from the same title
	 * rather than recovered from "./Getting_Started.html", which expand() reads as a bare path
	 * and hands back without a host.
	 */
	public function testAWholeUrlIsWhole() {
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');
		$title = Title::newFromText('Getting Started');
		$url = 'http:Getting_Started.html';
		$this->main()->onGetFullURL($title, $url, '');
		$this->assertSame('https://example.org/docs/Getting_Started.html', $url);
	}

	/** getCanonicalURL() carries the fragment getFullURL() drops, so the canonical hook adds it. */
	public function testACanonicalUrlCarriesTheFragment() {
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/docs');
		$title = Title::newFromText('Getting Started#Oven');
		$url = 'http:Getting_Started.html';
		$this->main()->onGetCanonicalURL($title, $url, '');
		$this->assertSame('https://example.org/docs/Getting_Started.html#Oven', $url);
	}

	/**
	 * Where the site has not said where it is published there is no address to write, so the
	 * value is left as it was: a wrong absolute URL is worse than an obviously relative one.
	 */
	public function testAWholeUrlIsLeftAloneWithoutASiteUrl() {
		$title = Title::newFromText('Getting Started');
		$url = 'http:Getting_Started.html';
		$this->main()->onGetFullURL($title, $url, '');
		$this->assertSame('http:Getting_Started.html', $url);
	}

	/**
	 * A target that is already a whole URL is one whichever hook asked: an edit link goes to the
	 * repository whether the caller wanted a relative URL or an absolute one.
	 */
	public function testAWholeUrlToAnExternalTargetNeedsNoSiteUrl() {
		$this->overrideConfigValue('WikvenEditUrl', 'https://repo/edit/$1');
		$title = Title::newFromText('Getting Started');
		$url = '/x';
		$this->main()->onGetFullURL($title, $url, 'action=edit');
		$this->assertSame('https://repo/edit/Getting%20Started.wikitext', $url);
	}

	/**
	 * The edit and history actions are rewritten to the configured repository
	 * URLs, with $1 replaced by the page's percent-encoded source file name, so a
	 * reader can jump from the rendered page to its source.
	 */
	public function testEditAndHistoryActionsRewritten() {
		$this->overrideConfigValue('WikvenEditUrl', 'https://repo/edit/$1');
		$this->overrideConfigValue('WikvenHistoryUrl', 'https://repo/history/$1');
		$title = Title::newFromText('Getting Started');

		$edit = '/x';
		$this->main()->onGetLocalURL($title, $edit, 'action=edit');
		$this->assertSame('https://repo/edit/Getting%20Started.wikitext', $edit);

		$history = '/x';
		$this->main()->onGetLocalURL($title, $history, 'action=history');
		$this->assertSame('https://repo/history/Getting%20Started.wikitext', $history);
	}

	/**
	 * A diff goes where the history goes. The export holds one revision of a page, so what changed
	 * is only in the repository: Citizen's "last modified" button asks for the latest diff, with a
	 * "diff" parameter carrying no value, and would otherwise resolve to the page it is already on.
	 *
	 * @dataProvider provideDiffQueries
	 */
	public function testDiffLinksGoToTheHistory(string $query) {
		$this->overrideConfigValue('WikvenHistoryUrl', 'https://repo/history/$1');
		$title = Title::newFromText('Getting Started');

		$url = '/x';
		$this->main()->onGetLocalURL($title, $url, $query);
		$this->assertSame('https://repo/history/Getting%20Started.wikitext', $url);
	}

	public static function provideDiffQueries() {
		return [
			"Citizen's latest diff" => ['diff='],
			'a diff between revisions' => ['diff=1234&oldid=1233']
		];
	}

	/**
	 * With no edit URL configured, even an action=edit link falls back to the
	 * static page rather than a dead query string.
	 */
	public function testEditFallsBackWithoutUrl() {
		$title = Title::newFromText('Getting Started');
		$url = '/x';
		$this->main()->onGetLocalURL($title, $url, 'action=edit');
		$this->assertSame('./Getting_Started.html', $url);
	}

	/** And with no history URL, a diff link is a page link rather than a dead query string. */
	public function testDiffFallsBackWithoutUrl() {
		$title = Title::newFromText('Getting Started');
		$url = '/x';
		$this->main()->onGetLocalURL($title, $url, 'diff=');
		$this->assertSame('./Getting_Started.html', $url);
	}

	/**
	 * Vector renders the collapsed search box as a link to Special:Search, which the export does
	 * not have, so it goes to the page SifterSearch lists a query's matches on instead.
	 */
	public function testSearchLinkGoesToTheResultsPage() {
		$this->setMwGlobals('wgSifterSearchResultsPage', 'Search');
		$url = '/index.php/Special:Search';
		$this->main()->onGetLocalURL(Title::newFromText('Special:Search'), $url, '');
		$this->assertSame('./Search.html', $url);
	}

	/** A link that asks for a term keeps it: the results widget reads "?search=" from the URL. */
	public function testSearchLinkCarriesTheTerm() {
		$this->setMwGlobals('wgSifterSearchResultsPage', 'Help:Search');
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('Special:Search'), $url, 'search=oven');
		$this->assertSame('./Help:Search.html?search=oven', $url);
	}

	/**
	 * With no results page named there is nowhere for a search to land, so the toggle is left the
	 * button its own script treats it as, rather than a link to a page that answers 404.
	 */
	public function testSearchLinkWithoutResultsPage() {
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('Special:Search'), $url, '');
		$this->assertSame('#', $url);
	}

	/**
	 * A Special:MyLanguage link is written canonically, not in this wiki's own words for it.
	 *
	 * The link is not a link to a file -- no export holds that special page -- but the marker
	 * resolveTranslationLinks.php reads to send a reader to their own copy of the target, and a
	 * marker matched by one spelling has to be written in one. On a wiki whose content language is
	 * not English the namespace is that language's word for it, so the marker written from the
	 * wiki's own naming would be "특수:MyLanguage/..." and that pass would walk past it, leaving
	 * every translated page pointing at a file the site does not have.
	 */
	public function testSpecialMyLanguageIsMarkedCanonicallyInAnotherContentLanguage() {
		$this->setContentLang('ko');
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('Special:MyLanguage/Getting Started'), $url, '');
		$this->assertSame('./Special:MyLanguage/Getting_Started.html', $url);
	}

	/** That language's own name for the special page resolves to the same marker. */
	public function testSpecialMyLanguageIsMarkedCanonicallyFromALocalisedAlias() {
		$this->setContentLang('ko');
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('특수:내언어/Getting Started'), $url, '');
		$this->assertSame('./Special:MyLanguage/Getting_Started.html', $url);
	}

	/**
	 * So does one capitalised differently, which is the same fault on an English wiki: MediaWiki
	 * matches a special page's name however it was typed, so "Special:Mylanguage/X" is that page as
	 * surely as "Special:MyLanguage/X" and has to reach the pass as the same marker.
	 */
	public function testSpecialMyLanguageIsMarkedCanonicallyFromAnotherCapitalisation() {
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('Special:Mylanguage/Getting Started'), $url, '');
		$this->assertSame('./Special:MyLanguage/Getting_Started.html', $url);
	}

	/** A name no special page answers to has no canonical form; it keeps the one it was given. */
	public function testAnUnknownSpecialPageKeepsItsName() {
		$url = '/x';
		$this->main()->onGetLocalURL(Title::newFromText('Special:NoSuchPage'), $url, '');
		$this->assertSame('./Special:NoSuchPage.html', $url);
	}

	/**
	 * The "View source" tab points at the page's source file in the repository, and in Citizen it
	 * brings an icon: the skin renders the page actions as icon buttons and stops rendering their
	 * labels below desktop width, so a tab it has no icon for is a blank box there. It maps icons
	 * onto the keys core uses, and this key is Wikven's own.
	 */
	public function testViewSourceTabCarriesCitizensIcon() {
		$dir = $this->getNewTempDirectory();
		file_put_contents("$dir/Real.wikitext", '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);
		$this->overrideConfigValue('WikvenViewSourceUrl', 'https://repo/blob/$1');

		$tab = $this->viewsFor('Real', 'citizen')['wikven-viewsource'];
		$this->assertSame('https://repo/blob/Real.wikitext', $tab['href']);
		$this->assertSame('wikiText', $tab['icon']);
	}

	/**
	 * Every other skin gets the tab without one: core passes the key on to whichever skin is
	 * rendering, and Vector 2022 draws it in the page-tools dropdown, where the edit and history
	 * tabs beside it have no icon either.
	 */
	public function testViewSourceTabHasNoIconElsewhere() {
		$dir = $this->getNewTempDirectory();
		file_put_contents("$dir/Real.wikitext", '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);
		$this->overrideConfigValue('WikvenViewSourceUrl', 'https://repo/blob/$1');

		$tab = $this->viewsFor('Real', 'vector-2022')['wikven-viewsource'];
		$this->assertSame('https://repo/blob/Real.wikitext', $tab['href']);
		$this->assertArrayNotHasKey('icon', $tab);
	}

	/** A generated page has no source file behind it, so it gets no tab to a link that would 404. */
	public function testViewSourceTabSkippedWithoutASourceFile() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->getNewTempDirectory());
		$this->overrideConfigValue('WikvenViewSourceUrl', 'https://repo/blob/$1');

		$this->assertArrayNotHasKey('wikven-viewsource', $this->viewsFor('Version', 'citizen'));
	}

	/**
	 * A skin preview gets no tab of wikven's own.
	 *
	 * The row of page actions is the skin's layout, and a skin author baking pages to look at it
	 * has not asked for an extra tab in it. Everything this class does to make the output work as
	 * files -- rewriting a link to "./X.html" above all -- is untouched, so there is still a
	 * preview to look at.
	 */
	public function testASkinPreviewGetsNoTabOfOurOwn() {
		$dir = $this->getNewTempDirectory();
		file_put_contents("$dir/Real.wikitext", '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);
		$this->overrideConfigValue('WikvenViewSourceUrl', 'https://repo/blob/$1');
		$this->overrideConfigValue('WikvenBuildFor', BuildFor::SKIN_PREVIEW);

		$this->assertSame([], $this->viewsFor('Real', 'citizen'));

		$url = '/wiki/Real';
		$this->main()->onGetLocalURL(Title::newFromText('Real'), $url, '');
		$this->assertSame('./Real.html', $url, 'the links a preview is browsed by still work');
	}

	/** @return array The 'views' menu the hook leaves behind, keyed as the skins read it. */
	private function viewsFor(string $titleText, string $skin): array {
		$sktemplate = $this->createMock(SkinTemplate::class);
		$sktemplate->method('getTitle')->willReturn(Title::newFromText($titleText));
		$sktemplate->method('getSkinName')->willReturn($skin);
		// The hook asks for one message, the label; a mock has none of its own to answer with.
		$sktemplate->method('msg')->willReturn(wfMessage('viewsource'));

		$links = ['views' => []];
		$this->main()->onSkinTemplateNavigation__Universal($sktemplate, $links);
		return $links['views'];
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
	 * Both a canonical url and an hreflang value have to be whole, and a site that has not said
	 * where it is published has no whole address to give. Naming the build container's own would
	 * be worse than saying nothing.
	 */
	public function testWithoutASiteUrlThereIsNoWholeAddressToName() {
		$this->overrideConfigValue('WikvenSiteUrl', '');

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

		$tags = $this->addressTags(Title::newFromText('Licenses'));

		$this->assertSame([
			'link-canonical' => '<link rel="canonical" href="https://example.org/docs/Licenses.html">',
			'link-alternate-language-en' =>
				'<link rel="alternate" hreflang="en" href="https://example.org/docs/Licenses.html">',
			'link-alternate-language-ko' =>
				'<link rel="alternate" hreflang="ko" href="https://example.org/docs/Licenses/ko.html">',
			'link-alternate-language-x-default' =>
				'<link rel="alternate" hreflang="x-default" href="https://example.org/docs/Licenses.html">'
		], $tags);
	}

	/**
	 * Every page of a set names the whole set, itself included, because a search engine reads them
	 * as a group only where the group agrees on its own membership. What differs between them is
	 * the canonical url, which is each page's own.
	 */
	public function testALicensesCopyNamesTheSameSetAsThePageItself() {
		$this->licensedSiteTranslatedIntoKorean();
		$this->getExistingTestPage(Title::newFromText('Licenses/ko'));

		$page = $this->addressTags(Title::newFromText('Licenses'));
		$copy = $this->addressTags(Title::newFromText('Licenses/ko'));

		unset($page['link-canonical'], $copy['link-canonical']);
		$this->assertSame($page, $copy);
		$this->assertSame(
			'<link rel="canonical" href="https://example.org/docs/Licenses/ko.html">',
			$this->addressTags(Title::newFromText('Licenses/ko'))['link-canonical']
		);
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

	/** @return array<string,string> */
	private function addressTags(Title $title): array {
		return TestingAccessWrapper::newFromObject($this->main())->addressTags($title);
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
