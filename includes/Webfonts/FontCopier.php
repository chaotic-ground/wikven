<?php

namespace MediaWiki\Extension\Wikven\Webfonts;

/**
 * Copy the woff2 files a baked stylesheet names out of UniversalLanguageSelector's font repository.
 *
 * An @font-face whose url() answers 404 leaves the reader with exactly the tofu the bundled font
 * was there to spare them, so this reports every file that did not arrive.
 */
class FontCopier {
	/**
	 * Copy each base-relative woff2 path into the output tree.
	 *
	 * @param string $srcBase ULS's font directory, the one its repository paths are relative to.
	 * @param string $destBase Output directory the woff2 tree is copied under.
	 * @param string[] $files Base-relative woff2 paths (e.g. "AbyssinicaSIL/AbyssinicaSIL-R.woff2").
	 * @return string[] The paths that did not arrive -- missing from the repository, or the
	 *   directory or the copy failed -- in the order they were asked for. Empty when all of them did.
	 */
	public static function copy(string $srcBase, string $destBase, array $files): array {
		$missing = [];
		foreach ($files as $relative) {
			$src = "$srcBase/$relative";
			if (!is_file($src)) {
				$missing[] = $relative;
				continue;
			}
			$dest = "$destBase/$relative";
			$dir = dirname($dest);
			if (!is_dir($dir) && !wfMkdirParents($dir)) {
				$missing[] = $relative;
				continue;
			}
			if (!copy($src, $dest)) {
				$missing[] = $relative;
			}
		}
		return $missing;
	}
}
