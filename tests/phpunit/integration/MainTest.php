<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Main
 */
class MainTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// Read by Main's constructor; not set outside a build.
		$this->overrideConfigValue('WikvenHtmlDirectory', $this->getNewTempDirectory());
		$this->overrideConfigValue('WikvenStyleDirectory', '.');
	}

	private function main(): Main {
		return new Main($this->getServiceContainer()->getMainConfig());
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
	 * With no edit URL configured, even an action=edit link falls back to the
	 * static page rather than a dead query string.
	 */
	public function testEditFallsBackWithoutUrl() {
		$title = Title::newFromText('Getting Started');
		$url = '/x';
		$this->main()->onGetLocalURL($title, $url, 'action=edit');
		$this->assertSame('./Getting_Started.html', $url);
	}
}
