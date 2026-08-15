<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Localize Commons hotlinks and File: uploads next to the HTML for a self-contained export. */
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

		// HTML reference => local "./img-*.ext" (or null on failure), deduping each reference.
		$map = [];

		// Match $wgUploadPath URLs; group 1 is the storage path, trailing ?query stripped from it.
		$localPattern = '~(?:(?:https?:)?//[^/\s"]+)?' . preg_quote($wgUploadPath, '~') . '(/[^\s"?]+)(?:\?[^\s"]*)?~';

		foreach (glob("$dir/*.html") as $file) {
			$html = file_get_contents($file);

			// Match each Commons src/srcset candidate up to the next space or quote.
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
					// Key by storage path (no ?query) so sizes/timestamps of one file dedupe.
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
			// Fail so the build aborts rather than publish output that still hotlinks images.
			$this->error("Wikven: $failed image(s) could not be made local; the output is not self-contained.");
			return false;
		}
		return true;
	}

	/**
	 * Download remote image $ref into $dir and return its local reference.
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
		$tmp = "$dest.tmp";
		// Retry with backoff: Commons may 5xx while generating an uncached thumbnail. A 4xx (e.g. a
		// dead File: link) won't change on retry, so stop as soon as one comes back.
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$req = $http->create($url, $options, __METHOD__);
			$fh = fopen($tmp, 'wb');
			if ($fh === false) {
				break;
			}
			$req->setCallback(static function ($resource, $buffer) use ($fh) {
				return fwrite($fh, $buffer);
			});
			$status = $req->execute();
			fclose($fh);

			$httpStatus = $req->getStatus();
			if ($status->isOK() && $httpStatus >= 200 && $httpStatus < 300 && filesize($tmp) > 0) {
				rename($tmp, $dest);
				return "./$name";
			}

			unlink($tmp);
			if ($httpStatus >= 400 && $httpStatus < 500) {
				break;
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
			$this->copyWithoutTimestamps($src, $dest);
		}
		return "./$name";
	}

	/** Copy a file, dropping the PNG chunks that record when it was written. */
	private function copyWithoutTimestamps(string $src, string $dest): void {
		$data = file_get_contents($src);
		$stripped = $data === false ? null : Png::withoutTimestamps($data);
		if ($stripped === null) {
			copy($src, $dest);
			return;
		}
		file_put_contents($dest, $stripped, LOCK_EX);
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
