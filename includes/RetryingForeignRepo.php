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
		$attempts = Attempts::FETCH;
		$what = "Wikven: the '{$this->getName()}' repository has no thumbnail URL for \"$name\" at $size";
		$tries = "after $attempts attempt(s).";
		$why = 'The build cannot make that image local, and would publish a page missing it.';
		$fix = 'Check the network connection and the system CA certificates, then build again.';
		throw new RuntimeException("$what $tries $why $fix");
	}

	/**
	 * @inheritDoc
	 *
	 * Answering with a body or with false is what Attempts::until reads as worked or did not, so
	 * the loop is the one the fetching side of the build uses, and the waits are the same waits.
	 *
	 * It is also where every request this repository makes passes, which makes it the place to
	 * say who is asking: core would otherwise sign each lookup "MediaWiki/" and its version,
	 * naming the library rather than the tool, and Commons is the server wikven asks most. A
	 * caller that brought its own string keeps it.
	 */
	public function httpGet($url, $timeout = 'default', $options = [], &$mtime = false) {
		$options['userAgent'] ??= UserAgent::string();
		return Attempts::until(
			function () use ($url, $timeout, $options, &$mtime) {
				return parent::httpGet($url, $timeout, $options, $mtime);
			},
			Attempts::FETCH,
			[Attempts::class, 'sleep']
		);
	}
}
