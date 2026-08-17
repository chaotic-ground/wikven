<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The pages one skin pass owns, for the build steps that walk the output tree.
 *
 * A skin pass must never read or write a path outside its own output. The trap is that the main
 * skin's output directory is dist/ itself, which is the parent of every other skin's
 * ("dist/citizen/", "dist/minerva/"; see WikvenSettings.php), so a pass that walks its own
 * directory recursively reaches the other skins' pages. Nothing is broken today only because of
 * ordering: the main skin is always the first pass, so the other directories do not exist yet
 * while it walks. That is not something a caller can be asked to preserve -- WIKVEN_BUILD_SKIN
 * re-runs a single pass over a dist/ that already holds the others, and the passes are meant to
 * become concurrent -- so the walk prunes them instead.
 *
 * history/ is pruned for the opposite reason: it is the pass's own, but it holds no page of the
 * site. RebuildFileCache writes a history page per page and Build::renderSkin() deletes the whole
 * tree a few steps later, so reading it is work thrown away.
 */
class OutputTree {
	/** Trees a pass writes into its own output directory that hold no page of the site. */
	private const NON_PAGE_DIRECTORIES = ['history'];

	/**
	 * Every exported page the pass rendering into $htmlDir owns.
	 *
	 * @param string $htmlDir The pass's own output directory ($wgWikvenHtmlDirectory).
	 * @param string[] $skinDirectories Every skin pass's output directory, this one included
	 *   ($wgWikvenSkinDirectories).
	 * @return list<string> Absolute paths of .html files, in a fixed order.
	 */
	public static function pages(string $htmlDir, array $skinDirectories = []): array {
		$htmlDir = rtrim($htmlDir, '/');
		if ($htmlDir === '' || !is_dir($htmlDir)) {
			return [];
		}
		$pruned = self::prunedDirectories($htmlDir, $skinDirectories);

		$directories = new RecursiveDirectoryIterator($htmlDir, FilesystemIterator::SKIP_DOTS);
		$own = new RecursiveCallbackFilterIterator(
			$directories,
			static function (SplFileInfo $file) use ($pruned): bool {
				// Files are all kept here and filtered below; only descent is being decided.
				return !$file->isDir() || !in_array(rtrim($file->getPathname(), '/'), $pruned, true);
			}
		);

		$pages = [];
		foreach (new RecursiveIteratorIterator($own) as $file) {
			$path = $file->getPathname();
			if ($file->isFile() && str_ends_with($path, '.html')) {
				$pages[] = $path;
			}
		}
		sort($pages);
		return $pages;
	}

	/**
	 * The directories a walk of $htmlDir must not descend into: every other skin's output that
	 * lies under it, and the pass's own non-page trees.
	 *
	 * A skin directory that is not under $htmlDir is left out, since the walk cannot reach it
	 * anyway; that is every other skin's, seen from a non-main pass.
	 *
	 * @param string $htmlDir The pass's own output directory, without a trailing slash.
	 * @param string[] $skinDirectories Every skin pass's output directory, this one included.
	 * @return list<string>
	 */
	public static function prunedDirectories(string $htmlDir, array $skinDirectories): array {
		$htmlDir = rtrim($htmlDir, '/');
		$pruned = [];
		foreach ($skinDirectories as $directory) {
			$directory = rtrim((string)$directory, '/');
			if ($directory === '' || $directory === $htmlDir) {
				continue;
			}
			if (str_starts_with($directory, "$htmlDir/")) {
				$pruned[] = $directory;
			}
		}
		foreach (self::NON_PAGE_DIRECTORIES as $name) {
			$pruned[] = "$htmlDir/$name";
		}
		return array_values(array_unique($pruned));
	}
}
