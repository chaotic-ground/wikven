<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * What the search index is asked to leave out.
 *
 * SifterSearch declares SifterSearchIndexPageHook for this, and this does not implement it: the
 * interface is only on disk where SifterSearch is installed, and a class implementing a missing one
 * cannot be loaded at all. MediaWiki dispatches by method name regardless.
 *
 * @see \MediaWiki\Extension\SifterSearch\Hook\SifterSearchIndexPageHook for the signature.
 */
class Indexer {
	/**
	 * How the source language of a translation page is looked up.
	 *
	 * A seam, so the rule below can be tested without Translate. Phan wants the nullable return
	 * parenthesised.
	 *
	 * @var callable(Title):(?string)
	 */
	private $sourceLanguageOf;

	/**
	 * @param callable|null $sourceLanguageOf Takes a Title, answers its source language or null.
	 *   Defaults to asking Translate.
	 */
	public function __construct(?callable $sourceLanguageOf = null) {
		$this->sourceLanguageOf = $sourceLanguageOf ?? [self::class, 'translateSourceLanguage'];
	}

	/**
	 * Leave out a translation page written in the language it was translated from.
	 *
	 * A marked page gets one per language, the source language included, so an English reader was
	 * offered both (#454). The source page is the one kept.
	 */
	public function onSifterSearchIndexPage(Title $title, bool &$index) {
		$sourceLanguage = ( $this->sourceLanguageOf )($title);
		if ($sourceLanguage !== null && self::translationLanguage($title) === $sourceLanguage) {
			$index = false;
		}
	}

	/**
	 * The language a translation page is in: the segment after its source page's title.
	 *
	 * Not Title::getSubpageText(), which answers with the whole title unless subpages are on for
	 * the namespace, and nothing turns them on for NS_MAIN.
	 */
	private static function translationLanguage(Title $title): ?string {
		$text = $title->getText();
		$cut = strrpos($text, '/');
		return $cut === false ? null : substr($text, $cut + 1);
	}

	/**
	 * The language a translation page was translated from, or null where it is not one.
	 *
	 * isTranslationPage() checks that the page above is marked, so a wiki keeping a page of its
	 * own called "Foo/en" keeps it in the index.
	 */
	private static function translateSourceLanguage(Title $title): ?string {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			// No Translate, no translation pages, and so nothing here duplicating a source page.
			return null;
		}
		$translatable = TranslatablePage::isTranslationPage($title);
		return $translatable ? $translatable->getSourceLanguageCode() : null;
	}
}
