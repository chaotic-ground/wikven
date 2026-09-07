<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * Which page answers for which language in a translatable page's family.
 *
 * A translatable page is three or more: "Development" the source, "Development/ko" the Korean one,
 * and "Development/en" the source's article again at a second address. hreflang cannot say a
 * language is at two, so the source page owns it.
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
