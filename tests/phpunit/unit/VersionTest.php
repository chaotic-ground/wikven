<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Version;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Version
 */
class VersionTest extends MediaWikiUnitTestCase {
	/** What {{WIKVENVERSION}} is for: the number release-please keeps in extension.json. */
	public function testItReadsTheVersionAComponentDeclares() {
		$things = [
			'Wikven' => ['version' => '1.2.3', 'path' => '/x/extensions/Wikven/extension.json'],
			'Translate' => ['version' => '2026-01-01', 'path' => '/x/extensions/Translate/extension.json']
		];

		$this->assertSame('1.2.3', Version::of($things, 'Wikven'));
		$this->assertSame('2026-01-01', Version::of($things, 'Translate'));
	}

	/** A wiki that has not loaded it has no answer to give, and a parser run has nobody to tell. */
	public function testAComponentThatIsNotThereHasNoVersion() {
		$this->assertSame('', Version::of([], 'Wikven'));
		$this->assertSame('', Version::of(['Vector' => ['version' => '1.0']], 'Wikven'));
	}

	/** A manifest need not declare one, and ExtensionRegistry keeps whatever it finds. */
	public function testAComponentDeclaringNoVersionHasNone() {
		$this->assertSame('', Version::of(['Wikven' => []], 'Wikven'));
		$this->assertSame('', Version::of(['Wikven' => ['version' => null]], 'Wikven'));
		$this->assertSame('', Version::of(['Wikven' => ['version' => ['1.2.3']]], 'Wikven'));
	}

	/** Taken as written: a version is a string a component chose, not a number to be tidied. */
	public function testTheVersionIsTakenAsWritten() {
		$this->assertSame(' 1.2.3-alpha+7 ', Version::of(['Wikven' => ['version' => ' 1.2.3-alpha+7 ']], 'Wikven'));
	}
}
