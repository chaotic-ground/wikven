<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\FileRepo\ForeignAPIRepo;

/**
 * A foreign file repository (Wikimedia Commons through InstantCommons, or any other api.php repo)
 * that gives a request the remote failed to answer a second and a third chance.
 *
 * Every parse of a page embedding a Commons image asks commons.wikimedia.org for that image's
 * thumbnail URL, and MediaWiki makes that request once, with no retry. A request that comes back
 * empty is not merely a missing thumbnail: ForeignAPIFile::transform() then takes an error path
 * that reads the media handler's language, which nothing on that path ever sets, so the parse dies
 * with "Need to set language before accessing" instead of rendering the image. One hiccup at
 * Commons therefore aborts the whole build, which is why the request is worth repeating here, the
 * same way StoreImages already repeats the downloads of the images themselves.
 */
class RetryingForeignRepo extends ForeignAPIRepo {
	/** How many times one request is made before the repository reports it as failed. */
	private const ATTEMPTS = 3;

	/** @inheritDoc */
	public function httpGet($url, $timeout = 'default', $options = [], &$mtime = false) {
		return self::retry(
			function () use ($url, $timeout, $options, &$mtime) {
				return parent::httpGet($url, $timeout, $options, $mtime);
			},
			self::ATTEMPTS,
			'sleep'
		);
	}

	/**
	 * Run $request until it answers, at most $attempts times, waiting longer before each retry.
	 *
	 * @param callable():(string|false) $request Returns the response body, or false if it failed.
	 * @param int $attempts How many times $request is run before its failure is passed on.
	 * @param callable(int):mixed $pause Given the seconds to wait before the next attempt (1, then 2, ...).
	 * @return string|false The first body $request returned, or false if every attempt failed.
	 */
	public static function retry(callable $request, int $attempts, callable $pause) {
		$body = false;
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			if ($attempt > 1) {
				$pause($attempt - 1);
			}
			$body = $request();
			if ($body !== false) {
				break;
			}
		}
		return $body;
	}
}
