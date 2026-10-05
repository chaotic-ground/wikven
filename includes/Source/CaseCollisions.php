<?php

namespace MediaWiki\Extension\Wikven\Source;

use MediaWiki\Extension\Wikven\ImageImport;

/**
 * Source files that a disk ignoring case would hold as one (#820).
 *
 * CapitalLinks is off, so "Foo" and "foo" are two pages. macOS and Windows keep one file for
 * both, in a checkout and in the output alike, and whichever is written last wins.
 */
class CaseCollisions {
	/**
	 * Each set of source files that would be one file, or build to one, on such a disk.
	 *
	 * Images count twice: as files, and by their File: page, so Guide/diagram.png and Diagram.png
	 * are two pages and one file.
	 *
	 * @param string[] $paths Every file under the source directory, relative to it.
	 * @param string[] $images Those of $paths that are images.
	 * @return string[][] Sorted relative paths, two or more to a set.
	 */
	public static function find(array $paths, array $images): array {
		$byPath = [];
		foreach ($paths as $path) {
			$byPath[self::fold($path)][$path] = $path;
		}
		$byPage = [];
		foreach ($images as $path) {
			$name = ImageImport::title(basename($path));
			$byPage[self::fold($name)][$name][] = $path;
		}

		$found = [];
		foreach ($byPath as $spellings) {
			if (count($spellings) > 1) {
				$found[] = array_values($spellings);
			}
		}
		foreach ($byPage as $spellings) {
			// Two images spelled alike are ImageImport::collisions()'s to refuse.
			if (count($spellings) > 1) {
				$found[] = array_merge(...array_values($spellings));
			}
		}

		// Foo.png and foo.png side by side are both kinds at once, and one report is enough.
		$unique = [];
		foreach ($found as $set) {
			sort($set);
			$unique[implode("\0", $set)] = $set;
		}
		ksort($unique);
		return array_values($unique);
	}

	/** What the name is to a disk that ignores case: Unicode simple case folding, as NTFS and APFS do. */
	private static function fold(string $name): string {
		return mb_convert_case($name, MB_CASE_FOLD_SIMPLE, 'UTF-8');
	}
}
