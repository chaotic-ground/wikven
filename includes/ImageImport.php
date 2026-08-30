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
	 * Subdirectories included, because pages are read from them too: a source tree where
	 * "Guide/Setup.wikitext" is the page "Guide/Setup" is one where "Guide/diagram.png" is the
	 * image that page embeds, and reading one and not the other left the page with a red link and
	 * the build with nothing to say about it. Matches on the extension without regard to case, and
	 * follows the directory order the importer's own walk follows.
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
			if (is_dir($path) && !is_link($path)) {
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
	 * A File: title is the file's name and nothing else -- core takes wfBaseName($file) and makes
	 * the title from that -- so two images with one name in two directories are one page. The build
	 * passes --skip-dupes, so the second is skipped with a line among thousands and whichever page
	 * embedded it shows the other one's picture. Reading subdirectories is what makes this
	 * reachable, so it is answered where it becomes reachable.
	 *
	 * Names are compared as written. default.yml sets CapitalLinks to false -- the entry page is the
	 * lowercase "index" -- so "diagram.png" and "Diagram.png" are two titles here rather than one,
	 * and reporting them as a collision would fail a build that is fine. A site that turns
	 * CapitalLinks back on makes that pair one page again, and this will not say so.
	 *
	 * @param string[] $sources Absolute paths, as sources() returns them.
	 * @return array<string, string[]> Shared name => the paths that claim it, two or more of them.
	 */
	public static function collisions(array $sources): array {
		$byName = [];
		foreach ($sources as $path) {
			$byName[basename($path)][] = $path;
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
	 * Files among $sources that are symlinks, which is not a thing to import.
	 *
	 * is_file() follows a link, so a source tree can offer one named picture.png and have the build
	 * upload whatever it points at -- a file outside the tree, on the machine doing the building,
	 * published with the site. A tree of wiki content has no use for one, so rather than resolve
	 * them they are refused, by name, where the tree is read.
	 *
	 * This is where wikven answers symlinks at all. ContainedPath, which bounds the paths that come
	 * back out of rendered pages, does not: the directories it bounds hold nothing an author put
	 * there, and a check in it would silently refuse the symlinked skins/ of a MediaWiki checkout
	 * somebody develops against.
	 *
	 * @param string[] $sources Absolute paths, as sources() returns them.
	 * @return string[] Those of them that are links.
	 */
	public static function links(array $sources): array {
		$links = [];
		foreach ($sources as $path) {
			if (is_link($path)) {
				$links[] = $path;
			}
		}
		return $links;
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
