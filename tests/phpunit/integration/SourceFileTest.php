<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\SourceFile;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SourceFile
 */
class SourceFileTest extends MediaWikiIntegrationTestCase {
	/**
	 * Only titles whose content model core resolves without a third-party
	 * extension are used here (.css/.js in NS_MEDIAWIKI, plain wikitext, images),
	 * so the test does not depend on TemplateStyles/Scribunto being installed.
	 *
	 * @dataProvider providePageFiles
	 */
	public function testIsPageFile(string $relativePath, bool $expected) {
		$this->assertSame($expected, SourceFile::isPageFile($relativePath));
	}

	public static function providePageFiles() {
		return [
			'wikitext page' => ['Getting Started.wikitext', true],
			'css page' => ['MediaWiki:Common.css', true],
			'js page' => ['MediaWiki:Common.js', true],
			'image binary' => ['Bakery oven.jpg', false],
			'png asset' => ['logo.png', false],
			'config file' => ['.wikven.yaml', false]
		];
	}

	/**
	 * filenameToTitle() and titleToFilename() must be inverses, so the edit and
	 * history links the static export derives from a page title resolve back to
	 * the source file the page was imported from.
	 *
	 * @dataProvider provideRoundTrip
	 */
	public function testFilenameRoundTrip(string $filename) {
		$title = SourceFile::filenameToTitle($filename);
		$this->assertSame($filename, SourceFile::titleToFilename($title));
	}

	public static function provideRoundTrip() {
		return [
			'plain page' => ['Getting Started.wikitext'],
			'css page keeps its extension' => ['MediaWiki:Common.css'],
			'js page keeps its extension' => ['MediaWiki:Common.js'],
			'file description page' => ['File:Bakery oven.jpg.wikitext'],
			'dotted title that is not a content model' => ['wikven.yaml.wikitext']
		];
	}

	/**
	 * exists() reports whether a page was imported from a source file (so the
	 * edit/history/view-source links have something to point at) rather than
	 * generated during the build, like the Version page.
	 */
	public function testExists() {
		$dir = $this->getNewTempDirectory();
		file_put_contents($dir . '/Getting Started.wikitext', '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);

		$this->assertTrue(SourceFile::exists('Getting Started'), 'imported page');
		$this->assertFalse(SourceFile::exists('Version'), 'generated page');
	}
}
