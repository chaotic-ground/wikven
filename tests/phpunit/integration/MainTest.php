<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Main;
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
}
