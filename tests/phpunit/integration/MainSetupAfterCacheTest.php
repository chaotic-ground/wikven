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

	/**
	 * ULS fetches its input methods from load.php the first time a reader focuses a text field, and
	 * an export has no load.php. Emptying the selector list is what leaves the handler nothing to
	 * bind to, and it belongs here: set at LocalSettings time, an empty array reads to
	 * ExtensionRegistry as "not set" and ULS's own default replaces it.
	 */
	public function testUlsInputMethodsAreLeftWithNoFieldToBindTo() {
		$this->setMwGlobals('wgULSImeSelectors', ['input[type=text]', 'textarea']);
		$this->main()->onSetupAfterCache();
		$this->assertSame([], $GLOBALS['wgULSImeSelectors']);
	}

	/** Without ULS there is no such config, and inventing one would be a global that means nothing. */
	public function testTheUlsSelectorListIsNotInventedWithoutUls() {
		if (array_key_exists('wgULSImeSelectors', $GLOBALS)) {
			$this->markTestSkipped('ULS is loaded here, so there is no "without ULS" to assert');
		}
		$this->main()->onSetupAfterCache();
		$this->assertArrayNotHasKey('wgULSImeSelectors', $GLOBALS);
	}

	/** A directory holding one image file, named as the site would name it in its config. */
	private function sourceDirectoryHolding(string $name): string {
		$directory = $this->getNewTempDirectory();
		file_put_contents("$directory/$name", 'not really a picture');
		return $directory;
	}

	/**
	 * The address of the file the export will write, handed to whichever setting the site named.
	 *
	 * Nothing here knows what reads $wgWikiSeoDefaultImage, which is the point: a setting per
	 * extension that wants a picture is a list with no end, and the address is the only part of
	 * the question that is the build's to answer.
	 */
	public function testAPublishedImageIsAWholeUrlNamingAWrittenFile() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->sourceDirectoryHolding('card.png'));
		$this->overrideConfigValue('WikvenPublishedImages', ['wgWikiSeoDefaultImage' => 'card.png']);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/wiki/');
		$this->overrideConfigValue('WikvenAssetDirectory', 'assets');
		$this->setMwGlobals('wgWikiSeoDefaultImage', null);

		$this->main()->onSetupAfterCache();

		$written = $GLOBALS['wgWikiSeoDefaultImage'];
		$this->assertStringStartsWith('https://example.org/wiki/assets/img-', $written);
		$this->assertStringEndsWith('.png', $written);
	}

	/** Every variable named gets its own picture, whatever each extension prefixes with. */
	public function testEachNamedSettingGetsItsOwnPicture() {
		$directory = $this->sourceDirectoryHolding('card.png');
		file_put_contents("$directory/badge.png", 'not really a picture either');
		$this->overrideConfigValue('WikvenSourceDirectory', $directory);
		$this->overrideConfigValue('WikvenPublishedImages', [
			'wgWikiSeoDefaultImage' => 'card.png',
			'egSomeOtherExtensionImage' => 'badge.png'
		]);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/wiki/');
		$this->setMwGlobals('wgWikiSeoDefaultImage', null);
		$this->setMwGlobals('egSomeOtherExtensionImage', null);

		$this->main()->onSetupAfterCache();

		$this->assertNotNull($GLOBALS['wgWikiSeoDefaultImage']);
		$this->assertNotNull($GLOBALS['egSomeOtherExtensionImage']);
		$this->assertNotSame(
			$GLOBALS['wgWikiSeoDefaultImage'],
			$GLOBALS['egSomeOtherExtensionImage']
		);
	}

	/** The asset directory decides where the file goes, so it decides what the setting names too. */
	public function testAPublishedImageFollowsTheAssetDirectory() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->sourceDirectoryHolding('card.png'));
		$this->overrideConfigValue('WikvenPublishedImages', ['wgWikiSeoDefaultImage' => 'card.png']);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/wiki/');
		$this->overrideConfigValue('WikvenAssetDirectory', 'static/img');
		$this->setMwGlobals('wgWikiSeoDefaultImage', null);

		$this->main()->onSetupAfterCache();

		$this->assertStringStartsWith(
			'https://example.org/wiki/static/img/img-',
			$GLOBALS['wgWikiSeoDefaultImage']
		);
	}

	/**
	 * Without an address to publish at there is no absolute URL to give, and a URL nobody can
	 * resolve is worse than the setting being unset -- so nothing is written.
	 */
	public function testNoSiteUrlPublishesNoImage() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->sourceDirectoryHolding('card.png'));
		$this->overrideConfigValue('WikvenPublishedImages', ['wgWikiSeoDefaultImage' => 'card.png']);
		$this->overrideConfigValue('WikvenSiteUrl', '');
		$this->setMwGlobals('wgWikiSeoDefaultImage', null);

		$this->main()->onSetupAfterCache();

		$this->assertNull($GLOBALS['wgWikiSeoDefaultImage']);
	}

	/** A picture named but not present is reported and skipped, as a missing logo is. */
	public function testAMissingPublishedImageWritesNothing() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->getNewTempDirectory());
		$this->overrideConfigValue('WikvenPublishedImages', ['wgWikiSeoDefaultImage' => 'card.png']);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/wiki/');
		$this->setMwGlobals('wgWikiSeoDefaultImage', null);

		$this->main()->onSetupAfterCache();

		$this->assertNull($GLOBALS['wgWikiSeoDefaultImage']);
	}

	/**
	 * A key that is not a variable name would otherwise set a global nothing ever reads, silently.
	 */
	public function testAKeyThatIsNotAVariableNameIsRefused() {
		$this->overrideConfigValue('WikvenSourceDirectory', $this->sourceDirectoryHolding('card.png'));
		$this->overrideConfigValue('WikvenPublishedImages', ['not a variable' => 'card.png']);
		$this->overrideConfigValue('WikvenSiteUrl', 'https://example.org/wiki/');

		$this->main()->onSetupAfterCache();

		$this->assertArrayNotHasKey('not a variable', $GLOBALS);
	}
}
