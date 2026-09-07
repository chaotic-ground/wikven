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
	 * Subdirectories included, because pages are read from them too, and matched on the extension
	 * without regard to case. Symlinked directories are followed because core's findFiles tests
	 * is_dir, which a link satisfies: refusing to follow one left every file under it imported and
	 * invisible to outside(), collisions() and failed().
	 *
	 * @param string $directory Source directory, without a trailing slash.
	 * @param string[] $extensions Allowed extensions, as $wgFileExtensions holds them.
	 * @return string[] Absolute paths.
	 */
	public static function sources(string $directory, array $extensions): array {
		if (!is_dir($directory)) {
			return [];
		}
		$allowed = array_map('strtolower', $extensions);
		$sources = [];
		foreach (scandir($directory) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $directory . '/' . $entry;
			if (is_dir($path)) {
				$sources = array_merge($sources, self::sources($path, $extensions));
				continue;
			}
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
	 * Files that would import as the same page, keyed by the name they would share.
	 *
	 * A File: title is wfBaseName($file) and nothing else, so two images with one name in two
	 * directories are one page: the importer takes the first and skips the second. The name
	 * compared is the one core normalises to; case is left alone, CapitalLinks being off.
	 *
	 * @param string[] $sources Absolute paths, as sources() returns them.
	 * @return array<string, string[]> Shared name => the paths that claim it, two or more of them.
	 */
	public static function collisions(array $sources): array {
		$byName = [];
		foreach ($sources as $path) {
			$byName[self::title(basename($path))][] = $path;
		}
		$shared = [];
		foreach ($byName as $name => $paths) {
			if (count($paths) > 1) {
				$shared[$name] = $paths;
			}
		}
		return $shared;
	}

	/**
	 * The File: page a file of this name imports as, as far as two of them being one page goes.
	 *
	 * TitleParser does this to every title: runs of space, underscore and the space-like characters
	 * listed here collapse to one underscore and are trimmed off both ends. Kept to that one rule.
	 *
	 * @param string $name A file's base name.
	 * @return string The name two files have to share to be one page.
	 */
	private static function title(string $name): string {
		$collapsed = preg_replace(
			'/[ _\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u',
			'_',
			$name
		);
		// preg_replace answers invalid UTF-8 with null; such a name is no title, and is its own name.
		return $collapsed === null ? $name : trim($collapsed, '_');
	}

	/**
	 * Files among $sources that are not really in the source tree, which is not a thing to import.
	 *
	 * is_file() follows a link and so does the walk, so a source tree could have the build upload
	 * whatever is on the other side. Asked of the resolved path, the file at the end being what
	 * gets uploaded.
	 *
	 * @param string $directory Source directory, as sources() was given it.
	 * @param string[] $sources Absolute paths, as sources() returns them.
	 * @return string[] Those of them that are not files inside $directory.
	 */
	public static function outside(string $directory, array $sources): array {
		$root = realpath($directory);
		if ($root === false) {
			return $sources;
		}
		$root = rtrim($root, '/') . '/';
		$outside = [];
		foreach ($sources as $path) {
			$real = realpath($path);
			if ($real === false || !str_starts_with($real, $root)) {
				$outside[] = $path;
			}
		}
		return $outside;
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
