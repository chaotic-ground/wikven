<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The pages one skin pass owns, out of everything under its output directory.
 *
 * A pass renders into $wgWikvenHtmlDirectory, and for the main skin that directory is dist/
 * itself: the parent of every other skin's output (dist/citizen/, dist/minerva/) and of the
 * history/ tree renderSkin() deletes. So "everything under my output directory" is not the same
 * set as "my pages", and a pass must never touch a path outside its own: another skin's page
 * resolves its links against the wrong root when read from here, and writing one races the pass
 * that owns it once the passes run together (#407, #409).
 *
 * A skin's directory is named after the skin, so it cannot be mistaken for a page's: the cache
 * writes a page under its title, and MediaWiki capitalizes a title's first letter, while the
 * canonical skin names these directories take are lowercase.
 */
class SkinOutput {
	/**
	 * Every .html file the pass rendering $skin owns, below $htmlDir.
	 *
	 * @param string $htmlDir The pass's output directory ($wgWikvenHtmlDirectory).
	 * @param string[] $skins Every skin the site renders ($wgWikvenSkins).
	 * @param string $mainSkin The skin rendered into the output root ($wgWikvenMainSkin).
	 * @param string $skin The skin this pass renders ($wgDefaultSkin).
	 * @return iterable<string> Absolute paths.
	 */
	public static function pages(string $htmlDir, array $skins, string $mainSkin, string $skin): iterable {
		$htmlDir = rtrim($htmlDir, '/');
		$foreign = array_fill_keys(self::foreignDirectories($htmlDir, $skins, $mainSkin, $skin), true);

		$entries = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator($htmlDir, FilesystemIterator::SKIP_DOTS),
				static function (SplFileInfo $current) use ($foreign): bool {
					return !isset($foreign[$current->getPathname()]);
				}
			)
		);
		foreach ($entries as $entry) {
			$path = $entry->getPathname();
			if ($entry->isFile() && str_ends_with($path, '.html')) {
				yield $path;
			}
		}
	}

	/**
	 * The directories directly under $htmlDir that hold something other than this pass's pages.
	 *
	 * @param string $htmlDir The pass's output directory ($wgWikvenHtmlDirectory).
	 * @param string[] $skins Every skin the site renders ($wgWikvenSkins).
	 * @param string $mainSkin The skin rendered into the output root ($wgWikvenMainSkin).
	 * @param string $skin The skin this pass renders ($wgDefaultSkin).
	 * @return string[] Absolute paths.
	 */
	public static function foreignDirectories(string $htmlDir, array $skins, string $mainSkin, string $skin): array {
		$htmlDir = rtrim($htmlDir, '/');
		// Every pass writes a per-page history/ tree that renderSkin() deletes on its way out, so
		// reading it here is work no one ever looks at (#408).
		$directories = ["$htmlDir/history"];
		// Only the main skin renders into the output root; every other pass has a directory to
		// itself, with nobody else's pages below it.
		if ($skin !== $mainSkin) {
			return $directories;
		}
		foreach ($skins as $other) {
			if ($other !== $mainSkin) {
				$directories[] = "$htmlDir/$other";
			}
		}
		return $directories;
	}
}
