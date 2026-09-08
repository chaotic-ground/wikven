<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\Output\AssetFile;
use MediaWiki\Extension\Wikven\Output\AssetLocalizer;
use MediaWiki\Extension\Wikven\Output\LazyModules;
use MediaWiki\Extension\Wikven\Output\ModuleRenderer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Dump page JS (startup + module closure) to static files so the skin runs without load.php. */
class BuildScripts extends Maintenance {
	/** Module groups that are never emitted statically (mirrors Main/buildStyles). */
	private const SKIP_GROUPS = ['noscript', 'private', 'user'];

	/** Per-user / styles-only modules that have no place in the static JS bundle. */
	private const SKIP_MODULES = ['site.styles', 'user', 'user.styles', 'user.options'];

	/**
	 * Modules pulled in at run time rather than queued by the page that needs them, so nothing in
	 * the rendered HTML names them. Unseeded, the browser asks load.php and gets a 404.
	 */
	private const RUNTIME_MODULES = ['ext.tabberNeue.icons'];

	/** The bundle's own module that carries what onDemand() holds back. Registered by its impl. */
	private const ON_DEMAND_MODULE = 'ext.Wikven.onDemand';

	public function __construct() {
		parent::__construct();
		$this->addDescription('Dump the static JS bundle (startup + module closure) for the generated pages.');
	}

	public function execute() {
		$config = $this->getConfig();
		$languageCode = (string)$config->get('LanguageCode');
		$defaultSkin = (string)$config->get('DefaultSkin');

		$htmlDir = rtrim((string)$config->get('WikvenHtmlDirectory'), '/');
		$outDir = AssetFile::path($htmlDir, (string)$config->get('WikvenAssetDirectory'));
		if (!wfMkdirParents($outDir, null, __METHOD__)) {
			$this->fatalError("Could not create asset directory $outDir");
		}

		$rl = MediaWikiServices::getInstance()->getResourceLoader();
		// Gadgets registers modules at boot, before this build imported defs; re-register them here.
		$this->registerGadgetModules($rl);
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->disableChronologyProtection();

		// 1. Discover the modules the rendered pages actually queue.
		$seeds = $this->collectPageModules($htmlDir);
		// Seed 'site' (Common.js + skin JS); startup pulls it live so it is never in a page queue.
		$seeds[] = 'site';
		// Seed default-on gadgets; the Gadgets hook adds them per-request, not in static render.
		$seeds = array_merge($seeds, $this->defaultGadgetModules());
		$readyConfig = $this->readyConfig($rl, $languageCode, $defaultSkin);
		// Seed the lazy search module (loaded on focus, never in page queue) so its closure bundles.
		if (Search::isActive()) {
			$searchModule = $this->resolveSearchModule($readyConfig);
			if ($searchModule !== null && $rl->isModuleRegistered($searchModule)) {
				$seeds[] = $searchModule;
			}
		}
		// Same for the modules core decides on by looking at the rendered page rather than by
		// queueing them, which is collapsibles and sortable tables. See LazyModules.
		$seeds = array_merge($seeds, $this->collectLazyModules($htmlDir, $readyConfig));
		// Citizen's preferences panel is no seed: a wiki serves it once the dropdown opens, after all
		// the page queued, and the bundle keeps it there (onDemand()). Unseeded it reports "Couldn't
		// load preferences"; Vue and Codex come with it.
		$onDemand = [];
		$preferences = 'skins.citizen.preferences';
		if (
			$defaultSkin === 'citizen'
			&& $config->get('CitizenEnablePreferences')
			&& $rl->isModuleRegistered($preferences)
		) {
			$onDemand[] = $preferences;
		}
		foreach (self::RUNTIME_MODULES as $runtimeModule) {
			if ($rl->isModuleRegistered($runtimeModule)) {
				$seeds[] = $runtimeModule;
			}
		}
		// 2. Expand to the full dependency closure, plus the implicit base modules.
		$closure = $this->resolveClosure($rl, $seeds, $languageCode, $defaultSkin);

		// 3. Dump startup; strip its RLPAGEMODULES auto-load (would 404 fetching base from load.php).
		$startup = $this->dump($rl, ['startup'], $languageCode, $defaultSkin, 'scripts', ['raw' => '1']);
		$startup = str_replace('mw.loader.load(window.RLPAGEMODULES||[]);', '', $startup);
		file_put_contents("$outDir/startup-static.js", $startup, LOCK_EX);

		// 4. Dump the closure in combined mode so every module self-executes.
		$bundle =
			$this->dump($rl, $closure, $languageCode, $defaultSkin, null, [])
			. $this->onDemand($rl, $onDemand, $closure, $languageCode, $defaultSkin);
		file_put_contents("$outDir/modules-static.js", $bundle, LOCK_EX);

		// Combined bundle embeds icon CSS pointing at load.php images. The bundle injects its CSS into
		// the document, so inline the images as data: URIs to load from any page depth.
		AssetLocalizer::localizeAssets(
			$rl,
			$outDir,
			["$outDir/modules-static.js", "$outDir/startup-static.js"],
			$languageCode,
			$defaultSkin,
			true
		);

		$this->output('Wrote startup-static.js and modules-static.js (' . count($closure) . " modules)\n");
	}

