<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Download the Wikimedia Commons image files that the generated pages hotlink
 * (via InstantCommons) and rewrite their <img src>/srcset references to the
 * local copies, so the static export is self-contained and never depends on
 * upload.wikimedia.org being reachable.
 *
 * Files are dumped flat next to the HTML using the same img-<hash>.<ext> scheme
 * as AssetLocalizer. The hash is taken over the full remote URL, so each srcset
 * candidate (which differs only by thumbnail width) becomes its own local file.
 */
class StoreImages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Download hotlinked Commons images and point the pages at local copies.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory;
		$dir = rtrim($wgWikvenHtmlDirectory, '/');

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();

		// Reference as it appears in the HTML => local "./img-*.ext" (or null if
		// the download failed), so each distinct URL is fetched at most once.
		$map = [];
		foreach (glob("$dir/*.html") as $file) {
			$html = file_get_contents($file);
			// Commons URLs only ever appear as src/srcset values; matching up to the
			// next space or quote isolates each candidate (the srcset width
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
			file_put_contents($file, $html, LOCK_EX);
		}

		$stored = count(array_filter($map));
		$failed = count($map) - $stored;
		$this->output("Stored $stored image(s)" . ( $failed ? ", $failed failed" : '' ) . "\n");
	}

	/**
	 * Download a single image and return its local reference.
	 *
	 * @param \MediaWiki\Http\HttpRequestFactory $http
	 * @param string $ref The reference as written in the HTML (may be protocol-relative).
	 * @param string $dir Output directory.
	 * @return string|null Local "./img-*.ext" reference, or null on failure.
	 */
	private function store($http, $ref, $dir) {
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
	 * @param string $url
	 * @return string A safe lowercase file extension, defaulting to "img".
	 */
	private function extension($url) {
		$ext = strtolower((string)pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
		return preg_match('/^[a-z0-9]+$/', $ext) ? $ext : 'img';
	}
}

$maintClass = StoreImages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
