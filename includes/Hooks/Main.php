<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWiki\Extension\Wikven\SourceFile;

class Main implements \MediaWiki\Hook\GetLocalURLHook, \MediaWiki\Hook\OutputPageAfterGetHeadLinksArrayHook {
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
		if (MW_ENTRY_POINT != 'cli') {
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
			$url = str_replace('$1', $this->sourceFileParam($title), $wgWikvenEditUrl);
		} elseif ($action === 'history' && $wgWikvenHistoryUrl) {
			$url = str_replace('$1', $this->sourceFileParam($title), $wgWikvenHistoryUrl);
		} else {
			$url = "./$name.html";
		}
	}

	/**
	 * The source file a page imported from, percent-encoded for use as the $1 in
	 * WikvenEditUrl/WikvenHistoryUrl. Built from the title text (spaces, not the
	 * DB key's underscores) so it matches the on-disk file name, then encoded so
	 * characters legal in a title but unsafe in a URL path (spaces, '#', '?',
	 * '%', non-ASCII) cannot break or truncate the link. The subpage separator
	 * '/' and the namespace separator ':' are kept readable.
	 */
	private function sourceFileParam(Title $title): string {
		$file = SourceFile::titleToFilename($title->getPrefixedText());
		return strtr(rawurlencode($file), ['%2F' => '/', '%3A' => ':']);
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
			if (in_array($module->getGroup(), ['site', 'noscript', 'private', 'user'])) {
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
	}

	private function addStyleToList(string $name): void {
		if (MW_ENTRY_POINT != 'cli') {
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
				null,
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
