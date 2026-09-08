<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Build\BuildFor;
use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

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
	 * A caller that asked for a whole URL gets one. getFullURL() runs its own hook after expanding
	 * getLocalURL()'s answer, which expand() reads as a bare path and hands back without a host.
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
	 * A diff goes where the history goes. Citizen's "last modified" button asks for the latest
	 * diff, with a "diff" parameter carrying no value, and would resolve to the page it is on.
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
	 * A marker matched by one spelling has to be written in one -- otherwise a Korean wiki writes
	 * "특수:MyLanguage/..." and that pass walks past it.
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
	 * matches a special page's name however it was typed.
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
	 * The "View source" tab points at the page's source file, and in Citizen it brings an icon: the
	 * skin drops tab labels below desktop width, so a tab with no icon is a blank box.
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
	 * The row of page actions is the skin's layout, and everything that makes the output work as
	 * files is untouched.
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

	/** A link to another wiki is that wiki's to answer for; the export rewrites its own pages only. */
	public function testAnInterwikiLinkIsLeftAlone() {
		$url = 'https://www.mediawiki.org/wiki/Manual:Contents';
		$title = Title::makeTitle(NS_MAIN, 'Manual:Contents', '', 'mediawikiwiki');
		$this->main()->onGetLocalURL($title, $url, '');

		$this->assertSame('https://www.mediawiki.org/wiki/Manual:Contents', $url);
	}

	/**
	 * A file borrowed from Commons has no File: page here, so the link goes to the page the
	 * repository keeps for it -- or nowhere, where the repository names none.
	 *
	 * @dataProvider provideForeignDescriptions
	 */
	public function testAForeignFileLinksToTheRepositoryThatHasIt(?string $description, string $expected) {
		$file = $this->createMock(File::class);
		$file->method('isLocal')->willReturn(false);
		$file->method('getDescriptionUrl')->willReturn($description);
		$repos = $this->createMock(RepoGroup::class);
		$repos->method('findFile')->willReturn($file);
		$this->setService('RepoGroup', $repos);

		$url = '/index.php/File:Bakery_oven.jpg';
		$this->main()->onGetLocalURL(Title::newFromText('File:Bakery oven.jpg'), $url, '');

		$this->assertSame($expected, $url);
	}

	public static function provideForeignDescriptions(): array {
		$commons = 'https://commons.wikimedia.org/wiki/File:Bakery_oven.jpg';
		return [
			'a repository with a description page' => [$commons, $commons],
			'a repository with none' => [null, '/index.php/File:Bakery_oven.jpg'],
			'a repository answering with nothing' => ['', '/index.php/File:Bakery_oven.jpg']
		];
	}

	/**
	 * Translate's banner and its "Translate" tab point at Special:Translate, which the export has no
	 * page for. The query says which page and which language, and that is a file on the edit host.
	 */
	public function testATranslateLinkGoesToTheTranslationsOwnSourceFile() {
		$this->overrideConfigValue('WikvenEditUrl', 'https://example.org/edit/$1');

		$url = '/index.php/Special:Translate';
		$this->main()->onGetFullURL(
			Title::newFromText('Special:Translate'),
			$url,
			'group=page-Getting+Started&language=ko&action=page'
		);

		$this->assertSame('https://example.org/edit/Getting%20Started/ko.wikitext', $url);
	}

	/**
	 * Without both halves of that query there is no file to name, so the link is left to the other
	 * rules -- which have nothing for a special page either.
	 *
	 * @dataProvider provideTranslateQueriesThatNameNoFile
	 */
	public function testATranslateLinkNamingNoFileIsNotRewritten(string $query) {
		$this->overrideConfigValue('WikvenEditUrl', 'https://example.org/edit/$1');

		$url = '/index.php/Special:Translate';
		$this->main()->onGetFullURL(Title::newFromText('Special:Translate'), $url, $query);

		$this->assertSame('/index.php/Special:Translate', $url);
	}

	public static function provideTranslateQueriesThatNameNoFile(): array {
		return [
			'no language' => ['group=page-Getting+Started'],
			'a group that is not a page' => ['group=core&language=ko'],
			'nothing at all' => ['']
		];
	}
}
