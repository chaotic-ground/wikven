<?php

namespace MediaWiki\Extension\Wikven;

/**
 * How many times the build asks somebody else's server for something before it gives up.
 *
 * A bake fetches third-party extensions and skins over the network, and asks Commons for the
 * thumbnail of every image a page embeds. Until recently one refusal ended the build. That is a
 * build failed by a service having a bad minute rather than by anything in the source: GitHub
 * answered 500 for git and archive traffic for hours on 2026-08-17, and codeload has answered 429
 * under load. Neither says anything about the commit being built.
 *
 * Nothing in the box does this. MediaWiki's HttpRequestFactory and MWHttpRequest carry no retry at
 * all; wikimedia/wait-condition-loop, which core does bundle, spends a time budget waiting for a
 * condition to come true, which is not the shape of one fetch that either worked or did not and can
 * take minutes; and Guzzle's retry middleware, which core also bundles, would reach only half of
 * the callers here, since one of them repeats a `composer update` in a subprocess.
 */
class Attempts {
	/** How many times one fetch is tried. Two retries is enough for a blip and short of a queue. */
	public const FETCH = 3;

	/**
	 * Run $work until it answers with something other than false, at most $attempts times.
	 *
	 * False is the failure, and only false: a request can succeed with an empty body, and a caller
	 * that answers with a bool says so by returning true. Whatever the successful attempt answered
	 * is what comes back, so a caller that wants the body gets the body and one that only wants to
	 * know gets its true.
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
	 * Doubling rather than a fixed wait because the two failures worth retrying differ in what they
	 * are asking for. A 500 wants a moment; a 429 wants to be asked less often, and answering it at
	 * the same rate is the thing that keeps it a 429.
	 */
	public static function backoff(int $attempt): int {
		return $attempt <= 1 ? 0 : 1 << ( $attempt - 1 );
	}

	/** Wait out the backoff before attempt number $attempt. The $before most callers want. */
	public static function sleep(int $attempt): void {
		sleep(self::backoff($attempt));
	}
}
