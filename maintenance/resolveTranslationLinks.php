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
 * render time; the exported path is (a translation is at "<Page>/<lang>.html"). This runs after
 * Rename, over the final tree: it reads each file's language from its path, then rewrites every
 * "Special:MyLanguage/Target" link to the target's translation in that language when it exists, or
 * the source target otherwise, keeping the link's existing relative prefix.
 *
 * "The final tree" is this pass's own pages and no one else's: the walk goes through SkinOutput,
 * which keeps the main skin's out of the other skins' output below it. Deciding a link in
 * dist/citizen/Foo/ko.html by whether dist/Foo/ko.html exists is how that goes wrong.
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
		$htmlDir = rtrim($GLOBALS['wgWikvenHtmlDirectory'], '/');
		if ($htmlDir === '' || !is_dir($htmlDir)) {
			return;
		}
		$languageNameUtils = $this->getServiceContainer()->getLanguageNameUtils();

		$pages = SkinOutput::pages(
			$htmlDir,
			$GLOBALS['wgWikvenSkins'] ?? [],
			(string)( $GLOBALS['wgWikvenMainSkin'] ?? $GLOBALS['wgDefaultSkin'] ),
			(string)$GLOBALS['wgDefaultSkin']
		);
		foreach ($pages as $path) {
			$lang = $this->fileLanguage($path, $htmlDir, $languageNameUtils);
			$html = (string)file_get_contents($path);
			$resolved = RelativeUrl::resolveMyLanguage(
				$html,
				$lang,
				static function (string $target) use ($htmlDir, $lang): bool {
					return is_file("$htmlDir/$target/$lang.html");
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
