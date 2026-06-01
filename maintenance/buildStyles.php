<?php

namespace MediaWiki\Extension\Wikven;

use FauxRequest;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

$IP = strval( getenv( 'MW_INSTALL_PATH' ) ) !== ''
	? getenv( 'MW_INSTALL_PATH' )
	: realpath( __DIR__ . '/../../../' );

require_once "$IP/maintenance/Maintenance.php";

class BuildStyles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Build styles based on the CSS files on $wgWikvenStyleDirectory.' );
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenStyleDirectory, $wgLanguageCode, $wgDefaultSkin;

		if ( str_ends_with( $wgWikvenHtmlDirectory, '/' ) ) {
			$wgWikvenHtmlDirectory = rtrim( $wgWikvenHtmlDirectory, '/' );
		}
		if ( str_ends_with( $wgWikvenStyleDirectory, '/' ) ) {
			$wgWikvenStyleDirectory = rtrim( $wgWikvenStyleDirectory, '/' );
		}

		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->disableChronologyProtection();

		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();

		foreach ( glob( "$wgWikvenHtmlDirectory/$wgWikvenStyleDirectory/*.css" ) as $filename ) {
			$query = ResourceLoader::makeLoaderQuery(
				[ basename( $filename, '.css' ) ],
				$wgLanguageCode,
				$wgDefaultSkin,
				// user
				null,
				// version; not relevant
				null,
				// inDebugMode
				null,
				// only
				'styles',
			);

			$context = new Context(
				$resourceLoader,
				new FauxRequest( $query )
			);

			ob_start();
			$resourceLoader->respond( $context );
			$text = ob_get_clean();

			if ( file_put_contents( $filename, $text, LOCK_EX ) === false ) {
				wfDebug( __METHOD__ . '() failed saving ' . $filename );
				continue;
			}
		}
	}
}

$maintClass = BuildStyles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
