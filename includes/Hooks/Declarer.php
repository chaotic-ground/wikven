<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Content\Hook\PageContentLanguageHook;
use MediaWiki\Extension\Wikven\LicensesPage;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Languages\LanguageNameUtils;

/**
 * Says what language the pages the build writes for itself are in.
 *
 * A page is in the wiki's content language unless something answers otherwise, and for a
 * translation page Translate is what answers. The licenses page the build writes per language has
 * that shape and nobody to answer for it, so every copy went out as English.
 *
 * Three things read that answer and all three were wrong: the "lang" attribute on <html>, the
 * :lang() rule ULS uses to pick a webfont, and the language SifterSearch files the page under.
 */
class Declarer implements PageContentLanguageHook {
	private LanguageFactory $languageFactory;

	private LanguageNameUtils $languageNameUtils;

	public function __construct(LanguageFactory $languageFactory, LanguageNameUtils $languageNameUtils) {
		$this->languageFactory = $languageFactory;
		$this->languageNameUtils = $languageNameUtils;
	}

	/** @inheritDoc */
	public function onPageContentLanguage($title, &$pageLang, $userLang) {
		$language = LicensesPage::generatedLanguage(
			$title,
			[$this->languageNameUtils, 'isKnownLanguageTag']
		);
		if ($language !== null) {
			$pageLang = $this->languageFactory->getLanguage($language);
		}
	}
}
