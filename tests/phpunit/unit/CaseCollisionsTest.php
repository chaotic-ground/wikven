<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Source\CaseCollisions;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Source\CaseCollisions
 */
class CaseCollisionsTest extends MediaWikiUnitTestCase {
	public function testPagesDifferingOnlyInCaseAreOneFile() {
		$this->assertSame(
			[['Foo.wikitext', 'foo.wikitext']],
			CaseCollisions::find(['Foo.wikitext', 'Help.wikitext', 'foo.wikitext'], [])
		);
	}

	public function testSubpagesAreComparedByTheirWholePath() {
		$this->assertSame(
			[['Guide/Setup.wikitext', 'guide/setup.wikitext']],
			CaseCollisions::find(['Guide/Setup.wikitext', 'guide/setup.wikitext', 'guide/Other.wikitext'], [])
		);
	}

	public function testCaseIsFoldedBeyondAscii() {
		$this->assertSame(
			[['Ärger.wikitext', 'ärger.wikitext']],
			CaseCollisions::find(['Ärger.wikitext', 'ärger.wikitext'], [])
		);
	}

	public function testImagesInTwoDirectoriesBuildToOneFile() {
		$paths = ['Diagram.png', 'Guide/diagram.png'];
		$this->assertSame([['Diagram.png', 'Guide/diagram.png']], CaseCollisions::find($paths, $paths));
	}

	public function testImagesSideBySideAreReportedOnce() {
		$paths = ['Logo.png', 'logo.png'];
		$this->assertSame([['Logo.png', 'logo.png']], CaseCollisions::find($paths, $paths));
	}

	public function testAnImageAndItsDescriptionPageAreNotACollision() {
		$paths = ['Bakery oven.jpg', 'File:Bakery oven.jpg.wikitext'];
		$this->assertSame([], CaseCollisions::find($paths, ['Bakery oven.jpg']));
	}

	public function testImagesSharingTheirExactNameAreLeftToImageImport() {
		$paths = ['diagram.png', 'Guide/diagram.png'];
		$this->assertSame([], CaseCollisions::find($paths, $paths));
	}
}
