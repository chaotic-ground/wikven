<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Main;
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
}
