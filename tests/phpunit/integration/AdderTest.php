<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Adder;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Adder
 */
class AdderTest extends MediaWikiIntegrationTestCase {
	/**
	 * An OutputPage that answers getRequest(): the Minerva path asks the request which theme it is
	 * rendering in, and a bare mock hands back null.
	 */
	private function outputPage(): OutputPage {
		$out = $this->createMock(OutputPage::class);
		$out->method('getRequest')->willReturn(new FauxRequest());
		return $out;
	}

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
		$out = $this->outputPage();
		$out->expects($this->once())
			->method('addInlineStyle')
			->with($this->stringContains('#p-search'));
		$skin = $this->createMock(Skin::class);
		$skin->method('getSkinName')->willReturn('vector');

		( new Adder() )->onBeforePageDisplay($out, $skin);
	}

	/**
	 * Every skin renders the generated settings page, and its skin list is filled from the chrome by
	 * ext.Wikven.appearance. One skin means there is no list, so the module has nothing to do and the
	 * empty toolbox is hidden instead.
	 *
	 * @dataProvider provideAppearanceCases
	 */
	public function testAppearanceModuleFollowsTheSkinCount(string $skin, array $skins, bool $expected) {
		$this->overrideConfigValue('WikvenSkins', $skins);
		$modules = [];
		$out = $this->outputPage();
		$out->method('addModules')->willReturnCallback(static function ($name) use (&$modules) {
			$modules[] = $name;
		});
		$skinMock = $this->createMock(Skin::class);
		$skinMock->method('getSkinName')->willReturn($skin);

		( new Adder() )->onBeforePageDisplay($out, $skinMock);

		$this->assertSame($expected, in_array('ext.Wikven.appearance', $modules, true));
	}

	public static function provideAppearanceCases() {
		$two = ['vector-2022', 'minerva'];
		return [
			'the main skin with a choice' => ['vector-2022', $two, true],
			'minerva with a choice' => ['minerva', $two, true],
			'nothing to choose between' => ['vector-2022', ['vector-2022'], false]
		];
	}

	/**
	 * Citizen registers a service worker from load.php whenever the client-side script path is
	 * the wiki root, and an export has no load.php to answer it. The value is overridden for
	 * Citizen alone, so a skin that does not read it keeps whatever core reports.
	 *
	 * @dataProvider provideScriptPathSkins
	 */
	public function testScriptPathClearedForCitizen(string $skin, bool $overridden) {
		$this->overrideConfigValue('WikvenSkins', [$skin]);
		$out = $this->outputPage();
		$vars = [];
		$out->method('addJsConfigVars')->willReturnCallback(
			static function ($keys, $value = null) use (&$vars) {
				$vars[$keys] = $value;
			}
		);
		$skinMock = $this->createMock(Skin::class);
		$skinMock->method('getSkinName')->willReturn($skin);

		( new Adder() )->onBeforePageDisplay($out, $skinMock);

		$this->assertSame($overridden, array_key_exists('wgScriptPath', $vars));
		if ($overridden) {
			$this->assertNull($vars['wgScriptPath']);
		}
	}

	public static function provideScriptPathSkins() {
		return [
			'citizen' => ['citizen', true],
			'vector' => ['vector-2022', false]
		];
	}

	/**
	 * Citizen binds its search shortcuts whether or not the command palette they open survived the
	 * bake, so the export takes them over. Only Citizen has them, so only Citizen gets the module.
	 *
	 * @dataProvider provideSearchShortcutSkins
	 */
	public function testSearchShortcutsAreTakenOverForCitizenOnly(string $skin, bool $expected) {
		$this->overrideConfigValue('WikvenSkins', [$skin]);
		$modules = [];
		$out = $this->outputPage();
		$out->method('addModules')->willReturnCallback(static function ($name) use (&$modules) {
			$modules[] = $name;
		});
		$skinMock = $this->createMock(Skin::class);
		$skinMock->method('getSkinName')->willReturn($skin);

		( new Adder() )->onBeforePageDisplay($out, $skinMock);

		$this->assertSame(
			$expected,
			in_array('ext.Wikven.citizenSearchShortcuts', $modules, true)
		);
	}

	public static function provideSearchShortcutSkins() {
		return [
			'citizen' => ['citizen', true],
			'vector' => ['vector-2022', false],
			'minerva' => ['minerva', false]
		];
	}

	/**
	 * The chooser that carries the skin list into Citizen's preferences panel is only worth
	 * loading where there is a panel to put it in and more than one skin to choose between.
	 *
	 * @dataProvider provideSkinChooserCases
	 */
	public function testSkinChooserIsLoadedForCitizenOnly(
		string $skin,
		array $skins,
		bool $preferences,
		bool $expected
	) {
		$this->overrideConfigValue('WikvenSkins', $skins);
		$this->setMwGlobals('wgCitizenEnablePreferences', $preferences);
		$modules = [];
		$out = $this->outputPage();
		$out->method('addModules')->willReturnCallback(static function ($name) use (&$modules) {
			$modules[] = $name;
		});
		$skinMock = $this->createMock(Skin::class);
		$skinMock->method('getSkinName')->willReturn($skin);

		( new Adder() )->onBeforePageDisplay($out, $skinMock);

		$this->assertSame($expected, in_array('ext.Wikven.citizenSkins', $modules, true));
	}

	/**
	 * The module that carries the skin list out of Vector's page-tools menu and into its appearance
	 * menu is only worth loading in Vector, and only where there is a list to move.
	 *
	 * @dataProvider provideVectorAppearanceCases
	 */
	public function testTheAppearanceMoveIsLoadedForVectorOnly(
		string $skin,
		array $skins,
		bool $expected
	) {
		$this->overrideConfigValue('WikvenSkins', $skins);
		$modules = [];
		$out = $this->outputPage();
		$out->method('addModules')->willReturnCallback(static function ($name) use (&$modules) {
			$modules[] = $name;
		});
		$skinMock = $this->createMock(Skin::class);
		$skinMock->method('getSkinName')->willReturn($skin);

		( new Adder() )->onBeforePageDisplay($out, $skinMock);

		$this->assertSame($expected, in_array('ext.Wikven.vectorSkins', $modules, true));
	}

	public static function provideVectorAppearanceCases() {
		$two = ['vector-2022', 'citizen'];
		return [
			'vector with a choice' => ['vector-2022', $two, true],
			'vector with nothing to choose between' => ['vector-2022', ['vector-2022'], false],
			'citizen' => ['citizen', $two, false],
			'minerva' => ['minerva', $two, false]
		];
	}

	public static function provideSkinChooserCases() {
		$two = ['vector-2022', 'citizen'];
		return [
			'citizen with a panel and a choice' => ['citizen', $two, true, true],
			'citizen with the panel turned off' => ['citizen', $two, false, false],
			'citizen with nothing to choose between' => ['citizen', ['citizen'], true, false],
			'another skin' => ['vector-2022', $two, true, false]
		];
	}

	/**
	 * Vector moves every section from the toolbox onward into its page-tools menu, so the skin list
	 * gets a section of its own there and the toolbox stays as Hider left it.
	 */
	public function testVectorGetsTheSkinListInItsOwnSection() {
		$sidebar = $this->sidebarFor('vector-2022');

		$this->assertSame([], $sidebar['TOOLBOX'], 'the toolbox is left empty');
		$this->assertSame(
			['t-wikven-skin-vector-2022', 't-wikven-skin-citizen'],
			array_keys($sidebar['wikven-skins'])
		);
		$this->assertSame(
			'./citizen/Installation.html',
			$sidebar['wikven-skins']['t-wikven-skin-citizen']['href']
		);
		$this->assertArrayNotHasKey('href', $sidebar['wikven-skins']['t-wikven-skin-vector-2022']);
	}

	/**
	 * Citizen keeps it in the toolbox: it takes only the toolbox into its page-tools menu, so a
	 * section of our own would land in the sidebar drawer instead.
	 */
	public function testCitizenKeepsTheSkinListInTheToolbox() {
		$sidebar = $this->sidebarFor('citizen');

		$this->assertArrayNotHasKey('wikven-skins', $sidebar);
		$this->assertSame(
			['t-wikven-skin-vector-2022', 't-wikven-skin-citizen'],
			array_keys($sidebar['TOOLBOX'])
		);
		$this->assertSame(
			'../Installation.html',
			$sidebar['TOOLBOX']['t-wikven-skin-vector-2022']['href']
		);
	}

	/**
	 * Minerva gets nothing here. Its toolbox is the page-actions menu, which is not where a
	 * site-wide setting belongs, so fillMinervaMenu.php writes these into its main menu instead.
	 */
	public function testMinervaIsLeftToItsMainMenu() {
		$sidebar = $this->sidebarFor('minerva');

		$this->assertSame(['TOOLBOX' => []], $sidebar);
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
