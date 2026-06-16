<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SourceFile;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SourceFile::filenameToTitle
 */
class SourceFileTest extends MediaWikiUnitTestCase {
	/**
	 * filenameToTitle is pure string handling (it only strips the ".wikitext"
	 * marker), so it is exercised here without the content-model registry that
	 * isPageFile()/titleToFilename() need; those are covered by the integration
	 * test.
	 *
	 * @dataProvider provideFilenames
	 */
	public function testFilenameToTitle(string $relativePath, string $expected) {
		$this->assertSame($expected, SourceFile::filenameToTitle($relativePath));
	}

	public static function provideFilenames() {
		return [
			'wikitext marker stripped' => ['Getting Started.wikitext', 'Getting Started'],
			'nested wikitext marker' => ['File:Bakery oven.jpg.wikitext', 'File:Bakery oven.jpg'],
			'content extension kept' => ['MediaWiki:Common.css', 'MediaWiki:Common.css'],
			'nested content extension kept' => ['Template:Note/styles.css', 'Template:Note/styles.css'],
			'only the trailing marker is stripped' => ['wikven.yaml.wikitext', 'wikven.yaml']
		];
	}
}
