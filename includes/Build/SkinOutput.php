<?php

namespace MediaWiki\Extension\Wikven\Build;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The pages one skin pass owns, out of everything under its output directory.
 *
 * For the main skin that directory is dist/ itself, the parent of every other skin's output, so
 * writing everything under it would race the pass that owns a page (#407, #409).
 *
 * With CapitalLinks off a page can take a skin's name, and the main pass skips it.
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
