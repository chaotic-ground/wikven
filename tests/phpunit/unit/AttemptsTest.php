<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Attempts;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Attempts
 */
class AttemptsTest extends MediaWikiUnitTestCase {
	/** Answers false the first $failures times it is called, then true. */
	private function failing(int $failures, array &$calls): callable {
		return static function () use ($failures, &$calls) {
			$calls[] = count($calls) + 1;
			return count($calls) > $failures;
		};
	}

	public function testWorkThatSucceedsIsRunOnceAndNotWaitedFor() {
		$calls = [];
		$waits = [];
		$ok = Attempts::until(
			$this->failing(0, $calls),
			3,
			static function (int $attempt) use (&$waits) {
				$waits[] = $attempt;
			}
		);
		$this->assertTrue($ok);
		$this->assertCount(1, $calls);
		$this->assertSame([], $waits, 'nothing to wait for before a first attempt');
	}

	public function testItKeepsTryingUntilSomethingWorks() {
		$calls = [];
		$waits = [];
		$ok = Attempts::until(
			$this->failing(2, $calls),
			3,
			static function (int $attempt) use (&$waits) {
				$waits[] = $attempt;
			}
		);
		$this->assertTrue($ok);
		$this->assertCount(3, $calls);
		$this->assertSame([2, 3], $waits, 'once before each retry, numbered by the attempt to come');
	}

	public function testItGivesUpAfterTheLastAttempt() {
		$calls = [];
		$waits = [];
		$ok = Attempts::until(
			$this->failing(PHP_INT_MAX, $calls),
			3,
			static function (int $attempt) use (&$waits) {
				$waits[] = $attempt;
			}
		);
		$this->assertFalse($ok);
		$this->assertCount(3, $calls, 'three attempts, not a fourth');
		$this->assertSame([2, 3], $waits, 'and no wait after the one that failed last');
	}

	/**
	 * @dataProvider provideTooFewAttempts
	 */
	public function testWorkIsAlwaysDoneAtLeastOnce(int $attempts) {
		$calls = [];
		$waits = [];
		$ok = Attempts::until(
			$this->failing(0, $calls),
			$attempts,
			static function (int $attempt) use (&$waits) {
				$waits[] = $attempt;
			}
		);
		$this->assertTrue($ok, 'asking for no attempts still means do the thing');
		$this->assertCount(1, $calls);
		$this->assertSame([], $waits, 'and does it without waiting first');
	}

	public static function provideTooFewAttempts() {
		return ['none' => [0], 'negative' => [-1]];
	}

	public function testTheWaitDoublesAndTheFirstAttemptNeverWaits() {
		$this->assertSame(0, Attempts::backoff(1));
		$this->assertSame(2, Attempts::backoff(2));
		$this->assertSame(4, Attempts::backoff(3));
		$this->assertSame(8, Attempts::backoff(4));
	}
}
