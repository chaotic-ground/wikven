<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\BuildConcurrency;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\BuildConcurrency
 */
class BuildConcurrencyTest extends MediaWikiUnitTestCase {
	/** Four processors, as a runner has. */
	private const CPUINFO = "processor\t: 0\nvendor_id\t: X\n\nprocessor\t: 1\n\nprocessor\t: 2\n\nprocessor\t: 3\n";

	public function testItRunsOnePassPerProcessor() {
		$this->assertSame(4, BuildConcurrency::limit(6, '', self::CPUINFO, ''));
	}

	public function testItNeverRunsMorePassesThanThereAre() {
		$this->assertSame(3, BuildConcurrency::limit(3, '', self::CPUINFO, ''));
		$this->assertSame(3, BuildConcurrency::limit(3, '8', self::CPUINFO, ''));
	}

	public function testAConfiguredNumberWins() {
		$this->assertSame(1, BuildConcurrency::limit(6, '1', self::CPUINFO, ''));
		$this->assertSame(6, BuildConcurrency::limit(6, ' 8 ', self::CPUINFO, ''));
	}

	/**
	 * @dataProvider provideNonsense
	 */
	public function testNonsenseIsNotANumberOfPasses(string $configured) {
		$this->assertSame(4, BuildConcurrency::limit(6, $configured, self::CPUINFO, ''));
	}

	public static function provideNonsense() {
		return [
			'unset' => [''],
			'zero' => ['0'],
			'negative' => ['-2'],
			'a word' => ['many'],
			'a fraction' => ['2.5']
		];
	}

	public function testACpuLimitCountsForMoreThanTheHostsProcessors() {
		// 200ms of every 100ms period: two processors, on a host that has four.
		$this->assertSame(2, BuildConcurrency::processors(self::CPUINFO, "200000 100000\n"));
	}

	public function testAPartialProcessorIsStillAPass() {
		$this->assertSame(1, BuildConcurrency::processors(self::CPUINFO, "50000 100000\n"));
	}

	public function testAnUnlimitedCgroupLeavesTheCountAlone() {
		$this->assertSame(4, BuildConcurrency::processors(self::CPUINFO, "max 100000\n"));
	}

	public function testALimitAboveTheHostsProcessorsDoesNotRaiseTheCount() {
		$this->assertSame(4, BuildConcurrency::processors(self::CPUINFO, "800000 100000\n"));
	}

	/**
	 * @dataProvider provideUnreadableMachines
	 */
	public function testAMachineThatSaysNothingRunsOnePassAtATime(string $cpuinfo, string $cpuMax) {
		$this->assertSame(1, BuildConcurrency::processors($cpuinfo, $cpuMax));
	}

	public static function provideUnreadableMachines() {
		return [
			'no cpuinfo' => ['', ''],
			'no cpuinfo, a limit' => ['', "400000 100000\n"],
			'cpuinfo without processors' => ["vendor_id\t: X\n", '']
		];
	}
}
