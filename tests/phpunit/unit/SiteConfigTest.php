<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SiteConfig;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SiteConfig
 */
class SiteConfigTest extends MediaWikiUnitTestCase {
	private const KNOWN = ['WikvenFooterUrl', 'WikvenEditUrl', 'WikvenMainPage'];

	public function testSoundFileHasNoWarnings() {
		$data = [
			'config' => ['WikvenEditUrl' => 'x', 'Sitename' => 'S'],
			'extensions' => ['Foo'],
			'skins' => ['Vector']
		];
		$this->assertSame([], SiteConfig::lint($data, self::KNOWN));
	}

	public function testNonMapIsRejected() {
		$this->assertSame(['the file is not a map; ignoring it.'], SiteConfig::lint('oops', self::KNOWN));
	}

	public function testUnknownTopLevelKeyWarns() {
		$warnings = SiteConfig::lint(['extension' => ['Foo']], self::KNOWN);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown top-level key 'extension'", $warnings[0]);
	}

	public function testWrongTypedListWarns() {
		$warnings = SiteConfig::lint(['extensions' => 'Foo'], self::KNOWN);
		$this->assertContains("'extensions' must be a list.", $warnings);
	}

	public function testMisspelledWikvenVariableWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenFooterURL' => 'x']], self::KNOWN);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown config 'WikvenFooterURL'", $warnings[0]);
	}

	public function testNonWikvenConfigKeyIsNotFlagged() {
		$this->assertSame(
			[],
			SiteConfig::lint(['config' => ['Sitename' => 'S', 'Localtimezone' => 'UTC']], self::KNOWN)
		);
	}
}
