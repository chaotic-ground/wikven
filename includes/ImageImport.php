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
	 * the build with nothing to say about it. Matches on the extension without regard to case.
	 *
	 * Walks exactly what core's importImages walks, symlinked directories included, because the
	 * point of this list is to be the same files core is about to import. It refused to follow a
	 * linked directory once, which sounds safer and was not: core follows one (its findFiles tests
	 * is_dir, which a link to a directory satisfies), so every file under it was imported while
	 * this list -- and therefore outside(), collisions() and failed() -- could not see any of them.
	 * Where a walk stops is not where to answer a symlink; outside() is.
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
	 * A File: title is the file's name and nothing else -- core takes wfBaseName($file) and makes
	 * the title from that -- so two images with one name in two directories are one page. The build
	 * passes --skip-dupes, so the second is skipped with a line among thousands and whichever page
	 * embedded it shows the other one's picture. Reading subdirectories is what makes this
	 * reachable, so it is answered where it becomes reachable.
	 *
	 * The name a file claims is the name after core normalises it, not the name on disk: a title
	 * collapses every run of space, underscore and the other whitespace it knows to one underscore
	 * and trims those off the ends, so "Bakery oven.png" and "Bakery_oven.png" are one page and
	 * comparing the two strings would have said they were two. Case is left alone: default.yml sets
	 * CapitalLinks to false -- the entry page is the lowercase "index" -- so "diagram.png" and
	 * "Diagram.png" really are two titles here, and reporting them would fail a build that is fine.
	 * A site that turns CapitalLinks back on makes that pair one page again, and this will not say so.
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
	 * MediaWiki\Title\TitleParser does this to every title it makes: runs of space, underscore and
	 * the space-like characters listed here collapse to a single underscore, and those are trimmed
	 * off both ends. Kept to that one rule -- the parser also normalises Unicode and rejects titles
	 * outright, neither of which changes whether two files answer to one name often enough to carry
	 * a copy of core's parser in here.
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
	 * is_file() follows a link and so does the walk, so a source tree can offer one named
	 * picture.png, or a whole linked directory of them, and have the build upload whatever is on the
	 * other side -- files outside the tree, on the machine doing the building, published with the
	 * site. A tree of wiki content has no use for one, so rather than resolve them they are refused,
	 * by name, where the tree is read.
	 *
	 * Asked of the resolved path rather than of each entry, because the file at the end is what gets
	 * uploaded: a link to a file and a real file under a linked directory both leave the tree, and
	 * only one of the two is itself a link. A name that resolves to nothing -- a link with nothing on
	 * the other side -- is refused here too, since containment cannot be shown for it.
	 *
	 * This is where wikven answers symlinks at all. ContainedPath, which bounds the paths that come
	 * back out of rendered pages, does not: the directories it bounds hold nothing an author put
	 * there, and a check in it would silently refuse the symlinked skins/ of a MediaWiki checkout
	 * somebody develops against.
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
