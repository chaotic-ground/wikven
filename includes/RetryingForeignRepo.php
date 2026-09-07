<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\FileRepo\ForeignAPIRepo;
use RuntimeException;

/**
 * A foreign file repository (Wikimedia Commons through InstantCommons, or any other api.php repo)
 * that gives a request the remote failed to answer a second and a third chance.
 *
 * An empty lookup is not merely a missing thumbnail: ForeignAPIFile::transform() then reads a
 * media handler language nothing sets, so the parse dies and takes the build with it.
 */
class RetryingForeignRepo extends ForeignAPIRepo {
	/**
	 * @inheritDoc
	 *
	 * Core's only caller is the ForeignAPIFile::transform() branch above, which cannot cope with a
	 * false. So end the build here, where the file and the reason are still known.
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
	 */
	public function httpGet($url, $timeout = 'default', $options = [], &$mtime = false) {
		return Attempts::until(
			function () use ($url, $timeout, $options, &$mtime) {
				return parent::httpGet($url, $timeout, $options, $mtime);
			},
			Attempts::FETCH,
			[Attempts::class, 'sleep']
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Named here rather than passed to each request, because httpGet() hands core the options and
	 * core writes this key over whatever came in. Left alone it names the library, not the tool.
	 */
	public function getUserAgent() {
		return UserAgent::tool() . ' ' . parent::getUserAgent();
	}
}
