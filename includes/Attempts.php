<?php

namespace MediaWiki\Extension\Wikven;

/**
 * How many times the build asks somebody else's server for something before it gives up.
 *
 * One refusal used to end the build -- failed by a service having a bad minute rather than by
 * anything in the source.
 *
 * Nothing in the box does this: HttpRequestFactory carries no retry, and Guzzle's middleware would
 * miss the caller that repeats a `composer update`.
 */
class Attempts {
	/** How many times one fetch is tried. Two retries is enough for a blip and short of a queue. */
	public const FETCH = 3;

	/**
	 * Run $work until it answers with something other than false, at most $attempts times.
	 *
	 * False is the failure, and only false: a request can succeed with an empty body, and a bool
	 * caller says so by returning true.
	 *
	 * @param callable():mixed $work Does the work; answers false if it failed, anything else if not.
	 * @param int $attempts How many times to run it. Below one is treated as one: a caller that asks
	 *   for no attempts still means "do the thing", and a silent no-op here would read as success.
	 * @param callable(int):mixed $before Run before every attempt after the first, given the number
	 *   of the attempt about to be made. Where the reporting, the waiting and any cleaning up go.
	 * @return mixed What the first attempt that did not fail answered, or false if none succeeded.
	 */
	public static function until(callable $work, int $attempts, callable $before) {
		$attempts = max(1, $attempts);
		$answer = false;
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			if ($attempt > 1) {
				$before($attempt);
			}
			$answer = $work();
			if ($answer !== false) {
				break;
			}
		}
		return $answer;
	}

	/**
	 * Seconds to wait before attempt number $attempt: none before the first, then 2, 4, 8...
	 *
	 * Doubling rather than a fixed wait: a 500 wants a moment, a 429 wants to be asked less often.
	 */
	public static function backoff(int $attempt): int {
		return $attempt <= 1 ? 0 : 1 << ( $attempt - 1 );
	}

	/** Wait out the backoff before attempt number $attempt. The $before most callers want. */
	public static function sleep(int $attempt): void {
		sleep(self::backoff($attempt));
	}
}
