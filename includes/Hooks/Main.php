<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Extension\Wikven\AssetFile;
use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\LicensesPage;
use MediaWiki\Extension\Wikven\OutputName;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationFamily;
use MediaWiki\Extension\Wikven\Search;
use MediaWiki\Extension\Wikven\SiteUrl;
use MediaWiki\Extension\Wikven\SourceFile;
use MediaWiki\Html\Html;
use MediaWiki\Language\LanguageCode;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Title\Title;

class Main implements
	\MediaWiki\Hook\GetCanonicalURLHook,
	\MediaWiki\Hook\GetFullURLHook,
	\MediaWiki\Hook\GetLocalURLHook,
	\MediaWiki\Hook\OutputPageAfterGetHeadLinksArrayHook,
	\MediaWiki\Hook\SetupAfterCacheHook,
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook {
	private ?Context $rlClientContext = null;

	private Config $config;

	/** The directory the HTML files are written to. */
	private string $htmlDirectory;

	/** The directory everything the build generates is written to, relative to the HTML one. */
	private string $assetDirectory;

	/** Where the site says it will be published, unknown where it has not said. */
	private SiteUrl $siteUrl;

	/** The licenses page's per-language copies, read from the source tree once; see licensesCluster(). */
	private ?array $licensesCopies = null;

	public function __construct(Config $config) {
		$this->config = $config;
		$this->htmlDirectory = $config->get('WikvenHtmlDirectory');
		$this->assetDirectory = $config->get('WikvenAssetDirectory');
		// Read once: onGetLocalURL answers every link on every page, and parsing a URL per link
		// would be paid thousands of times a render for a value that cannot change mid-build.
		$this->siteUrl = SiteUrl::fromWritten((string)$config->get('WikvenSiteUrl'));
	}

	/** @inheritDoc */
	public function onGetLocalURL($title, &$url, $query) {
		$target = $this->exportTarget($title, (string)$query);
		if ($target !== null) {
			$url = $target['local'];
		}
	}

	/**
	 * The whole URL of the page, for a caller that asked for one.
	 *
	 * Which of the three URL hooks ran is the only honest signal, so the answer is recomputed from
	 * the Title: expand() once put "http:index.html" in an og:url.
	 *
	 * @inheritDoc
	 */
	public function onGetFullURL($title, &$url, $query) {
		$target = $this->exportTarget($title, (string)$query);
		if ($target !== null && $target['whole'] !== null) {
			$url = $target['whole'];
		}
	}

	/**
	 * As onGetFullURL, plus the fragment getCanonicalURL() carries and getFullURL() does not.
	 *
	 * @inheritDoc
	 */
	public function onGetCanonicalURL($title, &$url, $query) {
		$target = $this->exportTarget($title, (string)$query);
		if ($target !== null && $target['whole'] !== null) {
			$url = $target['whole'] . $title->getFragmentForURL();
		}
	}

	/**
	 * A target outside the export: one whole URL, and the same URL whichever hook asked.
	 *
	 * @return array{local:string,whole:string}
	 */
	private static function leavingTheExport(string $url): array {
		return ['local' => $url, 'whole' => $url];
	}

	/**
	 * A file this build wrote, named relative to the output root.
	 *
	 * A page links to it from beside itself, which is what makes an export work from any
	 * directory. Its whole URL needs the address the site is published at.
	 *
	 * @return array{local:string,whole:?string}
	 */
	private function inTheExport(string $href): array {
		return [
			'local' => './' . $href,
			'whole' => $this->siteUrl->isKnown() ? $this->siteUrl->forFile($href) : null
		];
	}

	/**
	 * Where a title points in this export, or null where the build leaves the URL alone.
	 *
	 * One decision for all three URL hooks, so a Commons file and an edit link are answered the
	 * same whichever asked.
	 *
	 * @param Title $title
	 * @param string $query
	 * @return array{local:string,whole:?string}|null
	 */
	private function exportTarget($title, string $query): ?array {
		if (MW_ENTRY_POINT !== 'cli') {
			return null;
		}
		if ($title->getInterwiki()) {
			return null;
		}

		// Foreign-repo files (Commons via InstantCommons) have no local File: page; link to Commons.
		// A repo that names no description page answers false: say nothing rather than write an empty
		// href, which is what came out before.
		if ($title->getNamespace() === NS_FILE) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile($title);
			if ($file && !$file->isLocal()) {
				$description = $file->getDescriptionUrl();
				return is_string($description) && $description !== ''
					? self::leavingTheExport($description)
					: null;
			}
		}

		$editUrl = (string)$this->config->get('WikvenEditUrl');
		$historyUrl = (string)$this->config->get('WikvenHistoryUrl');

		// Translate's banner and "Translate" tab link to Special:Translate (the in-wiki translation UI),
		// which is not exported. Point them at the translation's source file on the edit host: the
		// query carries "group=page-<base>" and "language=<code>", so "<base>/<code>" is the file.
		if ($editUrl && $title->isSpecial('Translate')) {
			$params = wfCgiToArray($query);
			if (isset($params['language']) && str_starts_with($params['group'] ?? '', 'page-')) {
				$translation = Title::newFromText(substr($params['group'], 5) . '/' . $params['language']);
				if ($translation) {
					return self::leavingTheExport(str_replace(
						'$1',
						SourceFile::titleToParam($translation->getPrefixedText()),
						$editUrl
					));
				}
			}
		}

		// Vector renders the collapsed search box as a link to Special:Search, which the export has no
		// page for; where search lands here is SifterSearch's results page. Its script retargets the
		// link too, but the written one stands alone.
		if ($title->isSpecial('Search')) {
			$search = $this->searchHref($query);
			// Nowhere to land: the toggle stays the button its own script treats it as, and there
			// is no page for a caller wanting a whole URL to be told about.
			return $search === null ? ['local' => '#', 'whole' => null] : $this->inTheExport($search);
		}

		// The file this title is written to, url-encoded so a static server asking for it finds it;
		// see OutputName, which rename.php asks the same question of from the file's side.
		$href = OutputName::href(OutputName::of(...$this->nameFor($title)));
		// Parse query to name=>value; substring-matching "action=" would also match "veaction=edit".
		$params = wfCgiToArray($query);
		$action = $params['action'] ?? null;
		// A diff is history too: the export holds one revision, so what changed is only in the
		// repository. Citizen's "last modified" button asks for the latest diff, and without this it
		// resolves to the page it is already on.
		$wantsHistory = $action === 'history' || array_key_exists('diff', $params);
		// For edit/history, $1 is the source filename so the link targets the editable file.
		if ($action === 'edit' && $editUrl) {
			return self::leavingTheExport(str_replace(
				'$1',
				SourceFile::titleToParam($title->getPrefixedText()),
				$editUrl
			));
		}
		if ($wantsHistory && $historyUrl) {
			return self::leavingTheExport(str_replace(
				'$1',
				SourceFile::titleToParam($title->getPrefixedText()),
				$historyUrl
			));
		}
		return $this->inTheExport($href);
	}

	/**
	 * The namespace and title a link is written from.
	 *
	 * A special page is named canonically rather than as this wiki names it: a surviving link is a
	 * marker for a later pass, and a marker has to be one string.
	 *
	 * @return array{string,string} Namespace text and dbkey, as OutputName::of() takes them.
	 */
	private function nameFor(Title $title): array {
		if ($title->getNamespace() !== NS_SPECIAL) {
			return [(string)$title->getNsText(), $title->getDBkey()];
		}
		$services = MediaWikiServices::getInstance();
		$canonical = (string)$services->getNamespaceInfo()->getCanonicalName(NS_SPECIAL);
		[$name, $subpage] = $services->getSpecialPageFactory()->resolveAlias($title->getDBkey());
		// A name no special page answers to has no canonical form to be written in; leave it as typed.
		if ($name === null) {
			return [$canonical, $title->getDBkey()];
		}
		return [$canonical, $subpage === null ? $name : "$name/$subpage"];
	}

	/**
	 * Where a search goes in the export: the results page, carrying the term the link asks for
	 * (SifterSearch's widget reads it from "?search="), or nowhere.
	 *
	 * With none named the link is left the button its own script treats it as.
	 */
	private function searchHref(string $query): ?string {
		$results = Search::resultsPage();
		if (!$results) {
			return null;
		}
		$url = OutputName::href(OutputName::of(...$this->nameFor($results)));
		$term = wfCgiToArray($query)['search'] ?? '';
		return $term === '' ? $url : $url . '?' . wfArrayToCgi(['search' => $term]);
	}

	/**
	 * Resolve $wgWikvenLogos -- $wgLogos, but each src is a source-dir file name -- to $wgLogos
	 * proper, once each file's actual upload URL can be asked for.
	 *
	 * Here rather than in WikvenSettings.php, because the service container does not exist yet at
	 * LocalSettings.php time.
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

		// Core's default here is false rather than an array, hence the test.
		$configured = $this->config->get('Logos');
		$logos = is_array($configured) ? $configured : [];
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
	 * Registered at run time because extension.json handlers run in load order, and wikven loads
	 * before the skins.
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
	 * ULSIMEEnabled does not stop the request: ext.uls.interface binds a focus handler whatever it
	 * says. Set here because an empty array reads to ExtensionRegistry as "not set".
	 */
	private function unbindUlsInputMethods(): void {
		if (!$this->config->has('ULSImeSelectors')) {
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
		// A tab of wikven's own, added to the row the skin lays out: chrome, and a preview keeps
		// the row the skin would have drawn. See BuildFor.
		if (BuildFor::skinPreview()) {
			return;
		}
		$viewSourceUrl = (string)$this->config->get('WikvenViewSourceUrl');
		$title = $sktemplate->getTitle();
		// A generated page (e.g. Version) has no source file; skip rather than emit a 404 link.
		if (
			!$viewSourceUrl
			|| !$title
			|| !$title->canExist()
			|| !SourceFile::exists($title->getPrefixedText())
		) {
			return;
		}
		$links['views']['wikven-viewsource'] = [
			// MediaWiki core's existing "View source" label, so it is translated.
			'text' => $sktemplate->msg('viewsource')->text(),
			'href' => str_replace('$1', SourceFile::titleToParam($title->getPrefixedText()), $viewSourceUrl)
		];

		// Citizen draws page actions as icon buttons and drops their labels below desktop width
		// (Pagetools.less), so a tab it has no icon for is a blank box; its icon map is keyed by
		// core's names. Vector 2022 needs none.
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
					$href = AssetFile::locate($this->htmlDirectory, $this->assetDirectory, "$name.css")['href'];
					$tags[$name] = Html::linkedStyle($href);
					$this->addStyleToList($name);
				}
			}
		}

		$title = $out->getTitle();
		if (MW_ENTRY_POINT === 'cli' && $title) {
			$tags = array_merge($tags, $this->addressTags($title, $out->getSkin()->getSkinName()));
		}
	}

	/**
	 * What the head says about this document's own address, and about its translations.
	 *
	 * A whole canonical url settles at once every address a page is served at, but needs to know
	 * where the site is published.
	 *
	 * @param Title $title The page being rendered.
	 * @param string $skin The skin rendering this copy of it.
	 * @return array<string,string>
	 */
	private function addressTags(Title $title, string $skin): array {
		if (!$this->siteUrl->isKnown()) {
			return $this->duplicatedByThisSkin($title, $skin);
		}
		$cluster = $this->cluster($title);
		$tags = [
			'link-canonical' => Html::element('link', [
				'rel' => 'canonical',
				'href' => $cluster['owner']->getFullURL()
			])
		];
		// A set of one is the page saying it is the only language it has, which is what a page
		// with no translations already says by carrying no set at all.
		if (count($cluster['languages']) < 2) {
			return $tags;
		}
		// Every page in the set names the whole set, itself included, or a search engine ignores
		// the set: it reads them as a group only where the group agrees on its own membership.
		foreach ($cluster['languages'] as $code => $page) {
			$tags[self::alternateKey($code)] = self::alternate($code, $page->getFullURL());
		}
		// The address a reader reaches who is not any of these languages. That is the source page:
		// it is the one every link in the export names, and the one a language bar starts from.
		$tags[self::alternateKey('x-default')] = self::alternate('x-default', $cluster['source']->getFullURL());
		return $tags;
	}

	/**
	 * What a page can still say about its address with no whole one to give.
	 *
	 * A skin copy duplicates the main skin's, which is beside it in the export, so it can point at
	 * that from one directory up.
	 *
	 * @return array<string,string>
	 */
	private function duplicatedByThisSkin(Title $title, string $skin): array {
		$mainSkin = (string)$this->config->get('WikvenMainSkin');
		if ($mainSkin === '' || $skin === $mainSkin) {
			return [];
		}
		$href = OutputName::href(OutputName::of(...$this->nameFor($title)));
		return [
			'link-canonical' => Html::element('link', [
				'rel' => 'canonical',
				'href' => "../$href"
			])
		];
	}

	/** Core's own key for one of these, so a variant link and this cannot both claim a language. */
	private static function alternateKey(string $hreflang): string {
		return 'link-alternate-language-' . strtolower($hreflang);
	}

	private static function alternate(string $hreflang, string $href): string {
		return Html::element('link', [
			'rel' => 'alternate',
			'hreflang' => $hreflang === 'x-default' ? $hreflang : LanguageCode::bcp47($hreflang),
			'href' => $href
		]);
	}

	/**
	 * The pages this document shares its content with: which owns it, and one page per language.
	 *
	 * hreflang cannot say a language is at two addresses, so of "Development" and "Development/en"
	 * the source page owns it.
	 *
	 * @return array{owner:Title,source:Title,languages:array<string,Title>}
	 */
	private function cluster(Title $title): array {
		// Before the question below, because this family is the build's own doing: the copies are
		// there whether or not Translate is, and asking Translate about them would answer no.
		$licensed = $this->licensesCluster($title);
		if ($licensed !== null) {
			return $licensed;
		}
		$alone = ['owner' => $title, 'source' => $title, 'languages' => []];
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			return $alone;
		}
		$translation = TranslatablePage::isTranslationPage($title);
		if ($translation !== false) {
			$page = $translation;
		} elseif (TranslatablePage::isSourcePage($title)) {
			$page = TranslatablePage::newFromTitle($title);
		} else {
			return $alone;
		}
		$source = $page->getTitle();
		$sourceKey = $source->getDBkey();
		$sourceLanguage = $page->getSourceLanguageCode();
		$pages = [$sourceKey => $source];
		foreach ($page->getTranslationPages() as $translationPage) {
			$pages[$translationPage->getDBkey()] = $translationPage;
		}
		$owner = TranslationFamily::owner($title->getDBkey(), $sourceKey, $sourceLanguage);
		$languages = [];
		foreach (TranslationFamily::byLanguage($sourceKey, $sourceLanguage, array_keys($pages)) as $code => $key) {
			$languages[$code] = $pages[$key];
		}
		return [
			'owner' => $pages[$owner] ?? $title,
			'source' => $source,
			'languages' => $languages
		];
	}

	/**
	 * The licenses page's family, which is not Translate's.
	 *
	 * The build writes that page and a copy per translated language, so they are ordinary pages
	 * Translate has never heard of. The set of copies is read from the source tree once.
	 *
	 * @return ?array{owner:Title,source:Title,languages:array<string,Title>} Null where the title
	 *   is not part of that family.
	 */
	private function licensesCluster(Title $title): ?array {
		$page = LicensesPage::title();
		if ($page === null) {
			return null;
		}
		$services = MediaWikiServices::getInstance();
		$isKnownLanguage = [$services->getLanguageNameUtils(), 'isKnownLanguageTag'];
		if (LicensesPage::generatedLanguage($title, $isKnownLanguage) === null && !$title->equals($page)) {
			return null;
		}
		// Only the copies that are really there: build.php writes one per language it could translate
		// the page's messages into. An alternate naming a page the export does not have is a set a
		// search engine throws away.
		$this->licensesCopies ??= array_filter(
			LicensesPage::generatedCopies(
				(string)$this->config->get('WikvenSourceDirectory'),
				$isKnownLanguage
			),
			static function (Title $copy): bool {
				return $copy->exists();
			}
		);
		// The page itself is the content language's copy: the build writes it from this wiki's own
		// messages, and asks for a translation of them only for the copies.
		$languages = [$services->getContentLanguage()->getCode() => $page] + $this->licensesCopies;
		return ['owner' => $title, 'source' => $page, 'languages' => $languages];
	}

	private function addStyleToList(string $name): void {
		// Empty outside a build: there is no static site to place the file in.
		if (MW_ENTRY_POINT !== 'cli' || $this->htmlDirectory === '') {
			return;
		}

		$path = AssetFile::path($this->htmlDirectory, $this->assetDirectory);
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
