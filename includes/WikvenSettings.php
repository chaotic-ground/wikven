<?php

wfLoadExtension('Wikven');

// Static-export build internals; user-overridable defaults live in default.yml.

// Paths derive from one workdir (src input, dist output, .cache ephemeral state).
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

// Let pages opt out of indexing with __NOINDEX__ in any namespace.
$wgExemptFromUserRobotsControl = [];

// Standalone-binary mode (WIKVEN_WORKDIR set): keep ephemeral writes out of the install dir.
if ($wikvenWorkEnv !== false && $wikvenWorkEnv !== '') {
	$wgUploadDirectory = "$wikvenCache/uploads";
	$wgCacheDirectory = "$wikvenCache/mw";
	$wgTmpDirectory = "$wikvenCache/tmp";
	foreach ([$wgUploadDirectory, $wgCacheDirectory, $wgTmpDirectory] as $wikvenDir) {
		if (!is_dir($wikvenDir) && !mkdir($wikvenDir, 0777, true) && !is_dir($wikvenDir)) {
			throw new \RuntimeException("Wikven: could not create directory $wikvenDir");
		}
	}
}

// Built-in favicon so browsers do not 404; overridable via config.Favicon.
$wgFavicon = 'data:image/svg+xml,'
. rawurlencode(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
	. '<rect width="32" height="32" rx="6" fill="#157f93"/>'
	. '<text x="16" y="23" font-family="sans-serif" font-size="20" font-weight="700"'
	. ' fill="#ffffff" text-anchor="middle">W</text></svg>'
);

unset($wgFooterIcons['poweredby']);

// Detect image backend at run time; SVG never via ImageMagick (IM7 lacks `convert`).
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

// Load config: default.yml then the site file via $wgSettings; ext/skin lists loaded leniently.
global $wgSettings;

// Known config names from extension.json, used to flag misspelled keys.
$wikvenManifest = json_decode(file_get_contents("$IP/extensions/Wikven/extension.json"), true);
$wikvenKnownConfig = array_keys($wikvenManifest['config'] ?? []);

// Autoloader not active yet at LocalSettings time; load the helper directly.
require_once "$IP/extensions/Wikven/includes/SiteConfig.php";

// Pick the highest-precedence config name present; warn about any others.
$wikvenLocated = MediaWiki\Extension\Wikven\SiteConfig::locate($wikvenSrc);
$wikvenSiteFile = $wikvenLocated['path'];
if ($wikvenSiteFile !== null && $wikvenLocated['ignored'] !== []) {
	error_log(
		'Wikven: multiple site config files present; using ' . basename($wikvenSiteFile) . ' and ignoring '
			. implode(', ', array_map('basename', $wikvenLocated['ignored']))
	);
}

// Defaults then site file: feed each "config" map to $wgSettings, collect ext/skin names.
$config = ['extensions' => [], 'skins' => []];
$wikvenYaml = new MediaWiki\Settings\Source\Format\YamlFormat();
$wikvenYamlData = $wikvenYaml->decode(file_get_contents("$IP/extensions/Wikven/default.yml"));
$wikvenSiteData = [];
if ($wikvenSiteFile !== null) {
	$wikvenSiteFormat = str_ends_with($wikvenSiteFile, '.json')
		? new MediaWiki\Settings\Source\Format\JsonFormat()
		: new MediaWiki\Settings\Source\Format\YamlFormat();
	$wikvenSiteData = $wikvenSiteFormat->decode(file_get_contents($wikvenSiteFile));
	$wikvenSiteName = basename($wikvenSiteFile);
	foreach (MediaWiki\Extension\Wikven\SiteConfig::lint($wikvenSiteData, $wikvenKnownConfig) as $wikvenWarning) {
		error_log("Wikven: WARNING in $wikvenSiteName: $wikvenWarning");
	}
}

foreach ([$wikvenYamlData, $wikvenSiteData] as $wikvenData) {
	if (!is_array($wikvenData)) {
		continue;
	}
	if (isset($wikvenData['config']) && is_array($wikvenData['config'])) {
		$wgSettings->loadArray(['config' => $wikvenData['config']]);
	}
	$config['extensions'] = array_merge($config['extensions'], (array)( $wikvenData['extensions'] ?? [] ));
	$config['skins'] = array_merge($config['skins'], (array)( $wikvenData['skins'] ?? [] ));
}

