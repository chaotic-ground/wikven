<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\CacheName;
use MediaWiki\Language\Language;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\CacheName
 */
class CacheNameTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider provideNames
	 */
	public function testToOutputPath(string $cached, string $expected) {
		$language = $this->createMock(Language::class);
		$language->method('getNsText')->willReturnMap([[NS_MAIN, ''], [NS_HELP, 'Help']]);

		$this->assertSame($expected, CacheName::toOutputPath($cached, $language));
	}

	public static function provideNames() {
		return [
			'main namespace loses the prefix entirely' => ['ns0%3AInstallation.html', 'Installation.html'],
			'another namespace keeps its name' => ['ns12%3AContents.html', 'Help:Contents.html'],
			'escaped dots come back' => ['ns0%3AFile%2Esvg.html', 'File.svg.html'],
			'a subpage becomes a directory' => ['ns0%3AManual%2FDeep.html', 'Manual/Deep.html'],
			'an unprefixed name is left alone' => ['index.html', 'index.html']
		];
	}
}
