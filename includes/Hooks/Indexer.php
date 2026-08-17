<?php
/**
 * Translate is an optional, image-bundled dependency that phan does not analyse, so its symbols
 * are undeclared to static analysis here. It is only asked anything once it has said it is loaded.
 *
 * @phan-file-suppress PhanUndeclaredClassMethod
 */

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * What the search index is asked to leave out.
 *
 * SifterSearch declares SifterSearchIndexPageHook for this, and this does not implement it: the
 * interface is only on disk where SifterSearch is installed, and a class that implements a missing
 * one cannot be loaded at all -- not by the test suite, which installs neither. MediaWiki dispatches
 * a hook by method name regardless, so what the interface buys is a signature check, and what it
 * costs here is the class being unloadable wherever the optional dependency is absent.
 *
 * @see \MediaWiki\Extension\SifterSearch\Hook\SifterSearchIndexPageHook for the signature.
 */
class Indexer {
	/**
	 * How the source language of a translation page is looked up.
	 *
	 * A seam, so the rule below can be tested without Translate: it is an optional dependency the
	 * test suite does not install, and asking it is a static call there is otherwise no way past.
	 * The rule is the part worth testing; the lookup is one question put to somebody else.
	 *
	 * @var callable(Title):?string
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
	 * Marking a page for translation gives it a translation page per language, the source language
	 * included, so "Installation" and "Installation/en" carry the same English text under two
	 * titles. Both are pages of the export and both were indexed, so an English reader searching was
	 * offered the same thing twice (#454). Indexing per language cannot separate them, being one
	 * language by construction, and neither can $wgSifterSearchNamespaces, both being NS_MAIN.
	 *
	 * The source page is the one kept. It is where the site's own links land -- resolveTranslationLinks
	 * sends a Special:MyLanguage link from an untranslated page to the source page, and the export's
	 * front door, index.html, is the source page of the main page -- and it is the only one of the
	 * two that a page nobody marked for translation has at all.
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
	 * Cut here rather than asked of Title::getSubpageText(), which answers with the whole title
	 * unless subpages are switched on for the namespace -- and nothing switches them on for NS_MAIN,
	 * where these pages live. Translate names a translation page "<source>/<language>" whatever
	 * MediaWiki thinks a subpage is, so the last segment is the language either way, and reading it
	 * as one is safe only because the lookup above has already said this is a translation page.
	 */
	private static function translationLanguage(Title $title): ?string {
		$text = $title->getText();
		$cut = strrpos($text, '/');
		return $cut === false ? null : substr($text, $cut + 1);
	}

	/**
	 * The language a translation page was translated from, or null where it is not one.
	 *
	 * What is asked of Translate is whether this is a translation page, not whether the title looks
	 * like one: isTranslationPage() answers no for a subpage somebody merely named after a language,
	 * since it checks that the page above it is marked for translation. A wiki that keeps a page of
	 * its own called "Foo/en" therefore keeps it in the index.
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
