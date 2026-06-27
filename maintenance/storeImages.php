<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Make every image the generated pages reference available next to the HTML,
 * and rewrite the references to those local copies, so the static export is
 * self-contained and never depends on a live MediaWiki install or on
 * upload.wikimedia.org being reachable. Two sources are handled:
 *
 *   - Wikimedia Commons hotlinks (via InstantCommons): downloaded over HTTP.
 *   - Local File: namespace uploads: copied from the upload directory.
 *
 * Files are dumped flat next to the HTML using the same img-<hash>.<ext> scheme
 * as AssetLocalizer. The hash is taken over the reference (the storage path for
 * local files), so each srcset candidate and thumbnail (which differ only by
 * width) becomes its own file.
 */
class StoreImages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Make referenced images local and point the pages at the local copies.');
	}

	/**
	 * @return bool Whether every referenced image was made local.
	 */
	public function execute() {
		global $wgWikvenHtmlDirectory, $wgUploadPath, $wgUploadDirectory;
		$dir = rtrim($wgWikvenHtmlDirectory, '/');
		$uploadDir = rtrim((string)$wgUploadDirectory, '/');

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();

		// Reference as it appears in the HTML => local "./img-*.ext" (or null if
		// it could not be fetched/found), so each distinct reference is handled once.
		$map = [];

		// Local upload URLs ($wgUploadPath/...) as they appear in src, srcset, and
		// the file page's full-resolution links, with an optional scheme and host.
		// Group 1 is the storage path; a trailing cache-busting ?query (added on
		// file pages) is matched so the whole URL is replaced, but excluded from
		// the path used for the disk lookup.
		$localPattern = '~(?:(?:https?:)?//[^/\s"]+)?' . preg_quote($wgUploadPath, '~') . '(/[^\s"?]+)(?:\?[^\s"]*)?~';

		foreach (glob("$dir/*.html") as $file) {
			$html = file_get_contents($file);

			// Wikimedia Commons URLs only ever appear as src/srcset values; matching
			// up to the next space or quote isolates each candidate (the srcset width
			// descriptor that follows is separated by a space) while still allowing
			// commas inside file names.
			$html = preg_replace_callback(
				'~(?:https?:)?//upload\.wikimedia\.org/[^\s"]+~',
				function ($m) use (&$map, $http, $dir) {
					$ref = $m[0];
					if (!array_key_exists($ref, $map)) {
						$map[$ref] = $this->store($http, $ref, $dir);
					}
					return $map[$ref] ?? $ref;
				},
				$html
			);

			$html = preg_replace_callback(
				$localPattern,
				function ($m) use (&$map, $uploadDir, $dir) {
					// Key by the storage path (without the cache-busting ?query) so the
					// same file referenced at different sizes or timestamps dedupes.
					$path = $m[1];
					if (!array_key_exists($path, $map)) {
						$map[$path] = $this->storeLocal($uploadDir . $path, $path, $dir);
					}
					return $map[$path] ?? $m[0];
				},
				$html
			);

			file_put_contents($file, $html, LOCK_EX);
		}

		$stored = count(array_filter($map));
		$failed = count($map) - $stored;
		$this->output("Stored $stored image(s)" . ( $failed ? ", $failed failed" : '' ) . "\n");

		if ($failed) {
			// The export still hotlinks the images that could not be fetched, so it
			// is not self-contained; fail so the build (build.php's step()) aborts
			// rather than publish output that depends on upload.wikimedia.org.
			$this->error("Wikven: $failed image(s) could not be made local; the output is not self-contained.");
			return false;
		}
		return true;
	}

	/**
	 * Download a single remote image ($ref, the reference as written in the HTML,
	 * possibly protocol-relative) into $dir and return its local reference.
	 *
	 * @return string|null Local "./img-*.ext" reference, or null on failure.
	 */
	private function store(\MediaWiki\Http\HttpRequestFactory $http, string $ref, string $dir): ?string {
		$url = str_starts_with($ref, '//') ? "https:$ref" : $ref;
		$name = 'img-' . substr(md5($ref), 0, 12) . '.' . $this->extension($url);
		$dest = "$dir/$name";

		if (file_exists($dest)) {
			return "./$name";
		}

		$options = [
			'timeout' => 30,
			'userAgent' => 'wikven static-site builder (https://github.com/chaotic-ground/wikven)'
		];
		// Less common srcset widths may not be cached and 4xx/5xx while Commons
		// generates the thumbnail on the first hit, so retry with a short backoff.
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$bytes = $http->get($url, $options, __METHOD__);
			if ($bytes !== null && $bytes !== '') {
				file_put_contents($dest, $bytes, LOCK_EX);
				return "./$name";
			}
			if ($attempt < 3) {
				sleep($attempt);
			}
		}

		$this->output("  failed: $url\n");
		return null;
	}

	/**
	 * Copy a locally uploaded file into the output directory.
	 *
	 * @param string $src Absolute path of the file in the upload directory.
	 * @param string $path The storage path (no query), used for the name and hash.
	 * @param string $dir Output directory.
	 * @return string|null Local "./img-*.ext" reference, or null if the file is missing.
	 */
	private function storeLocal(string $src, string $path, string $dir): ?string {
		if (!is_file($src)) {
			$this->output("  missing: $path\n");
			return null;
		}
		$name = 'img-' . substr(md5($path), 0, 12) . '.' . $this->extension($path);
		$dest = "$dir/$name";
		if (!file_exists($dest)) {
			copy($src, $dest);
		}
		return "./$name";
	}

	/**
	 * @return string A safe lowercase file extension, defaulting to "img".
	 */
	private function extension(string $url): string {
		$ext = strtolower((string)pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
		return preg_match('/^[a-z0-9]+$/', $ext) ? $ext : 'img';
	}
}

$maintClass = StoreImages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
