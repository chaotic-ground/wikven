<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Title\Title;

/**
 * Where the page saying what a site redistributes lives, and what its per-language copies are
 * called.
 *
 * One place, because two things have to agree on it and they run in different processes. build.php
 * writes "<Page>/<lang>" while it populates the wiki; the Declarer hook, in every skin pass after
 * that, has to recognise the same titles to say what language they are in. A rule kept twice is a
 * rule that drifts, and the drift here is silent: the page still renders, in the wrong language.
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
	 * Generated is the whole of it. The build writes copies only where it wrote the page itself --
	 * a site that provides its own is left alone, subpages and all -- so a subpage under a page the
	 * source tree provides belongs to the site, or to Translate, and this must not answer for it.
	 * The source tree is what says which, and it is the same answer in every pass.
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
	 * The languages are the ones the source tree carries translations in, because those are the
	 * ones build.php writes a copy for. Which of those copies are the build's own is the question
	 * above, asked once per language: a site that provides its own page, or its own copy in one
	 * language, keeps it, and a caller that re-rendered it would be dressing someone else's page in
	 * a language nobody asked for.
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
