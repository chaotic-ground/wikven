<?php

wfLoadExtension('Wikven');

// Build mechanics. The user-overridable MediaWiki defaults live in default.yaml;
// only the static-export internals that are not plain config stay here.

// All filesystem locations derive from one working directory so the same build
// works in the Docker image (where /workspace is mounted) and in a standalone
// binary (where it points at a writable host directory). Layout:
//   $WIKVEN_WORKDIR/src    input (read-only)
//   $WIKVEN_WORKDIR/dist   output (the rendered file cache)
//   $WIKVEN_WORKDIR/.cache ephemeral state (uploads, l10n + object cache, tmp)
$wikvenWorkEnv = getenv('WIKVEN_WORKDIR');
$wikvenWork = $wikvenWorkEnv !== false && $wikvenWorkEnv !== '' ? $wikvenWorkEnv : '/workspace';
$wikvenSrc = "$wikvenWork/src";
$wikvenDist = "$wikvenWork/dist";
$wikvenCache = "$wikvenWork/.cache";

// The static export is MediaWiki's own file cache, written to the output dir.
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = $wikvenDist;
$wgWikvenSourceDirectory = $wikvenSrc;
$wgWikvenHtmlDirectory = $wikvenDist;

// Standalone-binary mode (WIKVEN_WORKDIR set): keep every ephemeral write out of
// the install dir, which in the embedded binary is an extracted, throwaway tree.
// In the Docker image WIKVEN_WORKDIR is unset, the install dir is writable, and
// MediaWiki's defaults are left untouched (no behavior change).
if ($wikvenWorkEnv !== false && $wikvenWorkEnv !== '') {
	$wgUploadDirectory = "$wikvenCache/uploads";
	$wgCacheDirectory = "$wikvenCache/mw";
	$wgTmpDirectory = "$wikvenCache/tmp";
	foreach ([$wgUploadDirectory, $wgCacheDirectory, $wgTmpDirectory] as $wikvenDir) {
		if (!is_dir($wikvenDir)) {
			@mkdir($wikvenDir, 0777, true);
		}
	}
}

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

// Image backend, detected at run time so the build uses whatever the host has.
// Raster thumbnails (png/jpg/gif/webp) go through ImageMagick when its `convert`
// (or `magick`) is on PATH, otherwise the GD extension. SVG goes through
// rsvg-convert when present, otherwise it is served inline as sanitized native
// SVG. SVG is deliberately never routed through ImageMagick, whose built-in
// 'ImageMagick' converter calls the `convert` binary that does not exist on
// ImageMagick-7-only hosts. This runs before the config load below, so an
// explicit value in .wikven.yaml still wins; default.yaml does not set the
// backend. In the Docker image both tools are present, so this reproduces the
// previous ImageMagick + rsvg configuration exactly.
$wikvenFindExe = static function (array $names) {
	$path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
	foreach ($names as $name) {
		foreach (explode(':', $path) as $dir) {
			if ($dir !== '' && is_executable(rtrim($dir, '/') . '/' . $name)) {
				return rtrim($dir, '/') . '/' . $name;
			}
		}
	}
	return null;
};
$wikvenConvert = $wikvenFindExe(['convert', 'magick']);
$wikvenRsvg = $wikvenFindExe(['rsvg-convert']);
$wgUseImageMagick = $wikvenConvert !== null;
if ($wikvenConvert !== null) {
	$wgImageMagickConvertCommand = $wikvenConvert;
}
if ($wikvenRsvg !== null) {
	$wgSVGConverter = 'rsvg';
	$wgSVGConverterPath = dirname($wikvenRsvg);
} else {
	$wgSVGNativeRendering = true;
}
if ($wikvenConvert === null || $wikvenRsvg === null) {
	error_log(
		'Wikven: '
		. ( $wikvenConvert === null ? 'ImageMagick not found, using GD for raster thumbnails. ' : '' )
		. ( $wikvenRsvg === null ? 'rsvg-convert not found, serving SVG inline (native). ' : '' )
		. 'Install ImageMagick and librsvg for higher-quality thumbnails.'
	);
}

