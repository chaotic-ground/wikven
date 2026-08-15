<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

use FilesystemIterator;
use MediaWiki\Extension\Wikven\SourceFile;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Locates translatable source pages and their translations by wikven's naming convention.
 *
 * A base page "<Page>.wikitext" that wraps content in <translate> is translatable; its
 * translations live at "<Page>/<lang>.wikitext" for any known language code. Which languages
 * exist is discovered from the files present, not declared. Shared by checkTranslations (CI) and
 * buildTranslations (materialize) so both agree on what is a base and what is a translation.
 */
class TranslationSource {
	/**
	 * An opening <translate> tag, attributes and all. Translate accepts <translate nowrap>, and
	 * StalenessComputer::mark() matches the same shape, so both halves of the pipeline agree on
	 * what counts as a translatable page.
	 */
	private const TRANSLATE_TAG = '#<translate(?:\s[^>]*)?>#';

	/** Whether a page's wikitext marks it as translatable (a real <translate>, not one shown as an example). */
	public static function isTranslatable(string $text): bool {
		// A <translate> inside a verbatim span -- <syntaxhighlight>, <nowiki>, <pre>, <source> -- is an
		// example, as on the page documenting page translation, not real markup. Blank those spans with
		// MediaWiki's own tag extraction (it handles attributes, self-closing tags and comments) before
		// looking for a real tag, rather than trusting a hand-rolled regex.
		$matches = [];
		$stripped = Parser::extractTagsAndParams(['syntaxhighlight', 'source', 'nowiki', 'pre'], $text, $matches);
		// Match the tag the way Translate itself does, attributes included: <translate nowrap> is a real
		// translatable page, and StalenessComputer::mark() accepts the same shape, so the two agree on
		// what marks a page for CI checks and the translated build.
		return preg_match(self::TRANSLATE_TAG, $stripped) === 1;
	}

	/**
	 * The source text of a page's title translation unit, or null when its title is not translatable.
	 *
	 * The unit's source text is the page's own title, exactly as Translate synthesizes its
	 * "Page display title" unit from the page name: nothing is added to the source wikitext, so an
	 * English page renders as it did before and a rename is what makes the translated title stale.
	 *
	 * A page that sets {{DISPLAYTITLE:}} itself is excluded. That magic word sits outside
	 * <translate>, so it is copied verbatim into every translation page and runs there too, fixing
	 * the same title in every language; asking for a translation Translate could not apply would
	 * only invite one that silently does nothing.
	 *
	 * @param string $baseFile Absolute path of the base page's source file.
	 * @param string $sourceDir Source directory the file lives under; the title is relative to it.
	 * @param string $text The base page's wikitext.
	 */
	public static function translatableTitle(string $baseFile, string $sourceDir, string $text): ?string {
		$sourceDir = rtrim($sourceDir, '/') . '/';
		if (!str_starts_with($baseFile, $sourceDir) || self::hasFixedDisplayTitle($text)) {
			return null;
		}
		return SourceFile::filenameToTitle(substr($baseFile, strlen($sourceDir)));
	}

	/** Whether a page's wikitext sets its own display title (a real magic word, not one shown as an example). */
	public static function hasFixedDisplayTitle(string $text): bool {
		// As in isTranslatable: a {{DISPLAYTITLE:}} shown inside a verbatim span -- as on the page
		// documenting page translation -- is an example, not a magic word the parser ever runs.
		$matches = [];
		$stripped = Parser::extractTagsAndParams(['syntaxhighlight', 'source', 'nowiki', 'pre'], $text, $matches);
		// DISPLAYTITLE is a localized magic word: on a de-content wiki the working spelling is
		// {{ANZEIGETITEL:}}. Checking every synonym in the content language covers that, or such a page
		// is handed a title unit its own magic word then overrides.
		foreach (self::displayTitleSynonyms() as $synonym) {
			if (preg_match('/\{\{\s*' . preg_quote($synonym, '/') . '\s*:/i', $stripped) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every spelling of the DISPLAYTITLE magic word in the content language, English always included.
	 *
	 * @return string[]
	 */
	private static function displayTitleSynonyms(): array {
		try {
			$synonyms = MediaWikiServices::getInstance()
				->getMagicWordFactory()
				->get('displaytitle')
				->getSynonyms();
		} catch (Throwable) {
			// Callers include maintenance scripts that run before the container is usable; the English
			// spelling works on the wikis wikven itself ships, so a missing registry is not fatal.
			$synonyms = [];
		}
		$synonyms[] = 'DISPLAYTITLE';
		$unique = [];
		foreach ($synonyms as $synonym) {
			if ($synonym !== '' && !in_array($synonym, $unique, true)) {
				$unique[] = $synonym;
			}
		}
		return $unique;
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
		// The directory name comes from a page title, and *, ? and [ are legal there; a glob pattern
		// built from it would expand them in every path component, so "C*-algebra" would also match the
		// translations of "Clifford-algebra" and import their units into the wrong page.
		foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'wikitext') {
				continue;
			}
			$lang = $file->getBasename('.wikitext');
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
