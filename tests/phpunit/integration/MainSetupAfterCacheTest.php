<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Hooks\Main;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Main
 */
class MainSetupAfterCacheTest extends MediaWikiIntegrationTestCase {
	private function main(): Main {
		return new Main($this->getServiceContainer()->getMainConfig());
	}

	/** The upload URL RepoGroup itself would give $name, so tests aren't pinned to its exact form. */
	private function expectedUrl(string $name): string {
		$title = Title::makeTitleSafe(NS_FILE, $name);
		return MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile($title)->getUrl();
	}

	public function testNoWikvenLogosLeavesWgLogosUntouched() {
		$this->overrideConfigValue('WikvenLogos', []);
		$this->setMwGlobals('wgLogos', ['icon' => '/existing.svg']);
		$this->main()->onSetupAfterCache();
		$this->assertSame(['icon' => '/existing.svg'], $GLOBALS['wgLogos']);
	}

	public function testAMissingSourceFileIsSkipped() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->getNewTempDirectory());
		$this->overrideConfigValue('WikvenLogos', ['1x' => 'missing.png']);
		$this->setMwGlobals('wgLogos', []);
		$this->main()->onSetupAfterCache();
		$this->assertArrayNotHasKey('1x', $GLOBALS['wgLogos']);
	}

	/**
	 * The file exists as "über.png"; under ASCII-only ucfirst() the old hand-rolled code linked it
	 * as "über.png" while MediaWiki (CapitalLinks) uploads it as "Über.png". Delegating to Title
	 * gets the UTF-8-aware capitalization right.
	 */
	public function testAScalarLogoResolvesThroughTitleAndRepoGroup() {
		$src = $this->getNewTempDirectory();
		file_put_contents("$src/über.png", 'not a real png, just needs to exist');
		$this->overrideConfigValue('WikvenSourceDirectory', $src);
		$this->overrideConfigValue('WikvenLogos', ['1x' => 'über.png']);
		$this->setMwGlobals('wgLogos', []);

		$this->main()->onSetupAfterCache();

		$this->assertSame($this->expectedUrl('über.png'), $GLOBALS['wgLogos']['1x']);
	}

	public function testAnArrayFormLogoHasOnlyItsSrcResolved() {
		$src = $this->getNewTempDirectory();
		file_put_contents("$src/logo.png", 'not a real png, just needs to exist');
		$this->overrideConfigValue('WikvenSourceDirectory', $src);
		$this->overrideConfigValue('WikvenLogos', [
			'icon' => ['src' => 'logo.png', 'width' => 50, 'height' => 50]
		]);
		$this->setMwGlobals('wgLogos', []);

		$this->main()->onSetupAfterCache();

		$this->assertSame(
			['src' => $this->expectedUrl('logo.png'), 'width' => 50, 'height' => 50],
			$GLOBALS['wgLogos']['icon']
		);
	}

	public function testAnArrayFormLogoWithoutSrcIsKeptAsIs() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->getNewTempDirectory());
		$this->overrideConfigValue('WikvenLogos', ['icon' => ['width' => 50]]);
		$this->setMwGlobals('wgLogos', []);

		$this->main()->onSetupAfterCache();

		$this->assertSame(['width' => 50], $GLOBALS['wgLogos']['icon']);
	}

	public function testExistingWgLogosEntriesNotNamedByWikvenAreKept() {
		$this->overrideConfigValue('WikvenLogos', []);
		$this->setMwGlobals('wgLogos', ['wordmark' => '/existing.svg']);
		$this->main()->onSetupAfterCache();
		$this->assertSame('/existing.svg', $GLOBALS['wgLogos']['wordmark']);
	}

	/**
	 * Citizen turns core's search wiring off, because its own search is the command palette; the
	 * export keeps the plain form the skin renders under it, and that is what the wiring attaches
	 * to. Only Citizen's answer is corrected, so no other skin's choice is disturbed.
	 *
	 * @dataProvider provideSearchWiringSkins
	 */
	public function testCitizenSearchWiringIsRestored(string $skin, ?bool $expected) {
		$resourceLoader = $this->getServiceContainer()->getResourceLoader();
		$context = new Context($resourceLoader, new FauxRequest(['skin' => $skin]));

		$config = ['search' => false, 'searchModule' => 'ext.sifter'];
		$this->main()->enableCitizenSearch($context, $config);

		$this->assertSame($expected, $config['search']);
		$this->assertSame('ext.sifter', $config['searchModule'], 'the module chosen is left alone');
	}

	public static function provideSearchWiringSkins() {
		return [
			'citizen' => ['citizen', true],
			'another skin keeps its own answer' => ['vector-2022', false]
		];
	}
}
