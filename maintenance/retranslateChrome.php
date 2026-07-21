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
 * caches the canonical anonymous view (interface language == content language). That leaves a
 * translated page such as "index/ko" with English chrome. Re-render each non-source-language
 * translation page with its own language as the interface language and overwrite its cache file,
 * so a reader browsing the Korean page gets a Korean interface too.
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

		foreach (TranslationSource::baseFiles($source) as $baseFile) {
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

		return true;
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
