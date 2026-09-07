<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Registration\ExtensionRegistry;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Resolve Translate's Special:MyLanguage/ links in the exported HTML.
 *
 * Translation pages are parsed in the source-page context, so the page language is not known at
 * render time; the exported path is. This runs after Rename, reading each file's language from
 * its path.
 *
 * The walk goes through SkinOutput: a link in dist/citizen/Foo/ko.html must not be decided by
 * whether dist/Foo/ko.html exists.
 */
class ResolveTranslationLinks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Resolve Special:MyLanguage/ links in the exported HTML to static pages.');
	}

	public function execute() {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			return;
		}
		$config = $this->getConfig();
		$htmlDir = rtrim((string)$config->get('WikvenHtmlDirectory'), '/');
		if ($htmlDir === '' || !is_dir($htmlDir)) {
			return;
		}
		$languageNameUtils = $this->getServiceContainer()->getLanguageNameUtils();

		$pages = SkinOutput::pages(
			$htmlDir,
			(array)$config->get('WikvenSkins'),
			(string)$config->get('WikvenMainSkin'),
			(string)$config->get('DefaultSkin')
		);
		foreach ($pages as $path) {
			$lang = $this->fileLanguage($path, $htmlDir, $languageNameUtils);
			$html = (string)file_get_contents($path);
			$resolved = RelativeUrl::resolveMyLanguage(
				$html,
				$lang,
				static function (string $target) use ($htmlDir, $lang): bool {
					// The link the marker carries, turned back into the file it names.
					return is_file($htmlDir . '/' . OutputName::file($target) . "/$lang.html");
				}
			);
			if ($resolved !== $html) {
				file_put_contents($path, $resolved, LOCK_EX);
			}
		}
	}

	/** A translation page lives at "<Page>/<lang>.html"; return that language, or null for other pages. */
	private function fileLanguage(string $path, string $htmlDir, LanguageNameUtils $languageNameUtils): ?string {
		// Top-level files are source pages, never a "<Page>/<lang>" translation.
		if (dirname($path) === $htmlDir) {
			return null;
		}
		$segment = basename($path, '.html');
		return $languageNameUtils->isKnownLanguageTag($segment) ? $segment : null;
	}
}

$maintClass = ResolveTranslationLinks::class;
require_once RUN_MAINTENANCE_IF_MAIN;
