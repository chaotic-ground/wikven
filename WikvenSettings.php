<?php

wfLoadExtension('Wikven');

// File caches
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = '/workspace/dist';

// Contents
$wgSitename = 'Wikven';
$wgCapitalLinks = false;
$wgRestrictDisplayTitle = false;
$wgUseInstantCommons = true;

// Ship a small built-in favicon so browsers do not 404 on /favicon.ico.
// Overridable from .wikven.yaml via "config": { "Favicon": "..." }.
$wgFavicon = 'data:image/svg+xml,'
. rawurlencode(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
	. '<rect width="32" height="32" rx="6" fill="#157f93"/>'
	. '<text x="16" y="23" font-family="sans-serif" font-size="20" font-weight="700"'
	. ' fill="#ffffff" text-anchor="middle">W</text></svg>'
);

// Etc
$wgJobRunRate = 0;
unset($wgFooterIcons['poweredby']);

// The static export ships its own startup + module bundle, so the
// localStorage module store would only cache stale code across rebuilds.
$wgResourceLoaderStorageEnabled = false;

// Skin
$wgVectorDefaultSkinVersion = '2';
$wgVectorStickyHeader = ['logged_out' => true];
$wgVectorLanguageInHeader = $wgVectorStickyHeader;
$wgVectorResponsive = true;

// Read configurations from .wikven.yaml or .wikven.json, the same formats
// MediaWiki's own settings system (MW_CONFIG_FILE) accepts. YAML is the primary
// format; .wikven.json is read only when no .wikven.yaml is present. (YAML is a
// superset of JSON, so JSON syntax is also valid inside a .wikven.yaml file.)
// YamlFormat prefers the PECL yaml extension and falls back to the bundled
// symfony/yaml, so no extra dependency is required.
$wikvenConfigFile = null;
if (file_exists('/workspace/src/.wikven.yaml')) {
	$wikvenConfigFile = '/workspace/src/.wikven.yaml';
	if (file_exists('/workspace/src/.wikven.json')) {
		error_log('Wikven: both .wikven.yaml and .wikven.json exist; using .wikven.yaml and ignoring .wikven.json');
	}
} elseif (file_exists('/workspace/src/.wikven.json')) {
	$wikvenConfigFile = '/workspace/src/.wikven.json';
}

if ($wikvenConfigFile !== null) {
	$text = file_get_contents($wikvenConfigFile);
	$format = str_ends_with($wikvenConfigFile, '.json')
		? new MediaWiki\Settings\Source\Format\JsonFormat()
		: new MediaWiki\Settings\Source\Format\YamlFormat();
	$config = $format->decode($text);

	// Skins. Register each named skin and use the first as the default. Only
	// skins bundled in this image can be enabled; an unknown name is skipped
	// with a warning instead of aborting the whole build.
	foreach ($config['skins'] ?? [] as $skin) {
		if (!is_string($skin)) {
			continue;
		}
		if (!is_file("$IP/skins/$skin/skin.json")) {
			error_log("Wikven: skipping skin '$skin' (not bundled in this image)");
			continue;
		}
		wfLoadSkin($skin);
		if (!isset($wgDefaultSkin)) {
			// A skin's default-skin name (e.g. 'minerva') can differ from its
			// directory name (e.g. 'MinervaNeue'), so read the canonical name
			// from the skin's own skin.json instead of guessing it.
			$wgDefaultSkin = strtolower($skin);
			$skinMeta = json_decode(file_get_contents("$IP/skins/$skin/skin.json"), true);
			if (isset($skinMeta['ValidSkinNames']) && is_array($skinMeta['ValidSkinNames'])) {
				$wgDefaultSkin = (string)array_key_first($skinMeta['ValidSkinNames']);
			}
		}
	}

	// Extensions. Only extensions bundled in this image can be enabled; a name
	// that is not installed (a typo, or a third-party extension that is not yet
	// supported) is skipped with a warning instead of aborting the whole build.
	foreach ($config['extensions'] ?? [] as $extension) {
		if (!is_string($extension)) {
			continue;
		}
		if (is_file("$IP/extensions/$extension/extension.json")) {
			wfLoadExtension($extension);
		} else {
			error_log("Wikven: skipping extension '$extension' (not bundled in this image)");
		}
	}

	// Config. Every entry maps to a MediaWiki or extension configuration
	// variable, named without the "wg" prefix, exactly as in MediaWiki's own
	// YAML settings format. This includes Wikven's own variables such as
	// WikvenFooterUrl, WikvenEditUrl, WikvenHistoryUrl, and WikvenLogo.
	foreach ($config['config'] ?? [] as $key => $val) {
		$GLOBALS['wg' . $key] = $val;
	}

	// Logo: WikvenLogo names an image file in the source directory. Inline it
	// as the header icon, so the static export carries its logo with no extra
	// request and no path that only resolves on a live server.
	if (!empty($wgWikvenLogo)) {
		$logoFile = '/workspace/src/' . $wgWikvenLogo;
		if (is_file($logoFile)) {
			$logoMimeTypes = [
				'svg' => 'image/svg+xml',
				'png' => 'image/png',
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'gif' => 'image/gif',
				'webp' => 'image/webp',
				'ico' => 'image/x-icon'
			];
			$logoExtension = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
			$logoMime = $logoMimeTypes[$logoExtension] ?? 'application/octet-stream';
			$logoData = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoFile));

			$logos = isset($wgLogos) && is_array($wgLogos) ? $wgLogos : [];
			$logos['icon'] = $logoData;
			$wgLogos = $logos;
		} else {
			error_log("Wikven: logo file '$wgWikvenLogo' not found in the source directory");
		}
	}
}
