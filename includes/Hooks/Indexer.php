<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\SifterSearch\Hook\SifterSearchIndexPageHook;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * What the search index is asked to leave out.
 *
 * @see \MediaWiki\Extension\SifterSearch\Hook\SifterSearchIndexPageHook
 */
class Indexer implements SifterSearchIndexPageHook {
	/**
	 * Leave out a translation page written in the language it was translated from.
	 *
	 * Marking a page for translation gives it a translation page per language, the source language
	 * included, so "Installation" and "Installation/en" carry the same English text under two
	 * titles. Both are pages of the export and both are indexed, so an English reader searching is
	 * offered the same thing twice (#454). Indexing per language cannot separate them, being one
	 * language by construction, and neither can $wgSifterSearchNamespaces, both being NS_MAIN.
	 *
	 * The source page is the one kept. It is where the site's own links land -- resolveTranslationLinks
	 * sends a Special:MyLanguage link from an untranslated page to the source page, and the export's
	 * front door, index.html, is the source page of the main page -- and it is the only one of the
	 * two that a page nobody marked for translation has at all.
	 *
	 * What is asked of Translate is whether this is a translation page, not whether the title looks
	 * like one: isTranslationPage() answers no for a subpage somebody merely named after a language,
	 * since it checks that the page above it is marked. A wiki that keeps a page called "Foo/en" of
	 * its own therefore keeps it in the index.
	 */
	public function onSifterSearchIndexPage(Title $title, bool &$index) {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			// No Translate, no translation pages, and so nothing here duplicating a source page.
			return;
		}
		$translatable = TranslatablePage::isTranslationPage($title);
		if (!$translatable) {
			return;
		}
		// Safe to read the last segment as the language now that Translate has said this is a
		// translation page, which is what makes that segment its language code.
		if ($title->getSubpageText() === $translatable->getSourceLanguageCode()) {
			$index = false;
		}
	}
}
