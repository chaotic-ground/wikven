<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval( getenv( 'MW_INSTALL_PATH' ) ) !== ''
	? getenv( 'MW_INSTALL_PATH' )
	: realpath( __DIR__ . '/../../../' );

require_once "$IP/maintenance/Maintenance.php";

/**
 * Rewrite the cached HTML so the skin JavaScript loads from the static bundle
 * produced by buildScripts.php instead of from load.php (which does not exist
 * on a static host). For each page this:
 *
 *   - empties the inline RLPAGEMODULES queue,
 *   - replaces the async load.php startup <script> with local startup-static.js
 *     + modules-static.js + an explicit mw.loader.load() of the page modules,
 *   - drops the leftover combined load.php stylesheet link (the per-module local
 *     <link>s emitted by the Main hook already cover those styles).
 */
class RewriteScripts extends Maintenance {
	/** Module groups that are not shipped statically (mirrors Main/buildScripts). */
	private const SKIP_GROUPS = [ 'site', 'noscript', 'private', 'user' ];

	/** Modules excluded from the bundle, so they must not be triggered either. */
	private const SKIP_MODULES = [ 'site', 'site.styles', 'user', 'user.styles', 'user.options' ];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rewrite cached HTML to load the static JS bundle instead of load.php.' );
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenScriptDirectory;

		$htmlDir = rtrim( $wgWikvenHtmlDirectory, '/' );
		$prefix = './' . rtrim( $wgWikvenScriptDirectory, '/' );

		$rl = MediaWikiServices::getInstance()->getResourceLoader();

		foreach ( glob( "$htmlDir/*.html" ) as $file ) {
			$html = file_get_contents( $file );

			// The modules to re-trigger: the page's own queue minus the groups we do
			// not ship, so we never load() a module that is not in the bundle.
			$trigger = [];
			if ( preg_match( '/RLPAGEMODULES=(\[[^\]]*\])/', $html, $m ) ) {
				$list = json_decode( $m[1], true );
				if ( is_array( $list ) ) {
					foreach ( $list as $name ) {
						$module = $rl->getModule( $name );
						if ( $module
							&& !in_array( $name, self::SKIP_MODULES, true )
							&& !in_array( $module->getGroup(), self::SKIP_GROUPS, true )
						) {
							$trigger[] = $name;
						}
					}
				}
			}

			// Stop the startup module from auto-loading anything over the network.
			$html = preg_replace( '/RLPAGEMODULES=\[[^\]]*\]/', 'RLPAGEMODULES=[]', $html );

			// Swap the async load.php startup tag for the local bundle + trigger.
			$tags = '<script src="' . $prefix . '/startup-static.js"></script>'
				. '<script src="' . $prefix . '/modules-static.js"></script>'
				. '<script>mw.loader.load(' . json_encode( $trigger ) . ');</script>';
			$html = preg_replace_callback(
				'#<script async(?:="")? src="[^"]*\bmodules=startup\b[^"]*"></script>#',
				static function () use ( $tags ) {
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

			file_put_contents( $file, $html, LOCK_EX );
		}
	}
}

$maintClass = RewriteScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
