<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What the build hands core's image importer, and what its answer means.
 *
 * importImages.php reports "no suitable files could be found for import" with the same false it
 * reports a failed import with, so its answer on its own cannot tell an image the wiki rejected
 * from a site that simply ships none. The files the build found before running it settle which of
 * the two happened.
 */
class ImageImport {
	/**
	 * The files core's importer will consider, found the way importImages.php finds them.
	 *
	 * It reads the top level of the directory only, since the build does not ask it to search
	 * recursively, and matches on the extension without regard to case.
	 *
	 * @param string $directory Source directory, without a trailing slash.
	 * @param string[] $extensions Allowed extensions, as $wgFileExtensions holds them.
	 * @return string[] Absolute paths, in the order the directory lists them.
	 */
	public static function sources(string $directory, array $extensions): array {
		if (!is_dir($directory)) {
			return [];
		}
		$allowed = array_map('strtolower', $extensions);
		$sources = [];
		foreach (scandir($directory) ?: [] as $entry) {
			$path = $directory . '/' . $entry;
			if (!is_file($path)) {
				continue;
			}
			if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowed, true)) {
				$sources[] = $path;
			}
		}
		return $sources;
	}

	/**
	 * Whether the importer's return value means an image did not import.
	 *
	 * @param mixed $result What ImportImages::execute() returned; false is its failure.
	 * @param string[] $sources The files it was given, which a false about an empty source has none of.
	 */
	public static function failed(mixed $result, array $sources): bool {
		return $result === false && $sources !== [];
	}
}
