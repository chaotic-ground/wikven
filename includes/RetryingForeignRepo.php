<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\FileRepo\ForeignAPIRepo;
use RuntimeException;

/**
 * A foreign file repository (Wikimedia Commons through InstantCommons, or any other api.php repo)
 * that gives a request the remote failed to answer a second and a third chance, and that says so
 * plainly when it still has no thumbnail to show for it.
 *
 * Every parse of a page embedding a Commons image asks commons.wikimedia.org for that image's
 * thumbnail URL, and MediaWiki makes that request once, with no retry. A lookup that comes back
 * empty is not merely a missing thumbnail either: ForeignAPIFile::transform() then takes an error
 * path that reads the media handler's language, which nothing on that path ever sets, so the parse
 * dies with "Need to set language before accessing" and takes the whole build with it. Repeating
 * the request, as StoreImages already repeats the downloads of the images themselves, means one
 * hiccup at Commons no longer does that; failing the lookup loudly means that when the image
 * really cannot be had, the build says which one and why instead of quoting that riddle.
 */
class RetryingForeignRepo extends ForeignAPIRepo {
	/** How many times one request is made before the repository reports it as failed. */
	private const ATTEMPTS = 3;

	/**
	 * @inheritDoc
	 *
	 * Core's only caller of this is the ForeignAPIFile::transform() branch described above, which
	 * cannot cope with a false: it reads a language off a media handler that has none, and throws.
	 * A thumbnail this repository cannot produce is a build that cannot produce a self-contained
	 * site, so end it here, where the file and the reason are still known, rather than three
	 * frames later with neither. StoreImages already aborts the build on the same grounds when it
	 * cannot download an image, "rather than publish output that still hotlinks images".
	 *
	 * This fails no build that would otherwise have passed: every false returned from here is
	 * already fatal today, just unreadably so.
	 */
	public function getThumbUrlFromCache($name, $width, $height, $params = '') {
		$url = parent::getThumbUrlFromCache($name, $width, $height, $params);
		if ($url !== false) {
			return $url;
		}

		$size = $height > 0 ? "{$width}x{$height}" : "{$width}px";
		$attempts = self::ATTEMPTS;
		$what = "Wikven: the '{$this->getName()}' repository has no thumbnail URL for \"$name\" at $size";
		$tries = "after $attempts attempt(s).";
		$why = 'The build cannot make that image local, and would publish a page missing it.';
		$fix = 'Check the network connection and the system CA certificates, then build again.';
		throw new RuntimeException("$what $tries $why $fix");
	}

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
