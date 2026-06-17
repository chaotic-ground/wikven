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

			ob_start();
			$resourceLoader->respond($context);
			$text = ob_get_clean();

			if (file_put_contents($filename, $text, LOCK_EX) === false) {
				wfDebug(__METHOD__ . '() failed saving ' . $filename);
				continue;
			}
		}

		$cssDir = "$wgWikvenHtmlDirectory/$wgWikvenStyleDirectory";

		// MediaWiki batches the site styles (MediaWiki:Common.css and the skin's
		// site CSS) into a single load.php?only=styles link that the static export
		// drops. Render that module to its own file so rewriteScripts can link it;
		// an empty result (no MediaWiki:Common.css) is left out.
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
		ob_start();
		$resourceLoader->respond($context);
		$siteStyles = ob_get_clean();
		if (trim($siteStyles) !== '') {
			file_put_contents("$cssDir/site.styles.css", $siteStyles, LOCK_EX);
		}

		// The dumped CSS still points icon background-images at the load.php image
		// endpoint, which 404s on a static host. Dump each referenced image to a
		// local file and rewrite the url() to it.
		AssetLocalizer::localizeImages(
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
