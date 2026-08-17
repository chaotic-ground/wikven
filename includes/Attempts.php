<?php

namespace MediaWiki\Extension\Wikven;

/**
 * How many times the build asks somebody else's server for something before it gives up.
 *
 * A bake fetches third-party extensions and skins over the network, and until now one refusal ended
 * it. That is a build failed by a service having a bad minute rather than by anything in the source:
 * GitHub answered 500 for git and archive traffic for hours on 2026-08-17, and codeload has answered
 * 429 under load. Neither says anything about the commit being built.
 *
 * RetryingForeignRepo does the same thing for Commons thumbnails and keeps its own loop, because
 * what it repeats is a request that answers with a body or with false. What is repeated here either
 * worked or did not, and may have left a half-finished attempt behind for the next one to clear.
 */
class Attempts {
	/** How many times one fetch is tried. Two retries is enough for a blip and short of a queue. */
	public const FETCH = 3;

	/**
	 * Run $work until it reports success, at most $attempts times.
	 *
	 * @param callable():bool $work Does the work; answers whether it succeeded.
	 * @param int $attempts How many times to run it. Below one is treated as one: a caller that asks
	 *   for no attempts still means "do the thing", and a silent no-op here would read as success.
	 * @param callable(int):mixed $before Run before every attempt after the first, given the number
	 *   of the attempt about to be made. Where the reporting, the waiting and any cleaning up go.
	 * @return bool Whether any attempt succeeded.
	 */
	public static function until(callable $work, int $attempts, callable $before): bool {
		$attempts = max(1, $attempts);
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			if ($attempt > 1) {
				$before($attempt);
			}
			if ($work()) {
				return true;
			}
		}
		return false;
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
}
