<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * Which page answers for which language in a translatable page's family.
 *
 * A translatable page is three or more pages: "Development" is the source and "Development/ko" the
 * Korean one. The rule worth saying out loud is the one about the source's own language. Translate
 * makes "Development/en" too, which is the source page's article again at a second address, and
 * hreflang cannot say a language is at two -- so the source page owns it, being the address every
 * link in the export names.
 */
class TranslationFamily {
	/**
	 * One page per language, the source page's own included.
	 *
	 * @param string $source The source page's name.
	 * @param string $sourceLanguage The language it is written in.
	 * @param string[] $translationPages Names of its translation pages, "<source>/<code>".
	 * @return array<string,string> Language code => the name of the page that answers for it.
	 */
	public static function byLanguage(string $source, string $sourceLanguage, array $translationPages): array {
		$languages = [$sourceLanguage => $source];
		foreach ($translationPages as $page) {
			$code = self::languageOf($page, $source);
			// The source language's own translation page restates the source page; see above.
			if ($code !== '' && $code !== $sourceLanguage) {
				$languages[$code] = $page;
			}
		}
		return $languages;
	}

	/**
	 * The page a given one's content really lives at: itself, unless it restates the source page.
	 *
	 * The source page passed in answers with itself, because what it carries past the source page's
	 * own name is nothing rather than a language.
	 */
	public static function owner(string $page, string $source, string $sourceLanguage): string {
		return self::languageOf($page, $source) === $sourceLanguage ? $source : $page;
	}

	/** The language a translation page is in: what its name carries past the source page's own. */
	public static function languageOf(string $translationPage, string $source): string {
		return substr($translationPage, strlen($source) + 1);
	}
}