	/** Register each gadget's RL module, mirroring the Gadgets extension. No-op if absent. */
	private function registerGadgetModules(ResourceLoader $rl): void {
		$repoClass = 'MediaWiki\\Extension\\Gadgets\\GadgetRepo';
		if (!class_exists($repoClass)) {
			return;
		}
		/** @var \MediaWiki\Extension\Gadgets\GadgetRepo $repo */
		$repo = MediaWikiServices::getInstance()->getService('GadgetsRepo');
		foreach ($repo->getGadgetIds() as $id) {
			$name = \MediaWiki\Extension\Gadgets\Gadget::getModuleName($id);
			if (!$rl->isModuleRegistered($name)) {
				$rl->register($name, [
					'class' => \MediaWiki\Extension\Gadgets\GadgetResourceLoaderModule::class,
					'id' => $id
				]);
			}
		}
	}

	/** @return string[] Module names of default-on gadgets, or [] without the Gadgets extension. */
	private function defaultGadgetModules(): array {
		$repoClass = 'MediaWiki\\Extension\\Gadgets\\GadgetRepo';
		if (!class_exists($repoClass)) {
			return [];
		}
		$modules = [];
		/** @var \MediaWiki\Extension\Gadgets\GadgetRepo $repo */
		$repo = MediaWikiServices::getInstance()->getService('GadgetsRepo');
		foreach ($repo->getGadgetIds() as $id) {
			$gadget = $repo->getGadget($id);
			// Styles-only gadgets belong in the CSS dump, not the JS bundle.
			if ($gadget->isOnByDefault() && $gadget->hasModule() && $gadget->getType() !== 'styles') {
				$modules[] = \MediaWiki\Extension\Gadgets\Gadget::getModuleName($id);
			}
		}
		return $modules;
	}

	/** @return string[] The union of the RLPAGEMODULES lists across all pages. */
	private function collectPageModules(string $htmlDir): array {
		$modules = [];
		foreach (glob("$htmlDir/*.html") as $file) {
			$html = file_get_contents($file);
			if (preg_match('/RLPAGEMODULES=(\[[^\]]*\])/', $html, $m)) {
				$list = json_decode($m[1], true);
				if (is_array($list)) {
					foreach ($list as $name) {
						$modules[$name] = true;
					}
				}
			}
		}
		return array_keys($modules);
	}

	/** @return string|null Search module page.ready lazy-loads on focus, or null if search is off. */
	private function resolveSearchModule(array $readyConfig): ?string {
		return $readyConfig['search'] ?? false ? $readyConfig['searchModule'] ?? null : null;
	}

