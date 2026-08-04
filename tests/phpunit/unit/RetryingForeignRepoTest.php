<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\RetryingForeignRepo;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\RetryingForeignRepo
 */
class RetryingForeignRepoTest extends MediaWikiUnitTestCase {
	/** A request answering with each of $results in turn, recording the calls it received. */
	private function requestReturning(array $results, array &$calls): callable {
		return static function () use ($results, &$calls) {
			$calls[] = count($calls) + 1;
			return $results[count($calls) - 1];
		};
	}

	/** A pause that records the waits asked of it instead of sleeping. */
	private function pauseRecording(array &$waits): callable {
		return static function (int $seconds) use (&$waits): void {
			$waits[] = $seconds;
		};
	}

	public function testARequestThatAnswersIsMadeOnce() {
		$calls = [];
		$waits = [];
		$body = RetryingForeignRepo::retry(
			$this->requestReturning(['{"query":{}}'], $calls),
			3,
			$this->pauseRecording($waits)
		);

		$this->assertSame('{"query":{}}', $body);
		$this->assertCount(1, $calls);
		$this->assertSame([], $waits, 'a request that answered must not be waited on');
	}

	public function testAFailedRequestIsRetriedUntilItAnswers() {
		$calls = [];
		$waits = [];
		$body = RetryingForeignRepo::retry(
			$this->requestReturning([false, '{"query":{}}', '{"unreached":{}}'], $calls),
			3,
			$this->pauseRecording($waits)
		);

		$this->assertSame('{"query":{}}', $body);
		$this->assertCount(2, $calls);
		$this->assertSame([1], $waits);
	}

	public function testAnEmptyResponseIsNotMistakenForAFailure() {
		// A repo answering "" did answer; only false means the request itself did not go through.
		$calls = [];
		$waits = [];
		$body = RetryingForeignRepo::retry(
			$this->requestReturning(['', '{"unreached":{}}'], $calls),
			3,
			$this->pauseRecording($waits)
		);

		$this->assertSame('', $body);
		$this->assertCount(1, $calls);
	}

	public function testAPersistentFailureGivesUpAfterTheLastAttempt() {
		$calls = [];
		$waits = [];
		$body = RetryingForeignRepo::retry(
			$this->requestReturning([false, false, false], $calls),
			3,
			$this->pauseRecording($waits)
		);

		$this->assertFalse($body, 'the caller still sees the failure once the attempts run out');
		$this->assertCount(3, $calls);
		$this->assertSame([1, 2], $waits, 'each retry waits a second longer than the one before');
	}
}
