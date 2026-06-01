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

			// No logo is configured, so the placeholder "change your logo" asset
			// would 404. Neutralize its reference (the logo itself is CSS-hidden).
			$html = preg_replace(
				'#(["\'(])[^"\')]*change-your-logo[^"\')]*\.svg#',
				'$1data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E',
				$html
			);

			// Search cannot work on a static host (it needs the API), and the JS
			// search widget lazily fetches codex/vue from load.php. Drop the search
			// boxes, and the skin-vector-search-vue body class that makes the sticky
			// header load the search module, so nothing mounts and nothing is fetched.
			$html = $this->removeElements( $html, 'vector-search-box-vue' );
			$html = str_replace( ' skin-vector-search-vue', '', $html );

			// The appearance menu (dark mode / width) pulls in codex+vue from
			// load.php for its widgets, which 404s and cannot work statically.
			// Remove it so it stops fetching.
			$html = $this->removeElements( $html, 'id="vector-appearance"' );

			file_put_contents( $file, $html, LOCK_EX );
		}
	}

	/**
	 * Remove every balanced <div ...$marker...>...</div> from the HTML, where
	 * $marker is a substring of the opening tag. Handles nested <div>s.
	 *
	 * @param string $html
	 * @param string $marker
	 * @return string
	 */
	private function removeElements( $html, $marker ) {
		while ( true ) {
			$start = $this->findOpeningDiv( $html, $marker );
			if ( $start === -1 ) {
				return $html;
			}
			$end = $this->matchingDivEnd( $html, $start );
			if ( $end === -1 ) {
				return $html;
			}
			$html = substr( $html, 0, $start ) . substr( $html, $end );
		}
	}

	/**
	 * @param string $html
	 * @param string $marker
	 * @return int Byte offset of the opening <div, or -1.
	 */
	private function findOpeningDiv( $html, $marker ) {
		$offset = 0;
		while ( ( $pos = strpos( $html, '<div', $offset ) ) !== false ) {
			$tagEnd = strpos( $html, '>', $pos );
			if ( $tagEnd === false ) {
				return -1;
			}
			if ( strpos( substr( $html, $pos, $tagEnd - $pos ), $marker ) !== false ) {
				return $pos;
			}
			$offset = $tagEnd + 1;
		}
		return -1;
	}

	/**
	 * @param string $html
	 * @param int $start Offset of an opening <div.
	 * @return int Byte offset just past the matching </div>, or -1.
	 */
	private function matchingDivEnd( $html, $start ) {
		$depth = 0;
		$len = strlen( $html );
		$i = $start;
		while ( $i < $len ) {
			$open = strpos( $html, '<div', $i );
			$close = strpos( $html, '</div>', $i );
			if ( $close === false ) {
				return -1;
			}
			if ( $open !== false && $open < $close ) {
				$depth++;
				$i = $open + 4;
			} else {
				$depth--;
				$i = $close + 6;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}
		return -1;
	}
}

$maintClass = RewriteScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
