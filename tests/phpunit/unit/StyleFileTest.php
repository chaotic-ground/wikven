<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\StyleFile;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\StyleFile
 */
class StyleFileTest extends MediaWikiUnitTestCase {
	public function testTheDefaultStyleDirectoryNamesTheOutputRoot() {
		$this->assertSame(
			['href' => './site.styles.css', 'path' => '/w/dist/site.styles.css'],
			StyleFile::locate('/w/dist', '.', 'site.styles.css')
		);
	}

	/**
	 * The failure this exists to catch: buildStyles writes site.styles.css under the style
	 * directory, so a link written for that directory while the file is looked for at the output
	 * root finds nothing, and the page ships without MediaWiki:Common.css or any styles-only gadget.
	 */
	public function testAStyleDirectoryMovesTheFileAsWellAsTheHref() {
		$this->assertSame(
			['href' => './assets/site.styles.css', 'path' => '/w/dist/assets/site.styles.css'],
			StyleFile::locate('/w/dist', 'assets', 'site.styles.css')
		);
	}

	public function testTheBundledWebfontsAreLocatedTheSameWay() {
		$this->assertSame(
			['href' => './assets/webfonts.css', 'path' => '/w/dist/assets/webfonts.css'],
			StyleFile::locate('/w/dist', 'assets', 'webfonts.css')
		);
	}

	public function testANestedStyleDirectoryKeepsAllOfItsLevels() {
		$this->assertSame(
			['href' => './static/css/site.styles.css', 'path' => '/w/dist/static/css/site.styles.css'],
			StyleFile::locate('/w/dist', 'static/css', 'site.styles.css')
		);
	}

	public function testAnEmptyStyleDirectoryNamesTheOutputRootToo() {
		$this->assertSame(
			['href' => './site.styles.css', 'path' => '/w/dist/site.styles.css'],
			StyleFile::locate('/w/dist', '', 'site.styles.css')
		);
	}

	/** A directory written with slashes around it is the same directory. */
	public function testSurroundingSlashesAreNotPartOfTheStyleDirectory() {
		$located = ['href' => './assets/site.styles.css', 'path' => '/w/dist/assets/site.styles.css'];
		$this->assertSame($located, StyleFile::locate('/w/dist', 'assets/', 'site.styles.css'));
		$this->assertSame($located, StyleFile::locate('/w/dist', '/assets/', 'site.styles.css'));
	}

	public function testATrailingSlashOnTheOutputDirectoryDoesNotDoubleUp() {
		$this->assertSame(
			'/w/dist/assets/site.styles.css',
			StyleFile::locate('/w/dist/', 'assets', 'site.styles.css')['path']
		);
	}
}
