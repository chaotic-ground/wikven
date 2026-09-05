<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * Which page answers for which language in a translatable page's family.
 *
 * A translatable page is three or more pages: "Development" is the source, written in whatever
 * language its author wrote it in, and "Development/ko" is the Korean one. The rule that needs
 * saying out loud is the one about the source's own language. Translate makes a translation page
 * for it too -- "Development/en" -- and that page is the source page's article again, word for
 * word, at a second address. hreflang has no way to say a language is at two addresses, so one of
 * them has to own it, and it is the source page: that is the address every link in the export
 * names, and "/en" is reached only from a language bar.
 *
 * Names rather than titles, and no Translate here, for the reason StalenessComputer beside it
 * gives: the rule is decided in one place, and the place that decides it can be read on its own.
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
