<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\Search;
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
	\MediaWiki\Hook\SetupAfterCacheHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook {
	private ?Context $rlClientContext = null;

	private Config $config;

	/** The directory the HTML files are written to. */
	private string $htmlDirectory;

	/** The directory the style files are written to, relative to the HTML one. */
	private string $styleDirectory;

	public function __construct(Config $config) {
		$this->config = $config;
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

		// Foreign-repo files (Commons via InstantCommons) have no local File: page; link to Commons.
		if ($title->getNamespace() === NS_FILE) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile($title);
			if ($file && !$file->isLocal()) {
				$url = $file->getDescriptionUrl();
				return;
			}
		}

		global $wgWikvenEditUrl, $wgWikvenHistoryUrl;

		// Translate's banner and "Translate" tab link to Special:Translate (the in-wiki translation UI),
		// which is not exported. Point them at the translation's source file on the edit host: the
		// query carries "group=page-<base>" and "language=<code>", so "<base>/<code>" is the file.
		if ($wgWikvenEditUrl && $title->isSpecial('Translate')) {
			$params = wfCgiToArray($query);
			if (isset($params['language']) && str_starts_with($params['group'] ?? '', 'page-')) {
				$translation = Title::newFromText(substr($params['group'], 5) . '/' . $params['language']);
				if ($translation) {
					$url = str_replace(
						'$1',
						SourceFile::titleToParam($translation->getPrefixedText()),
						$wgWikvenEditUrl
					);
					return;
				}
			}
		}

		// Vector renders the collapsed search box as a link to Special:Search -- the page the "f"
		// accesskey opens, and at a narrow viewport the whole of the search affordance -- and the
		// export has no such page. Where search lands here is SifterSearch's results page, so send
		// it there instead of at a 404. SifterSearch retargets the link itself once its script
		// runs; writing the link out right is what makes the exported page correct on its own.
		if ($title->isSpecial('Search')) {
			$url = $this->searchUrl($query);
			return;
		}

		$name = Title::makeName($title->getNamespace(), $title->getDBkey());
		// Parse query to name=>value; substring-matching "action=" would also match "veaction=edit".
		$params = wfCgiToArray($query);
		$action = $params['action'] ?? null;
		// A diff is history too. The export holds one revision of a page, so what changed is only
		// in the repository, and a link asking to see it belongs where the history link goes:
		// Citizen's "last modified" button asks for the latest diff ("diff" with no value), and
		// without this it resolves to the page it is already on.
		$wantsHistory = $action === 'history' || array_key_exists('diff', $params);
		// For edit/history, $1 is the source filename so the link targets the editable file.
		if ($action === 'edit' && $wgWikvenEditUrl) {
			$url = str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenEditUrl);
		} elseif ($wantsHistory && $wgWikvenHistoryUrl) {
			$url = str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenHistoryUrl);
		} else {
			$url = "./$name.html";
		}
	}

	/**
	 * Where a search goes in the export: the results page, carrying the term the link asks for
	 * (SifterSearch's widget reads it from "?search="), or nowhere.
	 *
	 * With no results page named there is nowhere to land, so the link is left the button its own
	 * script already treats it as: Vector's toggle prevents the navigation and opens the box, which
	 * is the whole of what it is for, and a reader without scripts has no search here either way --
	 * Pagefind answers in the browser. That beats a link to a page that answers 404.
	 */
	private function searchUrl(string $query): string {
		$results = Search::resultsPage();
		if (!$results) {
			return '#';
		}
		$url = './' . Title::makeName($results->getNamespace(), $results->getDBkey()) . '.html';
		$term = wfCgiToArray($query)['search'] ?? '';
		return $term === '' ? $url : $url . '?' . wfArrayToCgi(['search' => $term]);
	}

	/**
	 * Resolve $wgWikvenLogos -- $wgLogos, but each src is a source-dir file name -- to $wgLogos
	 * proper, once each file's actual upload URL can be asked for.
	 *
	 * This has to run here rather than in WikvenSettings.php (LocalSettings.php time), because the
	 * service container -- Title, RepoGroup -- does not exist yet that early.
	 *
	 * @inheritDoc
	 */
	public function onSetupAfterCache(): void {
		$this->restoreCitizenSearchWiring();
		$this->unbindUlsInputMethods();

		$wikvenLogos = $this->config->get('WikvenLogos');
		if (!is_array($wikvenLogos) || $wikvenLogos === []) {
			return;
		}
		$sourceDirectory = $this->config->get('WikvenSourceDirectory');

		$logos = is_array($GLOBALS['wgLogos'] ?? null) ? $GLOBALS['wgLogos'] : [];
		foreach ($wikvenLogos as $key => $value) {
			if (is_array($value)) {
				if (!isset($value['src'])) {
					$logos[$key] = $value;
					continue;
				}
				$src = $this->resolveLogoUrl($sourceDirectory, (string)$value['src']);
				if ($src === null) {
					continue;
				}
				$value['src'] = $src;
				$logos[$key] = $value;
			} else {
				$url = $this->resolveLogoUrl($sourceDirectory, (string)$value);
				if ($url !== null) {
					$logos[$key] = $url;
				}
			}
		}
		$GLOBALS['wgLogos'] = $logos;
	}

	/**
	 * Let core wire up Citizen's search box again, so SifterSearch's typeahead reaches it.
	 *
	 * Citizen sets SkinPageReadyConfig's "search" to false, because its own search is the command
	 * palette rather than the box core would attach to. An export has no backend for the palette,
	 * so rewriteScripts leaves the plain form the skin renders underneath it standing instead --
	 * and that form is what core's wiring, and through it the Pagefind typeahead SifterSearch
	 * substitutes, attaches to. Everything else the flag governs is the box we are keeping.
	 *
	 * Registered at run time rather than in extension.json because order decides this: handlers
	 * declared there run in load order, and wikven loads before the skins do, so a handler of ours
	 * would be overruled by Citizen's. HookContainer::register() appends after every extension and
	 * skin handler, which is the only place a correction can sit.
	 */
	private function restoreCitizenSearchWiring(): void {
		if (!Search::isActive()) {
			return;
		}
		MediaWikiServices::getInstance()
			->getHookContainer()
			->register('SkinPageReadyConfig', [$this, 'enableCitizenSearch']);
	}

	/**
	 * Leave ULS's input methods with no field to attach to.
	 *
	 * ULSIMEEnabled, which WikvenSettings turns off, does not stop the request: ext.uls.interface
	 * binds a focus handler to every text field whatever that flag says, and the flag is read
	 * inside ext.uls.ime, which the handler has fetched from load.php by then. So the empty
	 * selector list is what actually keeps the export quiet on the first click into a field.
	 *
	 * It has to be set here rather than in WikvenSettings.php, because an empty array is the one
	 * value ExtensionRegistry does not keep: it reads as "not set", and the extension's own
	 * default replaces it wholesale when the registry applies extension.json config.
	 *
	 * The global is the test for ULS: it exists because ULS's extension.json declares it.
	 */
	private function unbindUlsInputMethods(): void {
		if (!isset($GLOBALS['wgULSImeSelectors'])) {
			return;
		}
		$GLOBALS['wgULSImeSelectors'] = [];
	}

	/**
	 * Turn core's search wiring back on, for Citizen alone.
	 *
	 * @internal Registered as a hook handler by restoreCitizenSearchWiring(); public so that it is
	 *   callable, and so that what it decides can be asserted without a hook run around it.
	 */
	public function enableCitizenSearch(Context $context, array &$config): void {
		if ($context->getSkin() === 'citizen') {
			$config['search'] = true;
		}
	}

	/**
	 * The upload URL a source-dir file name $name will have, or null if it does not exist there.
	 */
	private function resolveLogoUrl(string $sourceDirectory, string $name): ?string {
		if ($sourceDirectory === '' || !is_file("$sourceDirectory/$name")) {
			error_log("Wikven: logo file '$name' not found in the source directory");
			return null;
		}
		$title = Title::makeTitleSafe(NS_FILE, $name);
		if ($title === null) {
			error_log("Wikven: logo file '$name' is not a valid file title");
			return null;
		}
		return MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile($title)->getUrl();
	}

	/**
	 * Add a "View source" tab linking to the page's source file (read-only counterpart of Edit).
	 *
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void {
		global $wgWikvenViewSourceUrl;
		// A tab of wikven's own, added to the row the skin lays out: chrome, and a preview keeps
		// the row the skin would have drawn. See BuildFor.
		if (BuildFor::skinPreview()) {
			return;
		}
		$title = $sktemplate->getTitle();
		// A generated page (e.g. Version) has no source file; skip rather than emit a 404 link.
		if (
			!$wgWikvenViewSourceUrl
			|| !$title
			|| !$title->canExist()
			|| !SourceFile::exists($title->getPrefixedText())
		) {
			return;
		}
		$links['views']['wikven-viewsource'] = [
			// MediaWiki core's existing "View source" label, so it is translated.
			'text' => $sktemplate->msg('viewsource')->text(),
			'href' => str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $wgWikvenViewSourceUrl)
		];

		// Citizen draws the page actions as icon buttons and stops rendering their labels below
		// desktop width (font-size: 0, Pagetools.less), so a tab it has no icon for is a blank box
		// the width of its padding: nothing to read and little to hit. Its icons come from a map
		// keyed by the names core uses, which this tab -- being ours -- is not in; what the skin
		// does for every entry is turn an 'icon' the item carries into the markup, so the tab has
		// to bring its own. wikiText rather than the editLock the skin gives core's "View source",
		// because the Edit tab beside it works: a padlock would read as a permission this reader is
		// missing rather than as a link to the file.
		//
		// Citizen alone, because core hands the key to whichever skin renders the page. Vector 2022
		// suppresses icons on the tabs themselves, but not in the page-tools dropdown it copies
		// them into, where Edit and View history next to it have none.
		if ($sktemplate->getSkinName() === 'citizen') {
			$links['views']['wikven-viewsource']['icon'] = 'wikiText';
		}
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

		// Non-main skins duplicate every page; point canonical at the main skin's copy one dir up.
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
		// Empty outside a build: there is no static site to place the file in.
		if (MW_ENTRY_POINT !== 'cli' || $this->htmlDirectory === '') {
			return;
		}

		$path = $this->htmlDirectory;
		if (str_ends_with($path, '/')) {
			$path = rtrim($path, '/');
		}
		$path .= '/' . $this->styleDirectory;
		// Honours $wgDirectoryMode, tolerates a concurrent create (build.php renders one process per
		// skin) and logs a real failure instead of printing a warning into the page.
		if (!wfMkdirParents($path, null, __METHOD__)) {
			return;
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
