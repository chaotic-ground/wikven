<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Source\SourceFile;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Source\SourceFile
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
			'css page' => ['MediaWiki/Common.css', true],
			'js page' => ['MediaWiki/Common.js', true],
			'css page spelled with a colon' => ['MediaWiki:Common.css', true],
			'css asset outside the namespace' => ['assets/Common.css', false],
			'image binary' => ['Bakery oven.jpg', false],
			'png asset' => ['logo.png', false],
			'config file' => ['.wikven.yaml', false]
		];
	}

	/**
	 * @dataProvider provideFilenames
	 */
	public function testFilenameToTitle(string $relativePath, string $expected) {
		$this->assertSame($expected, SourceFile::filenameToTitle($relativePath));
	}

	public static function provideFilenames() {
		return [
			'wikitext marker stripped' => ['Getting Started.wikitext', 'Getting Started'],
			'a subpage' => ['Guide/Setup.wikitext', 'Guide/Setup'],
			'a namespace directory' => ['File/Bakery oven.jpg.wikitext', 'File:Bakery oven.jpg'],
			'content extension kept' => ['MediaWiki/Common.css', 'MediaWiki:Common.css'],
			'a subpage in a namespace' => ['Template/Note/styles.css', 'Template:Note/styles.css'],
			'a namespace with a space in its name' => ['User talk/Example.wikitext', 'User talk:Example'],
			'the colon spelling is read as written' => ['Template:Note.wikitext', 'Template:Note'],
			'a directory has to spell the namespace as a title does' => ['template/Note.wikitext', 'template/Note'],
			'nor with an underscore' => ['User_talk/Example.wikitext', 'User_talk/Example'],
			'the main namespace has no directory' => ['Main/Example.wikitext', 'Main/Example'],
			'a namespace page is not a directory' => ['Template.wikitext', 'Template'],
			'only the trailing marker is stripped' => ['wikven.yaml.wikitext', 'wikven.yaml']
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
			'css page keeps its extension' => ['MediaWiki/Common.css'],
			'js page keeps its extension' => ['MediaWiki/Common.js'],
			'file description page' => ['File/Bakery oven.jpg.wikitext'],
			'a subpage in a namespace' => ['Template/Note/doc.wikitext'],
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

	/**
	 * The "$1" in an edit or history URL is a path in someone's repository, so the slash and the
	 * namespace colon stay as they are and everything else is encoded.
	 *
	 * @dataProvider provideTitlesAsParameters
	 */
	public function testTitleToParam(string $titleText, string $expected) {
		$this->assertSame($expected, SourceFile::titleToParam($titleText));
	}

	public static function provideTitlesAsParameters() {
		return [
			'a space' => ['Getting Started', 'Getting%20Started.wikitext'],
			'a subpage' => ['Manual/Config', 'Manual/Config.wikitext'],
			'a namespace' => ['Help:Search', 'Help/Search.wikitext'],
			'a title with its own content model' => ['MediaWiki:Common.css', 'MediaWiki/Common.css'],
			'a character a URL cannot carry' => ['Q&A', 'Q%26A.wikitext']
		];
	}

	/**
	 * A source still spelling a namespace with a colon gets links to the file it has; the directory
	 * wins where both are there, as the build refuses that tree anyway.
	 */
	public function testTheSpellingOnDiskIsTheOneLinkedTo() {
		$dir = $this->getNewTempDirectory();
		mkdir("$dir/Template");
		file_put_contents("$dir/Template:Old.wikitext", '');
		file_put_contents("$dir/Template/New.wikitext", '');
		file_put_contents("$dir/Template:Both.wikitext", '');
		file_put_contents("$dir/Template/Both.wikitext", '');
		$this->overrideConfigValue('WikvenSourceDirectory', $dir);

		$this->assertSame('Template:Old.wikitext', SourceFile::titleToFilename('Template:Old'));
		$this->assertSame('Template/New.wikitext', SourceFile::titleToFilename('Template:New'));
		$this->assertSame('Template/Both.wikitext', SourceFile::titleToFilename('Template:Both'));
		$this->assertSame('Template/Missing.wikitext', SourceFile::titleToFilename('Template:Missing'));
		$this->assertTrue(SourceFile::exists('Template:Old'));
		$this->assertTrue(SourceFile::exists('Template:New'));
	}

	/**
	 * @dataProvider provideColonPrefixes
	 */
	public function testHasColonPrefix(string $relativePath, bool $expected) {
		$this->assertSame($expected, SourceFile::hasColonPrefix($relativePath));
	}

	public static function provideColonPrefixes() {
		return [
			'the older spelling' => ['Template:Note.wikitext', true],
			'and a subpage of it' => ['Template:Note/styles.css', true],
			'the directory' => ['Template/Note.wikitext', false],
			'a colon in a main-namespace title' => ['Recipe: bread.wikitext', false],
			'a colon after the namespace directory' => ['Template/A:B.wikitext', false]
		];
	}

	/** A wiki running the extension outside a build has no source directory to look in. */
	public function testNothingExistsWithoutASourceDirectory() {
		$this->overrideConfigValue('WikvenSourceDirectory', '');

		$this->assertFalse(SourceFile::exists('Getting Started'));
	}

	/**
	 * A title core will not parse resolves no content model, so it is treated as one that has none
	 * of its own and gets the marker.
	 */
	public function testATitleCoreWillNotParseGetsTheMarker() {
		$this->assertSame('<.wikitext', SourceFile::titleToFilename('<'));
		$this->assertFalse(SourceFile::isPageFile('<'));
	}
}
