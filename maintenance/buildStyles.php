<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\Output\AssetFile;
use MediaWiki\Extension\Wikven\Output\AssetLocalizer;
use MediaWiki\Extension\Wikven\Output\DirectionalContext;
use MediaWiki\Extension\Wikven\Output\ModuleRenderer;
use MediaWiki\Extension\Wikven\Output\Stylesheet;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

class BuildStyles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Build styles based on the CSS files in $wgWikvenAssetDirectory.');
	}

	public function execute() {
		$config = $this->getConfig();
		$languageCode = (string)$config->get('LanguageCode');
		$defaultSkin = (string)$config->get('DefaultSkin');

		$cssDir = AssetFile::path(
			(string)$config->get('WikvenHtmlDirectory'),
			(string)$config->get('WikvenAssetDirectory')
		);

		$services = MediaWikiServices::getInstance();
		$services->getDBLoadBalancerFactory()->disableChronologyProtection();

		$resourceLoader = $services->getResourceLoader();
		$isModule = [$resourceLoader, 'isModuleRegistered'];

		// Directions the pages linked a stylesheet in, beyond the site language's own; see Stylesheet.
		$directions = [];
		foreach (glob("$cssDir/*.css") as $filename) {
			$stylesheet = Stylesheet::module($filename, $isModule);
			$this->render($resourceLoader, $filename, $stylesheet['module'], $stylesheet['direction']);
			if ($stylesheet['direction'] !== null) {
				$directions[$stylesheet['direction']] = true;
			}
		}

		// Render site.styles to its own file so rewriteScripts can link it, once per direction a page
		// is read in; skip if empty.
		$siteDirection = $services->getContentLanguage()->getDir();
		foreach ([null, ...array_keys($directions)] as $direction) {
			$name = Stylesheet::fileName('site.styles', $direction ?? $siteDirection, $siteDirection);
			$this->render($resourceLoader, "$cssDir/$name", 'site.styles', $direction, true);
		}

		// Dumped CSS points icons at load.php images that 404 on static hosts; localize them. One
		// direction at a time, the site's first, so a flipped copy reuses the icons it did not flip.
		$byDirection = ['' => []];
		foreach (glob("$cssDir/*.css") as $filename) {
			$byDirection[Stylesheet::module($filename, $isModule)['direction'] ?? ''][] = $filename;
		}
		foreach ($byDirection as $direction => $filenames) {
			AssetLocalizer::localizeAssets(
				$resourceLoader,
				$cssDir,
				$filenames,
				$languageCode,
				$defaultSkin,
				false,
				$direction === '' ? null : $direction
			);
		}
	}

	/**
	 * Render one module's styles into a file.
	 *
	 * @param ResourceLoader $resourceLoader
	 * @param string $filename Where the styles go.
	 * @param string $module The module whose styles they are.
	 * @param string|null $direction "ltr" or "rtl" to render in, or null for the site language's own.
	 * @param bool $skipEmpty Write nothing when the module has no styles.
	 */
	private function render(
		ResourceLoader $resourceLoader,
		string $filename,
		string $module,
		?string $direction,
		bool $skipEmpty = false
	): void {
		$config = $this->getConfig();
		$query = ResourceLoader::makeLoaderQuery(
			[$module],
			(string)$config->get('LanguageCode'),
			(string)$config->get('DefaultSkin'),
			// user
			null,
			// version; not relevant
			null,
			// inDebugMode
			Context::DEBUG_OFF,
			// only
			'styles'
		);
		$request = new FauxRequest($query);
		$context = $direction === null
			? new Context($resourceLoader, $request)
			: new DirectionalContext($resourceLoader, $request, $direction);

		$text = ModuleRenderer::render($resourceLoader, $context);
		if ($skipEmpty && trim($text) === '') {
			return;
		}

		$problem = Stylesheet::write($filename, $text);
		if ($problem !== null) {
			$this->fatalError($problem);
		}
	}
}

$maintClass = BuildStyles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
