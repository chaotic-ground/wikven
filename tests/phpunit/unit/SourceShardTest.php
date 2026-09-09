<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Build\SourceShard;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Build\SourceShard
 */
class SourceShardTest extends MediaWikiUnitTestCase {
	public function testReadsAShareOfSeveral(): void {
		$this->assertSame([0, 4], SourceShard::of('0/4'));
		$this->assertSame([3, 4], SourceShard::of(' 3/4 '));
	}

	/**
	 * Anything else is one reader taking everything, which is the answer that leaves no page unparsed.
	 *
	 * @dataProvider provideWholeList
	 */
	public function testAnythingElseIsTheWholeList(string $shard): void {
		$this->assertSame([0, 1], SourceShard::of($shard));
	}

	public static function provideWholeList(): array {
		return [
			'empty' => [''],
			'not a share' => ['half'],
			'no total' => ['2'],
			'past the end' => ['4/4'],
			'nothing to share between' => ['0/0'],
			'negative' => ['-1/4']
		];
	}
}
