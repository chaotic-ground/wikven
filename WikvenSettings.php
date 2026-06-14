<?php

wfLoadExtension('Wikven');

// Build mechanics. The user-overridable MediaWiki defaults live in default.yaml;
// only the static-export internals that are not plain config stay here.

// The static export is MediaWiki's own file cache, written to the output dir.
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = '/workspace/dist';

// Ship a small built-in favicon so browsers do not 404 on /favicon.ico.
// Overridable from .wikven.yaml via "config": { "Favicon": "..." }.
$wgFavicon = 'data:image/svg+xml,'
. rawurlencode(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
	. '<rect width="32" height="32" rx="6" fill="#157f93"/>'
	. '<text x="16" y="23" font-family="sans-serif" font-size="20" font-weight="700"'
	. ' fill="#ffffff" text-anchor="middle">W</text></svg>'
);

unset($wgFooterIcons['poweredby']);

// Configuration: wikven's defaults (default.yaml) merged with the site's own
// .wikven.yaml (or .wikven.json), the site overriding the defaults. Both use
// MediaWiki's YAML settings format: a "config" map (variables without the "wg"
// prefix), plus "extensions" and "skins" lists. .wikven.json is read only when
// no .wikven.yaml is present (YAML is a superset of JSON). YamlFormat prefers the
// PECL yaml extension and falls back to the bundled symfony/yaml.
$wikvenYaml = new MediaWiki\Settings\Source\Format\YamlFormat();
$config = $wikvenYaml->decode(file_get_contents("$IP/extensions/Wikven/default.yaml"));

$wikvenSiteFile = null;
if (file_exists('/workspace/src/.wikven.yaml')) {
	$wikvenSiteFile = '/workspace/src/.wikven.yaml';
	if (file_exists('/workspace/src/.wikven.json')) {
		error_log('Wikven: both .wikven.yaml and .wikven.json exist; using .wikven.yaml and ignoring .wikven.json');
	}
} elseif (file_exists('/workspace/src/.wikven.json')) {
	$wikvenSiteFile = '/workspace/src/.wikven.json';
}

if ($wikvenSiteFile !== null) {
	$format = str_ends_with($wikvenSiteFile, '.json')
		? new MediaWiki\Settings\Source\Format\JsonFormat()
		: $wikvenYaml;
	$site = $format->decode(file_get_contents($wikvenSiteFile));
	// The site overrides the defaults: config keys merge (the site wins), and
	// the extensions and skins lists are appended.
	$config['config'] = array_merge($config['config'] ?? [], $site['config'] ?? []);
	$config['extensions'] = array_merge($config['extensions'] ?? [], $site['extensions'] ?? []);
	$config['skins'] = array_merge($config['skins'] ?? [], $site['skins'] ?? []);
}

// Skins. Register each named skin and use the first as the default. Only skins
// bundled in this image can be enabled; an unknown name is skipped with a
// warning instead of aborting the whole build.
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
		// directory name (e.g. 'MinervaNeue'), so read the canonical name from
		// the skin's own skin.json instead of guessing it.
		$wgDefaultSkin = strtolower($skin);
		$skinMeta = json_decode(file_get_contents("$IP/skins/$skin/skin.json"), true);
		if (isset($skinMeta['ValidSkinNames']) && is_array($skinMeta['ValidSkinNames'])) {
			$wgDefaultSkin = (string)array_key_first($skinMeta['ValidSkinNames']);
		}
	}
}

// Extensions. Only extensions bundled in this image can be enabled; a name that
// is not installed (a typo, or a third-party extension that is not yet
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

// Config. Every entry maps to a MediaWiki or extension configuration variable,
// named without the "wg" prefix, exactly as in MediaWiki's own YAML settings
// format. This includes Wikven's own variables such as WikvenFooterUrl,
// WikvenEditUrl, WikvenHistoryUrl, and WikvenLogos.
foreach ($config['config'] ?? [] as $key => $val) {
	$GLOBALS['wg' . $key] = $val;
}

// Logos: WikvenLogos mirrors MediaWiki's $wgLogos, except each source is the name
// of an image file in the source directory rather than a URL. The static export
// rewrites asset URLs to hashed local files, so there is no stable URL to point
// $wgLogos at; instead each named file is inlined as a data URI. A value is
// either a file name, or (like $wgLogos['wordmark'] and ['tagline']) a map with a
// "src" file name plus extra keys such as width and height.
if (!empty($wgWikvenLogos) && is_array($wgWikvenLogos)) {
	$wikvenLogoData = static function ($name) {
		$file = '/workspace/src/' . $name;
		if (!is_file($file)) {
			error_log("Wikven: logo file '$name' not found in the source directory");
			return null;
		}
		$mimeTypes = [
			'svg' => 'image/svg+xml',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'ico' => 'image/x-icon'
		];
		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$mime = $mimeTypes[$ext] ?? 'application/octet-stream';
		return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file));
	};

	$logos = isset($wgLogos) && is_array($wgLogos) ? $wgLogos : [];
	foreach ($wgWikvenLogos as $key => $value) {
		if (is_array($value)) {
			if (isset($value['src'])) {
				$src = $wikvenLogoData($value['src']);
				if ($src === null) {
					continue;
				}
				$value['src'] = $src;
			}
			$logos[$key] = $value;
		} else {
			$data = $wikvenLogoData($value);
			if ($data !== null) {
				$logos[$key] = $data;
			}
		}
	}
	$wgLogos = $logos;
}
