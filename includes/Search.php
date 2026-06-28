<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Registration\ExtensionRegistry;

/** Whether SifterSearch provides a working static search box: loaded and indexing into a bundle. */
class Search {
	public static function isActive(): bool {
		return (
			ExtensionRegistry::getInstance()->isLoaded('SifterSearch')
			&& (string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' ) !== ''
		);
	}
}
