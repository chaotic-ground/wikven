<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Wikven\SourceFile;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Title\Title;

class Main implements
	\MediaWiki\Hook\GetLocalURLHook,
	\MediaWiki\Hook\OutputPageAfterGetHeadLinksArrayHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook {
	private ?Context $rlClientContext = null;

	/** The directory the HTML files are written to. */
	private string $htmlDirectory;

	/** The directory the style files are written to, relative to the HTML one. */
	private string $styleDirectory;

	public function __construct(Config $config) {
		$this->htmlDirectory = $config->get('WikvenHtmlDirectory');
		$this->styleDirectory = $config->get('WikvenStyleDirectory');
	}

	/** @inheritDoc */
	public function onGetLocalURL($title, &$url, $query) {
		if (MW_ENTRY_POINT !== 'cli') {
			return;
		}
		if ($title->getInterwiki()) {
			return;
		}

		// Images come from a foreign repo (Wikimedia Commons via InstantCommons),
		// so the static export has no local File: page to link to. Point clicks at
		// the file's real description page on Commons instead of a dead ./File:*.html.
		if ($title->getNamespace() === NS_FILE) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile($title);
			if ($file && !$file->isLocal()) {
				$url = $file->getDescriptionUrl();
				return;
			}
		}

		global $wgWikvenEditUrl, $wgWikvenHistoryUrl;
		$name = Title::makeName($title->getNamespace(), $title->getDBkey());
		// Parse the query into name=>value pairs rather than substring-matching
		// "action=", which would also fire on e.g. "veaction=edit".
		$action = wfCgiToArray($query)['action'] ?? null;
		// For edit/history, $1 is the page's source filename, so the link lands
		// on the file to edit rather than the rendered page.
		if ($action === 'edit' && $wgWikvenEditUrl) {
			$url = str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenEditUrl);
		} elseif ($action === 'history' && $wgWikvenHistoryUrl) {
			$url = str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenHistoryUrl);
		} else {
			$url = "./$name.html";
		}
	}

	/**
	 * Add a "View source" tab linking to the page's source file in the repository
	 * ($wgWikvenViewSourceUrl, with $1 the source file name), the read-only
	 * counterpart of the Edit tab. Skipped when the URL is not configured.
	 *
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void {
		global $wgWikvenViewSourceUrl;
		$title = $sktemplate->getTitle();
		if (!$wgWikvenViewSourceUrl || !$title || !$title->canExist()) {
			return;
		}
		$links['views']['wikven-viewsource'] = [
			// MediaWiki core's existing "View source" label, so it is translated.
			'text' => $sktemplate->msg('viewsource')->text(),
			'href' => str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenViewSourceUrl)
		];
	}

	/** @inheritDoc */
	public function onOutputPageAfterGetHeadLinksArray(&$tags, $out) {
		// Remove unreachable links, for example, api calls.
		foreach ([
			'alternative-edit',
			'opensearch',
			'rsd',
			'universal-edit-button'
		] as $key) {
			unset($tags[$key]);
		}

		// Links static stylesheet files
		$moduleStyles = $out->getModuleStyles(true);
		$rl = $out->getResourceLoader();
		$context = $this->getRlClientContext($out);
		$moduleStyles = array_filter($moduleStyles, static function ($name) use ($rl) {
			$module = $rl->getModule($name);
			if (!$module) {
				return false;
			}
			if (in_array($module->getGroup(), ['site', 'noscript', 'private', 'user'], true)) {
				return false;
			}
			return true;
		});
		foreach ($moduleStyles as $name) {
			$module = $out->getResourceLoader()->getModule($name);
			$group = $module->getGroup();
			if (!$module->shouldEmbedModule($context)) {
				if ($group !== 'user' || !$module->isKnownEmpty($context)) {
					$path = './' . $this->styleDirectory . "/$name.css";
					$tags[$name] = Html::linkedStyle($path);
					$this->addStyleToList($name);
				}
			}
		}

		// Non-main skins render duplicate copies of every page under dist/<skin>/.
		// Point their canonical link at the main skin's copy one directory up (the
		// dist root) so crawlers dedupe to it.
		$mainSkin = $GLOBALS['wgWikvenMainSkin'] ?? null;
		$title = $out->getTitle();
		if (
			MW_ENTRY_POINT === 'cli'
			&& $mainSkin
			&& $title
			&& $out->getSkin()->getSkinName() !== $mainSkin
		) {
			$name = Title::makeName($title->getNamespace(), $title->getDBkey());
			$tags['link-canonical'] = Html::element('link', [
				'rel' => 'canonical',
				'href' => "../$name.html"
			]);
		}
	}

	private function addStyleToList(string $name): void {
		if (MW_ENTRY_POINT !== 'cli') {
			return;
		}

		$path = $this->htmlDirectory;
		if (str_ends_with($path, '/')) {
			$path = rtrim($path, '/');
		}
		$path .= '/' . $this->styleDirectory;
		if (!is_dir($path)) {
			mkdir($path, 0777, true);
		}
		if (!file_exists("$path/$name.css")) {
			touch("$path/$name.css");
		}
	}

	private function getRlClientContext(OutputPage $output): Context {
		if (!$this->rlClientContext) {
			$query = ResourceLoader::makeLoaderQuery(
				// modules; not relevant
				[],
				$output->getLanguage()->getCode(),
				$output->getSkin()->getSkinName(),
				null,
				// version; not relevant
				null,
				// inDebugMode
				Context::DEBUG_OFF,
				// only; not relevant
				null,
				// printable
				false,
				$output->getRequest()->getBool('handheld')
			);
			$this->rlClientContext = new Context(
				$output->getResourceLoader(),
				new FauxRequest($query)
			);
		}
		return $this->rlClientContext;
	}
}
