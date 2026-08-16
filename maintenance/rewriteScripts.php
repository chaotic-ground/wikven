<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Rewrite cached HTML so skin JS loads from the static bundle instead of load.php. */
class RewriteScripts extends Maintenance {
	/** Module groups that are not shipped statically (mirrors Main/buildScripts). */
	private const SKIP_GROUPS = ['noscript', 'private', 'user'];

	/** Modules excluded from the bundle, so they must not be triggered either. */
	private const SKIP_MODULES = ['site.styles', 'user', 'user.styles', 'user.options'];

	public function __construct() {
		parent::__construct();
		$this->addDescription('Rewrite cached HTML to load the static JS bundle instead of load.php.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenScriptDirectory, $wgWikvenStyleDirectory, $wgDefaultSkin;

		$htmlDir = rtrim($wgWikvenHtmlDirectory, '/');
		$prefix = './' . rtrim($wgWikvenScriptDirectory, '/');
		$siteStylesHref = './' . rtrim($wgWikvenStyleDirectory, '/') . '/site.styles.css';
		$hasSiteStyles = is_file("$htmlDir/site.styles.css") && filesize("$htmlDir/site.styles.css") > 0;

		// Bundled webfonts (opt-in; bakeWebfonts wrote it): link ahead of site styles so a site can
		// still override the font-family, and let rename's reparenting fix the href on subpages.
		$webfontsHref = './' . rtrim($wgWikvenStyleDirectory, '/') . '/webfonts.css';
		$hasWebfonts = is_file("$htmlDir/webfonts.css") && filesize("$htmlDir/webfonts.css") > 0;

		$rl = MediaWikiServices::getInstance()->getResourceLoader();

		// With SifterSearch the static Pagefind bundle keeps the native search box working, so keep it.
		$sifterEnabled = Search::isActive();
		$isCitizen = $wgDefaultSkin === 'citizen';
		$citizenSearchWorks = Search::hasResultsPage();

		foreach (glob("$htmlDir/*.html") as $file) {
			$html = file_get_contents($file);

			// Modules to re-trigger: page queue minus groups/modules we do not ship.
			$trigger = [];
			if (preg_match('/RLPAGEMODULES=(\[[^\]]*\])/', $html, $m)) {
				$list = json_decode($m[1], true);
				if (is_array($list)) {
					foreach ($list as $name) {
						$module = $rl->getModule($name);
						if (
							$module
							&& !in_array($name, self::SKIP_MODULES, true)
							&& !in_array($module->getGroup(), self::SKIP_GROUPS, true)
						) {
							$trigger[] = $name;
						}
					}
				}
			}

			// Also trigger site JS and default gadgets: bundled but not queued by the static render. Dedupe.
			$trigger[] = 'site';
			$trigger = array_merge($trigger, $this->defaultGadgetModules());
			$trigger = array_values(array_unique($trigger));

			// Stop the startup module from auto-loading anything over the network. Only the first match:
			// the assignment lives in the RLQ script near the top of the page, and the same string can
			// legitimately recur in the article body, e.g. a page documenting this pattern.
			$html = preg_replace('/RLPAGEMODULES=\[[^\]]*\]/', 'RLPAGEMODULES=[]', $html, 1);

			// Swap the async load.php startup tag for the local bundle + trigger.
			$tags =
				'<script src="'
				. $prefix
				. '/startup-static.js"></script>'
				. '<script src="'
				. $prefix
				. '/modules-static.js"></script>'
				. '<script>mw.loader.load('
				. json_encode($trigger)
				. ');</script>';
			$html = preg_replace_callback(
				'#<script async(?:="")? src="[^"]*\bmodules=startup\b[^"]*"></script>#',
				static function (array $unused) use ($tags) {
					return $tags;
				},
				$html
			);

			// Drop the redundant combined load.php stylesheet link.
			$html = preg_replace(
				'#<link rel="stylesheet" href="[^"]*load\.php\?[^"]*only=styles[^"]*">#',
				'',
				$html
			);

			// Link the bundled webfonts, before the site styles injected below.
			if ($hasWebfonts) {
				$html = str_replace(
					'</head>',
					'<link rel="stylesheet" href="' . $webfontsHref . '"></head>',
					$html
				);
			}

			// Re-link the site styles last (their own file) so they win the cascade over the skin defaults.
			if ($hasSiteStyles) {
				$html = str_replace(
					'</head>',
					'<link rel="stylesheet" href="' . $siteStylesHref . '"></head>',
					$html
				);
			}

			// No logo configured: neutralize the placeholder asset reference so it does not 404.
			$html = preg_replace(
				'#(["\'(])[^"\')]*change-your-logo[^"\')]*\.svg#',
				'$1data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E',
				$html
			);

			// Without SifterSearch, drop the search boxes and body class so nothing mounts or fetches.
			if (!$sifterEnabled) {
				$html = HtmlElementRemover::remove($html, static function ($unusedName, array $attrs) {
					$classes = isset($attrs['class']) ? preg_split('/\s+/', trim($attrs['class'])) : [];
					return in_array('vector-search-box-vue', $classes, true);
				});
				$html = str_replace(' skin-vector-search-vue', '', $html);
			}

			if ($isCitizen) {
				$html = $this->citizenSearch($html, $citizenSearchWorks);
			}

			file_put_contents($file, $html, LOCK_EX);
		}
	}

	/**
	 * Leave Citizen with the search its no-JS fallback gives it, or with none at all.
	 *
	 * Citizen's own search is a command palette backed by the REST API, which an export has no
	 * server for. Underneath it the skin renders an ordinary search form, meant for readers without
	 * JavaScript, and that one works: SifterSearch's ext.sifter.retarget points it at the static
	 * results page. commandPalette.js deletes that form the moment it finds its trigger by id, so
	 * dropping the id is what leaves the working search standing. Where the form leads nowhere --
	 * no SifterSearch, or no results page for it to be retargeted at, Citizen having no typeahead
	 * to carry the submit instead -- the whole box goes, as Vector's does above.
	 */
	private function citizenSearch(string $html, bool $searchWorks): string {
		if ($searchWorks) {
			return str_replace('id="citizen-search-summary"', '', $html);
		}
		return HtmlElementRemover::remove($html, static function ($unusedName, array $attrs) {
			$classes = isset($attrs['class']) ? preg_split('/\s+/', trim($attrs['class'])) : [];
			return in_array('citizen-search', $classes, true);
		});
	}

	/**
	 * @return string[] Module names of default-on gadgets, or empty when Gadgets is not loaded.
	 */
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
}

$maintClass = RewriteScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