// Push merged config into globals so the logo handling below reads final values.
$wgSettings->apply();

// Dedupe so each extension/skin loads at most once.
$config['extensions'] = array_values(array_unique(array_filter($config['extensions'], 'is_string'), SORT_STRING));
$config['skins'] = array_values(array_unique(array_filter($config['skins'], 'is_string'), SORT_STRING));

// Register each bundled skin; canonical name (may differ from dir) read from skin.json.
$wgWikvenSkins = [];
foreach ($config['skins'] ?? [] as $skin) {
	if (!is_string($skin)) {
		continue;
	}
	if (!is_file("$IP/skins/$skin/skin.json")) {
		error_log("Wikven: skipping skin '$skin' (not bundled in this image)");
		continue;
	}
	wfLoadSkin($skin);
	$wikvenCanonical = strtolower($skin);
	$skinMeta = json_decode(file_get_contents("$IP/skins/$skin/skin.json"), true);
	if (isset($skinMeta['ValidSkinNames']) && is_array($skinMeta['ValidSkinNames'])) {
		$wikvenCanonical = (string)array_key_first($skinMeta['ValidSkinNames']);
	}
	$wgWikvenSkins[] = $wikvenCanonical;
}
$wgWikvenSkins = array_values(array_unique($wgWikvenSkins));

// First listed skin is the site default.
$wgWikvenMainSkin = $wgWikvenSkins[0] ?? $wgDefaultSkin;
$wgDefaultSkin = $wgWikvenMainSkin;

// Per-skin build pass: WIKVEN_BUILD_SKIN renders main skin to dist root, others to dist/<skin>/.
$wikvenBuildSkin = getenv('WIKVEN_BUILD_SKIN');
if ($wikvenBuildSkin !== false && in_array($wikvenBuildSkin, $wgWikvenSkins, true)) {
	$wgDefaultSkin = $wikvenBuildSkin;
	if ($wikvenBuildSkin !== $wgWikvenMainSkin) {
		$wgWikvenHtmlDirectory = "$wikvenDist/$wikvenBuildSkin";
		$wgFileCacheDirectory = $wgWikvenHtmlDirectory;
		// Non-main skins duplicate the main skin's pages; keep them out of search indexes.
		$wgDefaultRobotPolicy = 'noindex,follow';
	}
}

// Load each bundled extension; an unknown name is skipped with a warning.
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

// UniversalLanguageSelector (enabled for content i18n) would have the browser pull its webfont
// module and font files from load.php, which a static export cannot serve. Turn webfonts off;
// bundling them into the static site instead is tracked as a separate enhancement.
if (in_array('UniversalLanguageSelector', $config['extensions'], true)) {
	$GLOBALS['wgULSWebfontsEnabled'] = false;
}

// SifterSearch ships built in; default its Pagefind index into the build's dist dir, unless the
// site set the output path itself (an empty value there turns search off).
$wikvenSiteConfig = is_array($wikvenSiteData['config'] ?? null) ? $wikvenSiteData['config'] : [];
if (
	in_array('SifterSearch', $config['extensions'], true)
	&& !array_key_exists('SifterSearchOutputDir', $wikvenSiteConfig)
) {
	$GLOBALS['wgSifterSearchOutputDir'] = "$wikvenDist/pagefind";
}

// WikvenLogos mirrors $wgLogos but each src is a source-dir file name; resolve to its upload URL.
if (!empty($wgWikvenLogos) && is_array($wgWikvenLogos)) {
	// Map a source file name to the flat-storage URL its upload will have.
	$wikvenLogoUrl = static function ($name) use ($wikvenSrc) {
		global $wgUploadPath, $wgScriptPath, $wgCapitalLinks;
		if (!is_file("$wikvenSrc/" . $name)) {
			error_log("Wikven: logo file '$name' not found in the source directory");
			return null;
		}
		// $wgUploadPath is false this early; resolve to $wgScriptPath/images as Setup.php does.
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
