<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
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

	public function __construct() {
		parent::__construct();
		$this->addDescription('Dump the static JS bundle (startup + module closure) for the generated pages.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenScriptDirectory, $wgLanguageCode, $wgDefaultSkin;

		$htmlDir = rtrim($wgWikvenHtmlDirectory, '/');
		$outDir = $htmlDir . '/' . rtrim($wgWikvenScriptDirectory, '/');
		if (!is_dir($outDir)) {
			mkdir($outDir, 0777, true);
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
		// Seed the lazy search module (loaded on focus, never in page queue) so its closure bundles.
		if (Search::isActive()) {
			$searchModule = $this->resolveSearchModule($rl, $wgLanguageCode, $wgDefaultSkin);
			if ($searchModule !== null && $rl->isModuleRegistered($searchModule)) {
				$seeds[] = $searchModule;
			}
		}
		// 2. Expand to the full dependency closure, plus the implicit base modules.
		$closure = $this->resolveClosure($rl, $seeds, $wgLanguageCode, $wgDefaultSkin);

		// 3. Dump startup; strip its RLPAGEMODULES auto-load (would 404 fetching base from load.php).
		$startup = $this->dump($rl, ['startup'], $wgLanguageCode, $wgDefaultSkin, 'scripts', ['raw' => '1']);
		$startup = str_replace('mw.loader.load(window.RLPAGEMODULES||[]);', '', $startup);
		file_put_contents("$outDir/startup-static.js", $startup, LOCK_EX);

		// 4. Dump the closure in combined mode so every module self-executes.
		$bundle = $this->dump($rl, $closure, $wgLanguageCode, $wgDefaultSkin, null, []);
		file_put_contents("$outDir/modules-static.js", $bundle, LOCK_EX);

		// Combined bundle embeds icon CSS pointing at load.php images; localize them to local files.
		AssetLocalizer::localizeImages(
			$rl,
			$outDir,
			["$outDir/modules-static.js", "$outDir/startup-static.js"],
			$wgLanguageCode,
			$wgDefaultSkin
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
	private function resolveSearchModule(ResourceLoader $rl, string $lang, string $skin): ?string {
		$query = ResourceLoader::makeLoaderQuery([], $lang, $skin, null, null, Context::DEBUG_OFF, null);
		$context = new Context($rl, new FauxRequest($query));
		$config = ['search' => true, 'searchModule' => 'mediawiki.searchSuggest'];
		( new HookRunner(MediaWikiServices::getInstance()->getHookContainer()) )->onSkinPageReadyConfig(
			$context,
			$config
		);
		return $config['search'] ?? false ? $config['searchModule'] ?? null : null;
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
		ob_start();
		$rl->respond($context);
		return ob_get_clean();
	}
}

$maintClass = BuildScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
