<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MainConfigNames;

/**
 * Which languages Translate offers, cut to the ones a source tree could be read in.
 *
 * Translate offers every language MediaWiki knows and keeps a statistics row for each, so a bake
 * counted six hundred it has no file for.
 *
 * Translate declares SupportedLanguagesHook and this does not implement it: a class implementing
 * an absent interface cannot load. MediaWiki dispatches by name.
 *
 * @see \MediaWiki\Extension\Translate\Utilities\SupportedLanguagesHook for the signature.
 */
class Narrower {
	private Config $config;

	private LanguageNameUtils $languageNameUtils;

	/** @var ?string[] Kept for the calls after the first: the answer is a walk of the source tree. */
	private ?array $offered = null;

	public function __construct(Config $config, LanguageNameUtils $languageNameUtils) {
		$this->config = $config;
		$this->languageNameUtils = $languageNameUtils;
	}

	/**
	 * Leave the languages the source tree writes in, and leave every language where it has none.
	 *
	 * @param string[] &$list Language names, keyed by code.
	 * @param ?string $language The language the names are in; this reads only the codes.
	 */
	public function onTranslateSupportedLanguages(array &$list, ?string $language) {
		$offered = $this->offered();
		if ($offered === null) {
			return;
		}
		$list = array_intersect_key($list, array_fill_keys($offered, true));
	}

	/**
	 * The languages the source tree can be read in, or null where there is no source tree.
	 *
	 * A translation file for each, the wiki's own language, and the code Translate documents
	 * messages under where one is set.
	 *
	 * @return ?string[]
	 */
	private function offered(): ?array {
		if ($this->offered !== null) {
			return $this->offered;
		}
		$source = rtrim((string)$this->config->get('WikvenSourceDirectory'), '/');
		if ($source === '' || !is_dir($source)) {
			return null;
		}
		$offered = TranslationSource::languages(
			$source,
			[$this->languageNameUtils, 'isKnownLanguageTag']
		);
		$offered[] = (string)$this->config->get(MainConfigNames::LanguageCode);
		$documentation = (string)$this->config->get('TranslateDocumentationLanguageCode');
		if ($documentation !== '') {
			$offered[] = $documentation;
		}
		$this->offered = $offered;
		return $offered;
	}
}
