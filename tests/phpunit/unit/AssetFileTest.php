<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\AssetFile;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\AssetFile
 */
class AssetFileTest extends MediaWikiUnitTestCase {
	public function testTheOutputRootIsSpelledAsADot() {
		$this->assertSame(
			['href' => './site.styles.css', 'path' => '/w/dist/site.styles.css'],
			AssetFile::locate('/w/dist', '.', 'site.styles.css')
		);
	}

	/**
	 * The failure this exists to catch: buildStyles writes site.styles.css under the asset
	 * directory, so a link written for that directory while the file is looked for at the output
	 * root finds nothing, and the page ships without MediaWiki:Common.css or any styles-only gadget.
	 */
	public function testAnAssetDirectoryMovesTheFileAsWellAsTheHref() {
		$this->assertSame(
			['href' => './assets/site.styles.css', 'path' => '/w/dist/assets/site.styles.css'],
			AssetFile::locate('/w/dist', 'assets', 'site.styles.css')
		);
	}

	public function testTheBundledWebfontsAreLocatedTheSameWay() {
		$this->assertSame(
			['href' => './assets/webfonts.css', 'path' => '/w/dist/assets/webfonts.css'],
			AssetFile::locate('/w/dist', 'assets', 'webfonts.css')
		);
	}

	public function testANestedAssetDirectoryKeepsAllOfItsLevels() {
		$this->assertSame(
			['href' => './static/css/site.styles.css', 'path' => '/w/dist/static/css/site.styles.css'],
			AssetFile::locate('/w/dist', 'static/css', 'site.styles.css')
		);
	}

	public function testAnEmptyAssetDirectoryNamesTheOutputRootToo() {
		$this->assertSame(
			['href' => './site.styles.css', 'path' => '/w/dist/site.styles.css'],
			AssetFile::locate('/w/dist', '', 'site.styles.css')
		);
	}

	/** A directory written with slashes around it is the same directory. */
	public function testSurroundingSlashesAreNotPartOfTheAssetDirectory() {
		$located = ['href' => './assets/site.styles.css', 'path' => '/w/dist/assets/site.styles.css'];
		$this->assertSame($located, AssetFile::locate('/w/dist', 'assets/', 'site.styles.css'));
		$this->assertSame($located, AssetFile::locate('/w/dist', '/assets/', 'site.styles.css'));
	}

	public function testATrailingSlashOnTheOutputDirectoryDoesNotDoubleUp() {
		$this->assertSame(
			'/w/dist/assets/site.styles.css',
			AssetFile::locate('/w/dist/', 'assets', 'site.styles.css')['path']
		);
	}

	/** "" and "." both name the output root, and neither is a path segment of its own. */
	public function testBothSpellingsOfTheRootAreNoDirectory() {
		$this->assertSame('', AssetFile::directory('.'));
		$this->assertSame('', AssetFile::directory(''));
		$this->assertSame('assets', AssetFile::directory('/assets/'));
		$this->assertSame('static/css', AssetFile::directory('static/css'));
	}

	public function testPathIsTheOutputRootWhenThereIsNoDirectory() {
		$this->assertSame('/w/dist', AssetFile::path('/w/dist/', '.'));
		$this->assertSame('/w/dist/assets', AssetFile::path('/w/dist', 'assets'));
		$this->assertSame('/w/dist/static/css', AssetFile::path('/w/dist', 'static/css'));
	}

	/**
	 * The bundled webfont files are copied to a fixed directory at the output root, so a stylesheet
	 * in the asset directory reaches them by stepping up this many times.
	 */
	public function testDepthCountsTheLevelsBelowTheRoot() {
		$this->assertSame(0, AssetFile::depth('.'));
		$this->assertSame(0, AssetFile::depth(''));
		$this->assertSame(1, AssetFile::depth('assets'));
		$this->assertSame(2, AssetFile::depth('static/css'));
	}

	/**
	 * Two references to one picture must name one file, and a rebuild of an unchanged site must
	 * name the same one -- the whole point of hashing the reference rather than counting (#411).
	 */
	public function testOnePictureIsOneNameHoweverOftenItIsAsked() {
		$this->assertSame(
			AssetFile::imageName('/logo.png'),
			AssetFile::imageName('/logo.png')
		);
		$this->assertNotSame(
			AssetFile::imageName('/logo.png'),
			AssetFile::imageName('/card.png')
		);
	}

	/**
	 * The name a head tag has to be able to predict before storeImages has run: this is the rule
	 * both sides read, and the picture the documentation site publishes is the case that proves it.
	 */
	public function testTheNameIsTheOneStoreImagesWrites() {
		$this->assertSame('img-5d163c4d9d29.png', AssetFile::imageName('/logo.png'));
	}

	/** A remote picture is keyed by its whole URL, and takes its extension from the same string. */
	public function testARemotePictureKeepsItsExtension() {
		$url = 'https://upload.wikimedia.org/wikipedia/commons/e/ee/Diagram.png?width=250';
		$this->assertStringEndsWith('.png', AssetFile::imageName($url, $url));
	}

	/** Where the key is not what carries the extension, the second argument is. */
	public function testTheExtensionCanComeFromSomewhereElseThanTheKey() {
		$this->assertStringEndsWith(
			'.jpg',
			AssetFile::imageName('//example.org/photo?size=large', 'https://example.org/photo.jpg')
		);
	}

	/**
	 * A reference with nothing extension-shaped on the end still names a file. "img" rather than
	 * nothing, because a name ending in a bare dot is not one a static host serves.
	 *
	 * @dataProvider provideOddExtensions
	 */
	public function testAnExtensionIsAlwaysASafeOne(string $url, string $expected) {
		$this->assertSame($expected, AssetFile::extension($url));
	}

	public static function provideOddExtensions() {
		return [
			'a plain name' => ['/logo.png', 'png'],
			'shouted' => ['/LOGO.PNG', 'png'],
			'a query after it' => ['https://example.org/a/b.svg?version=9', 'svg'],
			'no extension at all' => ['/logo', 'img'],
			'not alphanumeric' => ['/logo.tar.gz~', 'img'],
			'nothing to read' => ['', 'img']
		];
	}
}
