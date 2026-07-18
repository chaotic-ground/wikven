<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Locates translatable source pages and their translations by wikven's naming convention.
 *
 * A base page "<Page>.wikitext" that wraps content in <translate> is translatable; its
 * translations live at "<Page>/<lang>.wikitext" for any known language code. Which languages
 * exist is discovered from the files present, not declared. Shared by checkTranslations (CI) and
 * buildTranslations (materialize) so both agree on what is a base and what is a translation.
 */
class TranslationSource {
	/** Whether a page's wikitext marks it as translatable. */
	public static function isTranslatable(string $text): bool {
		return str_contains($text, '<translate>');
	}

	/** The translation file for a base file in the given language ("Foo.wikitext" -> "Foo/ko.wikitext"). */
	public static function translationPath(string $baseFile, string $lang): string {
		return preg_replace('/\.wikitext$/', '/' . $lang . '.wikitext', $baseFile);
	}

	/**
	 * Whether an absolute path is a translation file.
	 *
	 * True when it is named "<lang>.wikitext" for a known language and its sibling base page
	 * "<dir>.wikitext" is translatable. Used to keep translation files out of the plain import.
	 *
	 * @param string $absolutePath
	 * @param callable(string):bool $isKnownLanguage
	 */
	public static function isTranslationFile(string $absolutePath, callable $isKnownLanguage): bool {
		if (!preg_match('#/([^/]+)\.wikitext$#', $absolutePath, $matches) || !$isKnownLanguage($matches[1])) {
			return false;
		}
		$base = preg_replace('#/[^/]+\.wikitext$#', '.wikitext', $absolutePath);
		return $base !== $absolutePath && is_file($base) && self::isTranslatable((string)file_get_contents($base));
	}

	/**
	 * The languages a base page is translated into: sibling "<Page>/<lang>.wikitext" files whose
	 * segment is a known language code.
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return string[] Language codes, sorted.
	 */
	public static function translationLanguages(string $baseFile, callable $isKnownLanguage): array {
		$directory = preg_replace('/\.wikitext$/', '', $baseFile);
		if (!is_dir($directory)) {
			return [];
		}
		$languages = [];
		foreach (glob("$directory/*.wikitext") ?: [] as $file) {
			$lang = basename($file, '.wikitext');
			if ($isKnownLanguage($lang)) {
				$languages[] = $lang;
			}
		}
		sort($languages);
		return $languages;
	}

	/**
	 * Every translatable base page under a source directory.
	 *
	 * @return string[] Absolute paths, sorted for a stable order.
	 */
	public static function baseFiles(string $sourceDir): array {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if (
				$file->isFile()
				&& str_ends_with($path, '.wikitext')
				&& self::isTranslatable((string)file_get_contents($path))
			) {
				$files[] = $path;
			}
		}
		sort($files);
		return $files;
	}
}
