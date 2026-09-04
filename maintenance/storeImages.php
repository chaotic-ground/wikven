<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Localize Commons hotlinks and File: uploads into the asset directory for a self-contained export. */
class StoreImages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Make referenced images local and point the pages at the local copies.');
	}

	/**
	 * @return bool Whether every referenced image was made local.
	 */
	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenAssetDirectory, $wgUploadPath, $wgUploadDirectory;
		$htmlDir = rtrim($wgWikvenHtmlDirectory, '/');
		$assetDirectory = (string)$wgWikvenAssetDirectory;
		$uploadDir = rtrim((string)$wgUploadDirectory, '/');

		// The pictures a page carries are as much the build's own output as the stylesheets are --
		// nobody typed "img-1a2b3c4d5e6f.png" -- so they go where the rest of it goes. Made rather
		// than assumed: BuildScripts happens to have made it already, and a step that depends on a
		// sibling having run is the kind of seam this build keeps getting caught by.
		$assetPath = AssetFile::path($htmlDir, $assetDirectory);
		if (!wfMkdirParents($assetPath, null, __METHOD__)) {
			$this->error("Wikven: could not create the asset directory $assetPath");
			return false;
		}

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();

		// HTML reference => local "./img-*.ext" (or null on failure), deduping each reference.
		$map = [];

		// Match $wgUploadPath URLs; group 1 is the storage path, trailing ?query stripped from it.
		$localPattern = '~(?:(?:https?:)?//[^/\s"]+)?' . preg_quote($wgUploadPath, '~') . '(/[^\s"?]+)(?:\?[^\s"]*)?~';

		foreach (glob("$htmlDir/*.html") as $file) {
			$html = file_get_contents($file);

			// Match each Commons src/srcset candidate up to the next space or quote.
			$html = preg_replace_callback(
				'~(?:https?:)?//upload\.wikimedia\.org/[^\s"]+~',
				function ($m) use (&$map, $http, $htmlDir, $assetDirectory) {
					$ref = $m[0];
					if (!array_key_exists($ref, $map)) {
						$map[$ref] = $this->store($http, $ref, $htmlDir, $assetDirectory);
					}
					return $map[$ref] ?? $ref;
				},
				$html
			);

			$html = preg_replace_callback(
				$localPattern,
				function ($m) use (&$map, $uploadDir, $htmlDir, $assetDirectory) {
					// Key by storage path (no ?query) so sizes/timestamps of one file dedupe.
					$path = $m[1];
					if (!array_key_exists($path, $map)) {
						// The path came out of a page's rendered HTML, so it is whatever someone
						// wrote there rather than something MediaWiki stored. Bound it to the
						// upload directory before reading a file at it.
						$src = ContainedPath::under($uploadDir, $path);
						if ($src === null) {
							$this->output("  refusing: $path (reaches outside the upload directory)\n");
						}
						$map[$path] = $src === null
							? null
							: $this->storeLocal($src, $path, $htmlDir, $assetDirectory);
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
	 * Download remote image $ref into the asset directory and return the reference pages link it by.
	 *
	 * @return string|null Local reference (e.g. "./assets/img-*.ext"), or null on failure.
	 */
	private function store(
		\MediaWiki\Http\HttpRequestFactory $http,
		string $ref,
		string $htmlDir,
		string $assetDirectory
	): ?string {
		$url = str_starts_with($ref, '//') ? "https:$ref" : $ref;
		$name = AssetFile::imageName($ref, $url);
		// The file and the link to it are worked out together; a page linking one directory while
		// the file was written to another has no picture and nothing anywhere to say so.
		$located = AssetFile::locate($htmlDir, $assetDirectory, $name);
		$dest = $located['path'];

		if (file_exists($dest)) {
			return $located['href'];
		}

		$options = ['timeout' => 30, 'userAgent' => UserAgent::string()];
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
				return $located['href'];
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
	 * @param string $htmlDir Output directory.
	 * @param string $assetDirectory The directory under it the build writes what it generates into.
	 * @return string|null Local reference (e.g. "./assets/img-*.ext"), or null if the file is missing.
	 */
	private function storeLocal(string $src, string $path, string $htmlDir, string $assetDirectory): ?string {
		if (!is_file($src)) {
			$this->output("  missing: $path\n");
			return null;
		}
		$name = AssetFile::imageName($path);
		$located = AssetFile::locate($htmlDir, $assetDirectory, $name);
		if (!file_exists($located['path'])) {
			$this->copyWithoutTimestamps($src, $located['path']);
		}
		return $located['href'];
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
}

$maintClass = StoreImages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
