<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

use FilesystemIterator;
use MediaWiki\Extension\Wikven\Source\SourceFile;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Locates translatable source pages and their translations by wikven's naming convention.
 *
 * A base page "<Page>.wikitext" that wraps content in <translate> is translatable; its
 * translations live at "<Page>/<lang>.wikitext" and carry that page's <!--T:n--> unit markers.
 * Which languages exist is discovered from the files present, not declared.
 */
class TranslationSource {
	/** A translate tag of either half, as Translate's own containsMarkup() looks for one. */
	private const TRANSLATE_TAG = '#</?translate[ >]#';

	/** What TranslatablePageParser::armourNowiki() hides: exactly this, no attributes, no other tag. */
	private const ARMOURED_NOWIKI = '#<nowiki>.*?</nowiki>#s';

	/**
	 * Tags whose contents MediaWiki hands over unparsed, so a magic word inside one never runs.
	 * <pre> and <nowiki> always; <syntaxhighlight> and <source> only where that extension is
	 * loaded, which is the case wikven's own docs run under.
	 */
	private const UNEXPANDED_TAGS = ['syntaxhighlight', 'source', 'nowiki', 'pre'];

	/** Whether a page's wikitext marks it as translatable (a real <translate>, not one shown as an example). */
	public static function isTranslatable(string $text): bool {
		// Translate's parser hook runs on raw wikitext, before any extension tag is stripped, so only
		// <nowiki> hides a tag from it. A placeholder rather than an empty string: removing the span
		// could splice "</" onto "translate>".
		return preg_match(self::TRANSLATE_TAG, preg_replace(self::ARMOURED_NOWIKI, "\x7f", $text)) === 1;
	}

	/**
	 * The source text of a page's title translation unit, or null when its title is not translatable.
	 *
	 * A page setting {{DISPLAYTITLE:}} itself is excluded: that word sits outside <translate> and
	 * fixes one title for every language.
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
		// Unlike isTranslatable, this asks what MediaWiki expands rather than what Translate parses.
		$matches = [];
		$stripped = Parser::extractTagsAndParams(self::UNEXPANDED_TAGS, $text, $matches);
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
	 * Named "<lang>.wikitext" for a known language, sibling to a translatable base page, and
	 * carrying unit markers -- which settle it, "API/id" having been read as Indonesian.
	 *
	 * @param string $absolutePath
	 * @param callable(string):bool $isKnownLanguage
	 */
	public static function isTranslationFile(string $absolutePath, callable $isKnownLanguage): bool {
		if (!preg_match('#/([^/]+)\.wikitext$#', $absolutePath, $matches) || !$isKnownLanguage($matches[1])) {
			return false;
		}
		$base = preg_replace('#/[^/]+\.wikitext$#', '.wikitext', $absolutePath);
		return (
			$base !== $absolutePath
			&& is_file($base)
			&& self::isTranslatable((string)file_get_contents($base))
			&& StalenessComputer::hasUnitMarkers((string)file_get_contents($absolutePath))
		);
	}

	/**
	 * The languages a base page is translated into: sibling "<Page>/<lang>.wikitext" files whose
	 * segment is a known language code and which carry <!--T:n--> unit markers.
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return string[] Language codes, sorted.
	 */
	public static function translationLanguages(string $baseFile, callable $isKnownLanguage): array {
		$languages = [];
		foreach (self::filesNamedForALanguage($baseFile, $isKnownLanguage) as $lang => $path) {
			if (StalenessComputer::hasUnitMarkers((string)file_get_contents($path))) {
				$languages[] = $lang;
			}
		}
		sort($languages);
		return $languages;
	}

	/**
	 * Subpages that a language code names but that are read as pages of their own, keyed by code.
	 *
	 * Named for a language, under a translatable page, and carrying no unit marker.
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return array<string,string> Absolute paths, keyed by the language code that names them.
	 */
	public static function pagesNamedForALanguage(string $baseFile, callable $isKnownLanguage): array {
		$pages = [];
		foreach (self::filesNamedForALanguage($baseFile, $isKnownLanguage) as $lang => $path) {
			if (!StalenessComputer::hasUnitMarkers((string)file_get_contents($path))) {
				$pages[$lang] = $path;
			}
		}
		ksort($pages);
		return $pages;
	}

	/**
	 * Every sibling "<Page>/<lang>.wikitext" whose name is a known language code, marked or not.
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return array<string,string> Absolute paths, keyed by language code.
	 */
	private static function filesNamedForALanguage(string $baseFile, callable $isKnownLanguage): array {
		$directory = preg_replace('/\.wikitext$/', '', $baseFile);
		if (!is_dir($directory)) {
			return [];
		}
		$found = [];
		// The directory name comes from a page title, and *, ? and [ are legal there; a glob built from
		// it would expand them, so "C*-algebra" would also match "Clifford-algebra".
		foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'wikitext') {
				continue;
			}
			$lang = $file->getBasename('.wikitext');
			if ($isKnownLanguage($lang)) {
				$found[$lang] = $file->getPathname();
			}
		}
		return $found;
	}

	/**
	 * The translation files a base page has, keyed by the language each is written in.
	 *
	 * By the paths they were found at rather than rebuilt from the page title, which has been
	 * through normalization ("Getting_Started.wikitext" imports as "Getting Started").
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return array<string,string> Absolute paths, keyed by language code, sorted by code.
	 */
	public static function translationFiles(string $baseFile, callable $isKnownLanguage): array {
		$files = [];
		foreach (self::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
			$files[$lang] = self::translationPath($baseFile, $lang);
		}
		return $files;
	}

	/**
	 * Every language the source tree carries a translation in.
	 *
	 * Read from the files because there is no setting to read: a second list would be one to fall
	 * out of step.
	 *
	 * @param string $sourceDir
	 * @param callable(string):bool $isKnownLanguage
	 * @return string[] Language codes, sorted, each once.
	 */
	public static function languages(string $sourceDir, callable $isKnownLanguage): array {
		$languages = [];
		foreach (self::baseFiles($sourceDir, $isKnownLanguage) as $baseFile) {
			foreach (self::translationLanguages($baseFile, $isKnownLanguage) as $language) {
				$languages[$language] = true;
			}
		}
		ksort($languages);
		return array_keys($languages);
	}

	/**
	 * Every translatable base page under a source directory.
	 *
	 * A translation is never one, whatever it holds: a <translate> it quotes is real to Translate
	 * too, and the unit markers are what say it is not (see isTranslationFile).
	 *
	 * @param string $sourceDir
	 * @param callable(string):bool $isKnownLanguage
	 * @return string[] Absolute paths, sorted for a stable order.
	 */
	public static function baseFiles(string $sourceDir, callable $isKnownLanguage): array {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if (
				$file->isFile()
				&& str_ends_with($path, '.wikitext')
				&& !self::isTranslationFile($path, $isKnownLanguage)
				&& self::isTranslatable((string)file_get_contents($path))
			) {
				$files[] = $path;
			}
		}
		sort($files);
		return $files;
	}
}