	/**
	 * mediawiki.page.ready's own configuration, which decides what it will go looking for.
	 *
	 * Read rather than assumed, so a site that switches a feature off gets the same answer from a
	 * bake as from a wiki.
	 */
	private function readyConfig(ResourceLoader $rl, string $lang, string $skin): array {
		$query = ResourceLoader::makeLoaderQuery([], $lang, $skin, null, null, Context::DEBUG_OFF, null);
		$context = new Context($rl, new FauxRequest($query));
		$config = [
			'search' => true,
			'searchModule' => 'mediawiki.searchSuggest',
			'collapsible' => true,
			'sortable' => true,
			'selectorLogoutLink' => '#pt-logout a[data-mw-interface]'
		];
		( new HookRunner(MediaWikiServices::getInstance()->getHookContainer()) )->onSkinPageReadyConfig(
			$context,
			$config
		);
		return $config;
	}

	/**
	 * The lazy modules any rendered page needs, from the same pass over the same HTML.
	 *
	 * Top-level pages only: a translation is rendered from the source page's wikitext, and scanning
	 * deeper would reach the per-skin copies.
	 *
	 * @return string[]
	 */
	private function collectLazyModules(string $htmlDir, array $readyConfig): array {
		$modules = [];
		foreach (glob("$htmlDir/*.html") as $file) {
			foreach (LazyModules::forPage(file_get_contents($file), $readyConfig) as $module) {
				$modules[$module] = true;
			}
		}
		return array_keys($modules);
	}

	/** @return string[] The full dependency closure, including the base modules. */
	private function resolveClosure(ResourceLoader $rl, array $seeds, string $lang, string $skin): array {
		$query = ResourceLoader::makeLoaderQuery([], $lang, $skin, null, null, Context::DEBUG_OFF, null);
		$context = new Context($rl, new FauxRequest($query));

		$resolved = [];
		$queue = $seeds;
		while ($queue) {
			$name = array_shift($queue);
			if (isset($resolved[$name])) {
				continue;
			}
			$module = $rl->getModule($name);
			if (
				!$module
				|| in_array($module->getGroup(), self::SKIP_GROUPS, true)
				|| in_array($name, self::SKIP_MODULES, true)
			) {
				continue;
			}
			$resolved[$name] = true;
			foreach ($module->getDependencies($context) as $dep) {
				if (!isset($resolved[$dep])) {
					$queue[] = $dep;
				}
			}
		}

		// jquery/mediawiki.base are implicit base modules: absent from getDependencies() but required.
		$resolved['jquery'] = true;
		$resolved['mediawiki.base'] = true;
		return array_keys($resolved);
	}

	/**
	 * Modules a reader's own action loads, held back until the page's have run.
	 *
	 * Seeded like the rest they ran early, and ULS's later copy of Codex undid the radio spacing
	 * Citizen's panel restates at equal specificity.
	 *
	 * @param ResourceLoader $rl
	 * @param string[] $modules The modules to hold back.
	 * @param string[] $closure The page's own closure, already dumped.
	 * @param string $lang
	 * @param string $skin
	 * @return string JavaScript to append to the bundle; '' when nothing is left to hold back.
	 */
	private function onDemand(
		ResourceLoader $rl,
		array $modules,
		array $closure,
		string $lang,
		string $skin
	): string {
		$later = array_values(array_diff($this->resolveClosure($rl, $modules, $lang, $skin), $closure));
		if ($later === []) {
			return '';
		}
		// mw.loader.using is mediawiki.base's, which has not run when the bundle does. A module of the
		// bundle's own executes once the base modules have; its callback fires whether the page's
		// modules ran or failed, as a wiki serves these either way.
		return (
			"\nmw.loader.impl(function(){return["
			. json_encode(self::ON_DEMAND_MODULE)
			. ',function(){var run=function(){'
			. $this->dump($rl, $later, $lang, $skin, null, [])
			. '};mw.loader.using('
			. json_encode($closure)
			. ',run,run);}];});'
			. "\n"
		);
	}

	private function dump(
		ResourceLoader $rl,
		array $modules,
		string $lang,
		string $skin,
		?string $only,
		array $extra
	): string {
		$query = ResourceLoader::makeLoaderQuery(
			$modules,
			$lang,
			$skin,
			null,
			null,
			Context::DEBUG_OFF,
			$only,
			false,
			null,
			$extra
		);
		$context = new Context($rl, new FauxRequest($query));
		return ModuleRenderer::render($rl, $context);
	}
}

$maintClass = BuildScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
