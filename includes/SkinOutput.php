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
 * Which directory belongs to which is decided by name, and a page can take a skin's name. Nothing
 * capitalizes it out of the way: wikven's own defaults set CapitalLinks off, so a title keeps the
 * case its file was written in, and a subpage of "citizen" makes the same dist/citizen/ the Citizen
 * pass renders into. The exclusion below is by name too, so on such a site the main pass skips that
 * page along with the skin's copies -- resolveTranslationLinks, the only caller, leaves its
 * Special:MyLanguage links unresolved.
 *
 * That is not a case to fix here. Once the two are in one directory nothing tells them apart: a
 * name is all either has, and reading the page would mean writing into the directory the Citizen
 * pass is writing to, which is the race this exists to prevent. Choosing the skin's copies is the
 * safe half of an unresolvable collision. What fixes it is not naming a page after a skin, which
 * Special:MyLanguage/Pages#reserved-names asks of a site.
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
