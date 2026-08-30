<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
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
		$this->addDescription('Build styles based on the CSS files on $wgWikvenStyleDirectory.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenStyleDirectory, $wgLanguageCode, $wgDefaultSkin;

		if (str_ends_with($wgWikvenHtmlDirectory, '/')) {
			$wgWikvenHtmlDirectory = rtrim($wgWikvenHtmlDirectory, '/');
		}
		if (str_ends_with($wgWikvenStyleDirectory, '/')) {
			$wgWikvenStyleDirectory = rtrim($wgWikvenStyleDirectory, '/');
		}

		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->disableChronologyProtection();

		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();

		foreach (glob("$wgWikvenHtmlDirectory/$wgWikvenStyleDirectory/*.css") as $filename) {
			$query = ResourceLoader::makeLoaderQuery(
				[basename($filename, '.css')],
				$wgLanguageCode,
				$wgDefaultSkin,
				// user
				null,
				// version; not relevant
				null,
				// inDebugMode
				Context::DEBUG_OFF,
				// only
				'styles'
			);

			$context = new Context(
				$resourceLoader,
				new FauxRequest($query)
			);

			$text = ModuleRenderer::render($resourceLoader, $context);

			$problem = Stylesheet::write($filename, $text);
			if ($problem !== null) {
				$this->fatalError($problem);
			}
		}

		$cssDir = "$wgWikvenHtmlDirectory/$wgWikvenStyleDirectory";

		// Render site.styles to its own file so rewriteScripts can link it; skip if empty.
		$query = ResourceLoader::makeLoaderQuery(
			['site.styles'],
			$wgLanguageCode,
			$wgDefaultSkin,
			// user
			null,
			// version
			null,
			// inDebugMode
			Context::DEBUG_OFF,
			// only
			'styles'
		);
		$context = new Context($resourceLoader, new FauxRequest($query));
		$siteStyles = ModuleRenderer::render($resourceLoader, $context);
		if (trim($siteStyles) !== '') {
			$problem = Stylesheet::write("$cssDir/site.styles.css", $siteStyles);
			if ($problem !== null) {
				$this->fatalError($problem);
			}
		}

		// Dumped CSS points icons at load.php images that 404 on static hosts; localize them.
		AssetLocalizer::localizeAssets(
			$resourceLoader,
			$cssDir,
			glob("$cssDir/*.css"),
			$wgLanguageCode,
			$wgDefaultSkin
		);
	}
}

$maintClass = BuildStyles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
