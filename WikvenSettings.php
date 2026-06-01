<?php

wfLoadExtension( 'Wikven' );

// File caches
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = '/workspace/dist';

// Contents
$wgSitename = 'Wikven';
$wgCapitalLinks = false;
$wgRestrictDisplayTitle = false;
$wgUseInstantCommons = true;

// Etc
$wgJobRunRate = 0;
unset( $wgFooterIcons['poweredby'] );

// Skin
$wgVectorDefaultSkinVersion = '2';
$wgVectorStickyHeader = [ 'logged_out' => true ];
$wgVectorLanguageInHeader = $wgVectorStickyHeader;
$wgVectorResponsive = true;

// Read configurations from .wikven.json
if ( file_exists( '/workspace/src/.wikven.json' ) ) {
	$text = file_get_contents( '/workspace/src/.wikven.json' );
	$config = json_decode( $text, true );

	// Skins
	if ( isset( $config['Skin'] ) ) {
		wfLoadSkin( $config['Skin'] );
		// A skin's default-skin name (e.g. 'minerva') can differ from its
		// extension directory name (e.g. 'MinervaNeue'), so read the canonical
		// name from the skin's own skin.json instead of guessing it.
		$wgDefaultSkin = strtolower( $config['Skin'] );
		$skinJson = "$IP/skins/{$config['Skin']}/skin.json";
		if ( is_readable( $skinJson ) ) {
			$skinMeta = json_decode( file_get_contents( $skinJson ), true );
			if ( isset( $skinMeta['ValidSkinNames'] ) && is_array( $skinMeta['ValidSkinNames'] ) ) {
				$wgDefaultSkin = (string)array_key_first( $skinMeta['ValidSkinNames'] );
			}
		}
		unset( $config['Skin'] );
	}

	// Extensions
	if ( isset( $config['Extensions'] ) ) {
		if ( is_array( $config['Extensions'] ) ) {
			wfLoadExtensions( $config['Extensions'] );
		}
		unset( $config['Extensions'] );
	}

	// wg variables
	if ( isset( $config['wg'] ) ) {
		foreach ( $config['wg'] as $key => $val ) {
			$key = 'wg' . $key;
			$GLOBALS[$key] = $val;
		}
		unset( $config['wg'] );
	}

	// Etc
	if ( isset( $config['Url'] ) ) {
		$wgWikvenFooterUrl = $config['Url'];
		unset( $config['Url'] );
	}
	foreach ( $config as $key => $val ) {
		$key = 'wgWikven' . $key;
		$GLOBALS[$key] = $val;
	}
}
