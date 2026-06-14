<?php

namespace MediaWiki\Extension\Wikven;

use FauxRequest;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Dump the JavaScript needed by the generated pages into two static files so
 * the skin's JS runs without a load.php server:
 *
 *   startup-static.js  the startup module (mw.loader + the register manifest),
 *                      with its trailing auto-load call removed so it does not
 *                      try to fetch the base modules over the network.
 *   modules-static.js  the full dependency closure of the page modules dumped
 *                      in combined mode, so every module is wrapped in a
 *                      self-executing mw.loader.impl() call.
 *
 * This is the JS analogue of buildStyles.php. Unlike CSS, an only=scripts dump
 * is inert (it force-marks modules 'ready', which skips execution), so the
 * closure must be dumped in combined mode and the base modules (jquery,
 * mediawiki.base) must be included explicitly.
 */
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
		// Gadgets registers its modules into the ResourceLoader at boot, before the
		// single build process has imported the gadget definitions, so the modules
		// are missing here. Re-register them from the (now populated) gadget repo.
		$this->registerGadgetModules($rl);
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->disableChronologyProtection();

		// 1. Discover the modules the rendered pages actually queue.
		$seeds = $this->collectPageModules($htmlDir);
		// The site module (MediaWiki:Common.js plus the skin's JS) is pulled in by
		// the startup module on a live wiki, so it never appears in a page's module
		// queue. Seed it explicitly so that site JS runs on the static pages too.
		$seeds[] = 'site';
		// Likewise the gadgets enabled for every reader: the Gadgets hook adds them
		// at request time, which the static render does not reproduce, so seed them
		// so default gadgets are bundled too.
		$seeds = array_merge($seeds, $this->defaultGadgetModules());
		// 2. Expand to the full dependency closure, plus the implicit base modules.
		$closure = $this->resolveClosure($rl, $seeds, $wgLanguageCode, $wgDefaultSkin);

		// 3. Dump startup and neutralise its auto-load of RLPAGEMODULES (which would
		//    otherwise fetch the base modules from load.php and 404 on a static host).
		$startup = $this->dump($rl, ['startup'], $wgLanguageCode, $wgDefaultSkin, 'scripts', ['raw' => '1']);
		$startup = str_replace('mw.loader.load(window.RLPAGEMODULES||[]);', '', $startup);
		file_put_contents("$outDir/startup-static.js", $startup, LOCK_EX);

		// 4. Dump the closure in combined mode so every module self-executes.
		$bundle = $this->dump($rl, $closure, $wgLanguageCode, $wgDefaultSkin, null, []);
		file_put_contents("$outDir/modules-static.js", $bundle, LOCK_EX);

		// The combined bundle embeds icon-module CSS that still points at the
		// load.php image endpoint; localize those references to local files too.
		AssetLocalizer::localizeImages(
			$rl,
			$outDir,
			["$outDir/modules-static.js", "$outDir/startup-static.js"],
			$wgLanguageCode,
			$wgDefaultSkin
		);

		$this->output('Wrote startup-static.js and modules-static.js (' . count($closure) . " modules)\n");
	}

	/**
	 * Register every gadget's ResourceLoader module on the given loader, mirroring
	 * the Gadgets extension's own registration. No-op without the extension.
	 *
	 * @param ResourceLoader $rl
	 */
	private function registerGadgetModules(ResourceLoader $rl) {
		$repoClass = 'MediaWiki\\Extension\\Gadgets\\GadgetRepo';
		if (!class_exists($repoClass)) {
			return;
		}
		$repo = $repoClass::singleton();
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

	/**
	 * @return string[] Module names of the gadgets enabled for every reader (so
	 *   they can be bundled like the page's own modules), or an empty array when
	 *   the Gadgets extension is not loaded.
	 */
	private function defaultGadgetModules() {
		$repoClass = 'MediaWiki\\Extension\\Gadgets\\GadgetRepo';
		if (!class_exists($repoClass)) {
			return [];
		}
		$modules = [];
		$repo = $repoClass::singleton();
		foreach ($repo->getGadgetIds() as $id) {
			$gadget = $repo->getGadget($id);
			// Styles-only gadgets belong in the CSS dump, not the JS bundle.
			if ($gadget->isOnByDefault() && $gadget->hasModule() && $gadget->getType() !== 'styles') {
				$modules[] = \MediaWiki\Extension\Gadgets\Gadget::getModuleName($id);
			}
		}
		return $modules;
	}

	/**
	 * @param string $htmlDir
	 * @return string[] The union of the RLPAGEMODULES lists across all pages.
	 */
	private function collectPageModules($htmlDir) {
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

	/**
	 * @param ResourceLoader $rl
	 * @param string[] $seeds
	 * @param string $lang
	 * @param string $skin
	 * @return string[] The full dependency closure, including the base modules.
	 */
	private function resolveClosure(ResourceLoader $rl, array $seeds, $lang, $skin) {
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

		// jquery and mediawiki.base are implicit base modules; they never appear in
		// getDependencies() but mw.loader blocks every module until they are ready.
		$resolved['jquery'] = true;
		$resolved['mediawiki.base'] = true;
		return array_keys($resolved);
	}

	/**
	 * @param ResourceLoader $rl
	 * @param string[] $modules
	 * @param string $lang
	 * @param string $skin
	 * @param string|null $only
	 * @param array $extra
	 * @return string
	 */
	private function dump(ResourceLoader $rl, array $modules, $lang, $skin, $only, array $extra) {
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
