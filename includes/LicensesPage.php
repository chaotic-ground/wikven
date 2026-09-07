<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Title\Title;

/**
 * Where the page saying what a site redistributes lives, and what its per-language copies are
 * called.
 *
 * One place, because two things have to agree on it in different processes: build.php writes
 * "<Page>/<lang>", and the Declarer hook has to recognise the same titles. The drift would be
 * silent -- the page still renders, in the wrong language.
 */
class LicensesPage {
	/** The page the site asked for, or null where it asked for none (the name set empty). */
	public static function title(): ?Title {
		$name = (string)( $GLOBALS['wgWikvenLicensesPage'] ?? '' );
		return $name === '' ? null : Title::newFromText($name);
	}

	/** Prefixed text of the copy in one language, which is where build.php writes it. */
	public static function inLanguage(Title $page, string $language): string {
		return $page->getPrefixedText() . '/' . $language;
	}

	/**
	 * The language a title is a generated copy in, or null where it is not one.
	 *
	 * The build writes copies only where it wrote the page itself, so a subpage under a source page
	 * is the site's or Translate's.
	 *
	 * @param Title $title
	 * @param callable(string):bool $isKnownLanguage
	 * @return ?string
	 */
	public static function generatedLanguage(Title $title, callable $isKnownLanguage): ?string {
		$page = self::title();
		if ($page === null) {
			return null;
		}

		// Cheapest questions first. This is asked for every page whose language anything looks up,
		// and all but a handful are answered by the prefix.
		$prefix = $page->getPrefixedText() . '/';
		$text = $title->getPrefixedText();
		if (!str_starts_with($text, $prefix)) {
			return null;
		}

		$language = substr($text, strlen($prefix));
		if (!$isKnownLanguage($language)) {
			return null;
		}
		if (SourceFile::exists($page->getPrefixedText()) || SourceFile::exists($text)) {
			return null;
		}
		return $language;
	}

	/**
	 * The copies the build wrote, keyed by the language each one is in.
	 *
	 * The languages are the ones the source tree carries translations in. Which of those copies are
	 * the build's own is the question above.
	 *
	 * @param string $sourceDir
	 * @param callable(string):bool $isKnownLanguage
	 * @return array<string,Title> Language code => the copy written in it.
	 */
	public static function generatedCopies(string $sourceDir, callable $isKnownLanguage): array {
		$page = self::title();
		if ($page === null) {
			return [];
		}

		$copies = [];
		foreach (TranslationSource::languages($sourceDir, $isKnownLanguage) as $language) {
			$title = Title::newFromText(self::inLanguage($page, $language));
			if ($title && self::generatedLanguage($title, $isKnownLanguage) !== null) {
				$copies[$language] = $title;
			}
		}
		return $copies;
	}
}
