<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Adder;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Adder
 */
class AdderTest extends MediaWikiIntegrationTestCase {
	/**
	 * The footer project link shows a friendly host name for known forges and the
	 * bare host otherwise, so it is not hardcoded to GitHub.
	 *
	 * @dataProvider provideRepoHosts
	 */
	public function testRepoHostName(string $url, ?string $expected) {
		$adder = TestingAccessWrapper::newFromObject(new Adder());
		$this->assertSame($expected, $adder->repoHostName($url));
	}

	public static function provideRepoHosts() {
		return [
			'github' => ['https://github.com/owner/repo', 'GitHub'],
			'gitlab with www' => ['https://www.gitlab.com/owner', 'GitLab'],
			'codeberg' => ['https://codeberg.org/owner', 'Codeberg'],
			'unknown host kept as-is' => ['https://git.example.org/owner', 'git.example.org'],
			'no host' => ['not a url', null]
		];
	}

	/**
	 * The footer gains a link to the project repository when WikvenFooterUrl is
	 * set, but only for the "places" category and not for others.
	 */
	public function testFooterAddsSourceLink() {
		$this->overrideConfigValue('WikvenFooterUrl', 'https://github.com/owner/repo');
		$this->overrideConfigValue('WikvenSkins', ['vector']);
		// Empty so the footer's version-link branch does not query the database.
		$this->overrideConfigValue('WikvenVersionPage', '');

		$footerItems = [];
		( new Adder() )->onSkinAddFooterLinks($this->skin(), 'places', $footerItems);
		$this->assertArrayHasKey('source', $footerItems);
		$this->assertStringContainsString('github.com/owner/repo', $footerItems['source']);

		$other = ['existing' => 'kept'];
		( new Adder() )->onSkinAddFooterLinks($this->skin(), 'info', $other);
		$this->assertSame(['existing' => 'kept'], $other, 'only the places category is touched');
	}

	/**
	 * The header search box has no backend on a static site, so it is hidden
	 * unless SifterSearch (not loaded here) provides static search.
	 */
	public function testHidesSearchBoxWithoutSifterSearch() {
		$this->overrideConfigValue('WikvenSkins', ['vector']);
		$out = $this->createMock(OutputPage::class);
		$out->expects($this->once())
			->method('addInlineStyle')
			->with($this->stringContains('#p-search'));
		$skin = $this->createMock(Skin::class);
		$skin->method('getSkinName')->willReturn('vector');

		( new Adder() )->onBeforePageDisplay($out, $skin);
	}

	/**
	 * Vector moves every section from the toolbox onward into its page-tools menu, so the skin list
	 * gets a section of its own there and the toolbox stays as Hider left it.
	 */
	public function testVectorGetsTheSkinListInItsOwnSection() {
		$sidebar = $this->sidebarFor('vector-2022');

		$this->assertSame([], $sidebar['TOOLBOX'], 'the toolbox is left empty');
		$this->assertSame(
			['wikven-skin-vector-2022', 'wikven-skin-citizen'],
			array_keys($sidebar['wikven-skins'])
		);
		$this->assertSame(
			'./citizen/Installation.html',
			$sidebar['wikven-skins']['wikven-skin-citizen']['href']
		);
		$this->assertArrayNotHasKey('href', $sidebar['wikven-skins']['wikven-skin-vector-2022']);
	}

	/**
	 * Every other skin keeps it in the toolbox: Citizen takes only the toolbox into its page-tools
	 * menu and Minerva reads no other section, so a section of our own would not reach either.
	 */
	public function testOtherSkinsKeepTheSkinListInTheToolbox() {
		$sidebar = $this->sidebarFor('citizen');

		$this->assertArrayNotHasKey('wikven-skins', $sidebar);
		$this->assertSame(
			['wikven-skin-vector-2022', 'wikven-skin-citizen'],
			array_keys($sidebar['TOOLBOX'])
		);
		$this->assertSame(
			'../Installation.html',
			$sidebar['TOOLBOX']['wikven-skin-vector-2022']['href']
		);
	}

	/** The sidebar a two-skin export builds for one of them, on a page at the export root. */
	private function sidebarFor(string $name): array {
		$this->overrideConfigValue('WikvenSkins', ['vector-2022', 'citizen']);
		$this->overrideConfigValue('WikvenMainSkin', 'vector-2022');
		$sidebar = ['TOOLBOX' => []];
		( new Adder() )->onSidebarBeforeOutput($this->skin($name), $sidebar);
		return $sidebar;
	}

	private function skin(string $name = 'vector'): Skin {
		$skin = $this->createMock(Skin::class);
		$skin->method('msg')->willReturnCallback(static function (string $key, ...$params) {
			return wfMessage($key, ...$params);
		});
		$skin->method('getSkinName')->willReturn($name);
		$skin->method('getTitle')->willReturn(Title::makeTitle(NS_MAIN, 'Installation'));
		return $skin;
	}
}
