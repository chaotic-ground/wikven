<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

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
		global $wgWikvenHtmlDirectory, $wgWikvenScriptDirectory, $wgWikvenStyleDirectory;

		$htmlDir = rtrim($wgWikvenHtmlDirectory, '/');
		$prefix = './' . rtrim($wgWikvenScriptDirectory, '/');
		$siteStylesHref = './' . rtrim($wgWikvenStyleDirectory, '/') . '/site.styles.css';
		$hasSiteStyles = is_file("$htmlDir/site.styles.css") && filesize("$htmlDir/site.styles.css") > 0;

		$rl = MediaWikiServices::getInstance()->getResourceLoader();

		// With SifterSearch the static Pagefind bundle keeps the native search box working, so keep it.
		$sifterEnabled = ExtensionRegistry::getInstance()->isLoaded('SifterSearch');

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

			// Stop the startup module from auto-loading anything over the network.
			$html = preg_replace('/RLPAGEMODULES=\[[^\]]*\]/', 'RLPAGEMODULES=[]', $html);

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
				$html = $this->removeElements($html, 'vector-search-box-vue');
				$html = str_replace(' skin-vector-search-vue', '', $html);
			}

			// Remove the appearance menu: its widgets pull codex+vue from load.php, which 404s statically.
			$html = $this->removeElements($html, 'id="vector-appearance"');

			file_put_contents($file, $html, LOCK_EX);
		}
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

	/** Remove every balanced <div ...$marker...>...</div> (nested-aware) from the HTML. */
	private function removeElements(string $html, string $marker): string {
		while (true) {
			$start = $this->findOpeningDiv($html, $marker);
			if ($start === -1) {
				return $html;
			}
			$end = $this->matchingDivEnd($html, $start);
			if ($end === -1) {
				return $html;
			}
			$html = substr($html, 0, $start) . substr($html, $end);
		}
	}

	/**
	 * @return int Byte offset of the opening <div, or -1.
	 */
	private function findOpeningDiv(string $html, string $marker): int {
		$pos = strpos($html, '<div');
		while ($pos !== false) {
			$tagEnd = strpos($html, '>', $pos);
			if ($tagEnd === false) {
				return -1;
			}
			if (str_contains(substr($html, $pos, $tagEnd - $pos), $marker)) {
				return $pos;
			}
			$pos = strpos($html, '<div', $tagEnd + 1);
		}
		return -1;
	}

	/**
	 * Find the end of the <div> opening at byte offset $start.
	 * @return int Byte offset just past the matching </div>, or -1.
	 */
	private function matchingDivEnd(string $html, int $start): int {
		$depth = 0;
		$len = strlen($html);
		$i = $start;
		while ($i < $len) {
			$open = strpos($html, '<div', $i);
			$close = strpos($html, '</div>', $i);
			if ($close === false) {
				return -1;
			}
			if ($open !== false && $open < $close) {
				$depth++;
				$i = $open + 4;
			} else {
				$depth--;
				$i = $close + 6;
				if ($depth === 0) {
					return $i;
				}
			}
		}
		return -1;
	}
}

$maintClass = RewriteScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