// Configuration: wikven's defaults (default.yaml) merged with the site's own
// .wikven.yaml (or .wikven.json), the site overriding the defaults. Both use
// MediaWiki's YAML settings format: a "config" map (variables without the "wg"
// prefix), plus "extensions" and "skins" lists. .wikven.json is read only when
// no .wikven.yaml is present (YAML is a superset of JSON). YamlFormat prefers the
// PECL yaml extension and falls back to the bundled symfony/yaml.
$wikvenYaml = new MediaWiki\Settings\Source\Format\YamlFormat();
$config = $wikvenYaml->decode(file_get_contents("$IP/extensions/Wikven/default.yaml"));

$wikvenSiteFile = null;
if (file_exists("$wikvenSrc/.wikven.yaml")) {
	$wikvenSiteFile = "$wikvenSrc/.wikven.yaml";
	if (file_exists("$wikvenSrc/.wikven.json")) {
		error_log('Wikven: both .wikven.yaml and .wikven.json exist; using .wikven.yaml and ignoring .wikven.json');
	}
} elseif (file_exists("$wikvenSrc/.wikven.json")) {
	$wikvenSiteFile = "$wikvenSrc/.wikven.json";
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
// of an image file in the source directory rather than a URL. Those files are
// uploaded into the File: namespace at build time like any other source image, so
// each one already has a URL; we point $wgLogos at it. The upload path is
// predictable because uploads are stored flat (HashedUploadDirectory: false in
// default.yaml), so the URL can be built here, at config time, before the file is
// actually uploaded later in the build. The static export then localizes that URL
// to a single shared file alongside the HTML (storeImages.php), so the logo is not
// inlined into every page. A value is either a file name, or (like
// $wgLogos['wordmark'] and ['tagline']) a map with a "src" file name plus extra
// keys such as width and height.
if (!empty($wgWikvenLogos) && is_array($wgWikvenLogos)) {
	// Map a source file name to the URL its upload will have. MediaWiki turns the
	// file name into a File: title (spaces become underscores, and the first letter
	// is capitalized unless $wgCapitalLinks is off, as Wikven's default keeps it),
	// then, with flat storage, serves it from $wgUploadPath/<title>.
	$wikvenLogoUrl = static function ($name) use ($wikvenSrc) {
		global $wgUploadPath, $wgScriptPath, $wgCapitalLinks;
		if (!is_file("$wikvenSrc/" . $name)) {
			error_log("Wikven: logo file '$name' not found in the source directory");
			return null;
		}
		// $wgUploadPath is still its false default this early (MediaWiki resolves it
		// to "$wgScriptPath/images" later, in Setup.php); resolve it the same way so
		// the URL matches what the upload, and storeImages.php, will use.
		$uploadPath =
			$wgUploadPath !== false && (string)$wgUploadPath !== ''
				? $wgUploadPath
				: ( $wgScriptPath ?? '' ) . '/images';
		$title = str_replace(' ', '_', trim($name));
		if ($wgCapitalLinks ?? true) {
			$title = ucfirst($title);
		}
		return rtrim((string)$uploadPath, '/') . '/' . $title;
	};

	$logos = isset($wgLogos) && is_array($wgLogos) ? $wgLogos : [];
	foreach ($wgWikvenLogos as $key => $value) {
		if (is_array($value)) {
			if (isset($value['src'])) {
				$src = $wikvenLogoUrl($value['src']);
				if ($src === null) {
					continue;
				}
				$value['src'] = $src;
			}
			$logos[$key] = $value;
		} else {
			$url = $wikvenLogoUrl($value);
			if ($url !== null) {
				$logos[$key] = $url;
			}
		}
	}
	$wgLogos = $logos;
}
