<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Cache\HTMLFileCache;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Page\Article;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * RebuildFileCache renders every page in the wiki's content language, because HTMLFileCache only
 * caches the canonical anonymous view (interface language == content language). That leaves every
 * translation -- "index/ko", "index/km", all of them -- wearing the content language's chrome.
 * Re-render each non-source-language translation page with its own language as the interface
 * language and overwrite its cache file, so a reader browsing a translation gets an interface in
 * the language they are reading.
 */
class RetranslateChrome extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Re-render translated pages with their own language as the interface language.');
	}

	/** @return bool Always true; nothing to do without Translate or a source directory. */
	public function execute() {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			return true;
		}
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		if ($source === '' || !is_dir($source)) {
			return true;
		}

		$services = $this->getServiceContainer();
		$contentLang = $services->getContentLanguage()->getCode();
		$isKnownLanguage = [$services->getLanguageNameUtils(), 'isKnownLanguageTag'];

		foreach (TranslationSource::baseFiles($source, $isKnownLanguage) as $baseFile) {
			$relative = substr($baseFile, strlen($source) + 1);
			$baseTitle = SourceFile::filenameToTitle($relative);
			foreach (TranslationSource::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
				// The source-language page already renders in the content language.
				if ($lang === $contentLang) {
					continue;
				}
				$title = Title::newFromText("$baseTitle/$lang");
				if (!$title || !$title->exists()) {
					continue;
				}
				$this->recache($title, $lang);
			}
		}

		$this->recacheGeneratedLicenses($source, $contentLang, $isKnownLanguage);

		return true;
	}

	/**
	 * The licenses page's own language copies, which the walk above cannot reach.
	 *
	 * That walk finds translations by their source files, and these have none: build.php writes
	 * them, message by message, in each language the site is built in. They are pages in that
	 * language all the same -- the Declarer hook answers for them -- so the chrome around them has
	 * to follow, or a Korean page keeps an English menu and footer.
	 *
	 * Only the copies the build wrote: a page the source tree provided, under that title or under
	 * one of its language subpages, is the site's or Translate's, and is handled above. LicensesPage
	 * is asked which those are, the same as the Declarer hook asks it, so the chrome a copy wears
	 * and the language it declares cannot end up disagreeing.
	 *
	 * @param string $source
	 * @param string $contentLang
	 * @param callable(string):bool $isKnownLanguage
	 */
	private function recacheGeneratedLicenses(string $source, string $contentLang, callable $isKnownLanguage): void {
		foreach (LicensesPage::generatedCopies($source, $isKnownLanguage) as $lang => $title) {
			if ($lang === $contentLang || !$title->exists()) {
				continue;
			}
			$this->recache($title, $lang);
		}
	}

	/** Render one page with $lang as the interface language and overwrite its view cache file. */
	private function recache(Title $title, string $lang): void {
		$context = new RequestContext();
		$context->setTitle($title);
		$context->setLanguage($lang);
		$article = Article::newFromTitle($title, $context);
		$context->setWikiPage($article->getPage());
		// Some extensions read the main context's title.
		RequestContext::getMain()->setTitle($title);

		ob_start();
		$article->view();
		$context->getOutput()->output();
		$context->getOutput()->clearHTML();
		$html = ob_get_clean();

		( new HTMLFileCache($title, 'view') )->saveToFileCache($html);
		$this->output("Wikven: re-rendered {$title->getPrefixedText()} in $lang\n");
	}
}

$maintClass = RetranslateChrome::class;
require_once RUN_MAINTENANCE_IF_MAIN;
