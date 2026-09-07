<?php

wfLoadExtension('Wikven');

// Static-export build internals; user-overridable defaults live in default.yml.

// Paths derive from one workdir (src input, dist output, .cache ephemeral state); BuildPaths
// holds the rule. Required by hand: wfLoadExtension above only queues the extension.
require_once "$IP/extensions/Wikven/includes/Build/BuildPaths.php";
$wikvenWorkEnv = getenv('WIKVEN_WORKDIR');
$wikvenWork = $wikvenWorkEnv !== false && $wikvenWorkEnv !== '' ? $wikvenWorkEnv : '/workspace';
$wikvenPaths = MediaWiki\Extension\Wikven\Build\BuildPaths::fromWorkdir($wikvenWork);
$wikvenSrc = $wikvenPaths['source'];
$wikvenDist = $wikvenPaths['dist'];
$wikvenCache = $wikvenPaths['cache'];

// The static export is MediaWiki's own file cache, written to the output dir.
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = $wikvenDist;
$wgWikvenSourceDirectory = $wikvenSrc;
$wgWikvenHtmlDirectory = $wikvenDist;

// That file cache holds two actions per page, and the export is one of them: rebuildFileCache.php
// renders ?action=history for every page in every skin pass, into a tree the pass then deletes.
// Swap it for an action that renders nothing.
$GLOBALS['wgActions']['history'] = MediaWiki\Extension\Wikven\SkippedHistoryAction::class;

// Per-page "last edited" dates come from the source tree's git history, which a bake usually
// cannot reach: actions/bake mounts the source directory without the .git beside it, so it dumps
// the log on the runner instead. See SourceHistory.
$wgWikvenSourceHistoryFile = $wikvenPaths['history'];

// $wgCacheEpoch would otherwise follow LocalSettings.php's mtime, which the entrypoint rewrites
// every bake, and it is a version input of any module carrying a versionCallback.
$wgInvalidateCacheOnLocalSettingsChange = false;

// The database queue pops jobs in random order by default. A build has one runner and wants a
// fixed order: the jobs that render translated pages create those pages, so a random order gives
// them different ids on every bake.
$GLOBALS['wgJobTypeConf']['default']['order'] = 'fifo';

// The NewPP limit report is a wall-clock measurement of the parse, so it differs between bakes.
// It is addressed to someone debugging a live wiki, and nothing in an export can act on it.
$wgEnableParserLimitReporting = false;

// Run the whole build as of one instant: with a live clock the pages report themselves as edited
// seconds ago, differently in each bake. wikven's action passes the commit being built.
$wikvenEpoch = getenv('SOURCE_DATE_EPOCH');
$wikvenEpoch = is_string($wikvenEpoch) && preg_match('/^\d+$/', trim($wikvenEpoch))
	? (int)trim($wikvenEpoch)
	: 946_684_800;
Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime($wikvenEpoch);

// Each parse of a page embedding a Commons image asks commons.wikimedia.org for its thumbnail
// URL again, and the installer leaves the main object cache at CACHE_NONE, so all that is left
// is a three-entry per-process one.
$wgMainCacheType = CACHE_DB;

// The frozen clock makes this one impossible to invalidate: CacheTime::expired() tests
// getCacheTime() < page_touched strictly and setFakeTime gives both the same value, so a parse
// taken mid-build never looks stale (#333). Costs a parse per skin instead of one per bake.
$wgParserCacheType = CACHE_NONE;

// Let pages opt out of indexing with __NOINDEX__ in any namespace.
$wgExemptFromUserRobotsControl = [];

// Standalone-binary mode (WIKVEN_WORKDIR set): keep ephemeral writes out of the install dir.
if ($wikvenWorkEnv !== false && $wikvenWorkEnv !== '') {
	$wgUploadDirectory = "$wikvenCache/uploads";
	$wgCacheDirectory = "$wikvenCache/mw";
	$wgTmpDirectory = "$wikvenCache/tmp";
	foreach ([$wgUploadDirectory, $wgCacheDirectory, $wgTmpDirectory] as $wikvenDir) {
		// Honours $wgDirectoryMode and re-checks is_dir() itself after a losing race, which the
		// standalone binary can hit when two builds share one workdir.
		if (!wfMkdirParents($wikvenDir, null, __FILE__)) {
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
	// Core's own environment checks locate the same binaries with this, so the two agree on where
	// they are: it splits PATH and also searches the standard bin directories a stripped-down PATH
	// omits. It reaches nothing but getenv() and is_executable().
	if (class_exists(\MediaWiki\Utils\ExecutableFinder::class)) {
		return \MediaWiki\Utils\ExecutableFinder::findInDefaultPaths($names) ?: null;
	}
	$path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
	foreach ($names as $name) {
		foreach (explode(PATH_SEPARATOR, $path) as $dir) {
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
	// Native SVG rendering is on by default and bypasses the converter; turn it off so the
	// rsvg converter configured above rasterizes SVGs into thumbnails.
	$wgSVGNativeRendering = false;
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

// Autoloader not active yet at LocalSettings time; load the helpers directly. SiteUrl belongs here
// rather than beside its other use below: lint() reads a site's WikvenSiteUrl through it, and that
// runs a few lines down from here.
require_once "$IP/extensions/Wikven/includes/SiteConfig.php";
require_once "$IP/extensions/Wikven/includes/SiteUrl.php";

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
	foreach (MediaWiki\Extension\Wikven\SiteConfig::lint($wikvenSiteData) as $wikvenWarning) {
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

// Core keeps a site's address in two halves and a site should not write it twice: it writes
// WikvenSiteUrl once, and this hands core the half it understands (see SiteUrl). $wgServer too,
// a reader having been handed the container's address.
$wikvenSiteUrl = MediaWiki\Extension\Wikven\SiteUrl::fromWritten((string)( $wgWikvenSiteUrl ?? '' ));
if ($wikvenSiteUrl->isKnown()) {
	$wgCanonicalServer = $wikvenSiteUrl->canonicalServer();
	$wgServer = $wgCanonicalServer;
}

// And take back the three the build works out for itself, which apply() has just handed to
// whatever a site's file said. A site that set WikvenSourceDirectory would be configured from
// one tree and built from another.
$wgWikvenSourceDirectory = $wikvenPaths['source'];
$wgWikvenHtmlDirectory = $wikvenPaths['dist'];
$wgWikvenSourceHistoryFile = $wikvenPaths['history'];

// Say which setting core cannot accept, while the site's file is still the obvious suspect. It
// cannot catch a misspelled key: validate() walks the schema's keys rather than the file's.
foreach (MediaWiki\Extension\Wikven\SiteConfig::schemaErrors($wgSettings->validate()) as $wikvenBadSetting) {
	error_log("Wikven: WARNING in configuration: $wikvenBadSetting");
}

// Dedupe so each extension/skin loads at most once.
$config['extensions'] = array_values(array_unique(array_filter($config['extensions'], 'is_string'), SORT_STRING));
$config['skins'] = array_values(array_unique(array_filter($config['skins'], 'is_string'), SORT_STRING));

// A name in these two lists is a directory in this image, and both loops below turn it straight
// into a path: one carrying a separator resolves outside the image and is loaded anyway.

// Anything named below that is not on disk is a name whose settings nobody here can account
// for. Collected rather than counted, because the build fails on it and has to say which names.
$GLOBALS['wgWikvenMissing'] = [];

// Register each bundled skin; canonical name (may differ from dir) read from skin.json.
$wgWikvenSkins = [];
foreach ($config['skins'] ?? [] as $skin) {
	if (!is_string($skin)) {
		continue;
	}
	if (!MediaWiki\Extension\Wikven\SiteConfig::isComponentName($skin)) {
		error_log("Wikven: refusing skin '$skin' (a name here is a directory, not a path)");
		$GLOBALS['wgWikvenMissing'][] = "skin '$skin' (a name here is a directory, not a path)";
		continue;
	}
	if (!is_file("$IP/skins/$skin/skin.json")) {
		error_log("Wikven: skipping skin '$skin' (nothing here provides it)");
		$GLOBALS['wgWikvenMissing'][] =
			"skin '$skin' (not bundled, and no WikvenRepositories entry" . ' says where to fetch it)';
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

// Which skin the site is read in. A site names it with DefaultSkin, and the skins list says which
// to build. Without one, the first built skin: a default nothing renders leaves the root empty.
$wikvenNamedSkin = $wikvenSiteData['config']['DefaultSkin'] ?? '';
if (!is_string($wikvenNamedSkin)) {
	$wikvenNamedSkin = '';
}
// Through $GLOBALS because this is the one place the file reads core's default before setting
// it below, and nothing else in the tree declares that global any more.
$wikvenFirstSkin = $wgWikvenSkins[0] ?? $GLOBALS['wgDefaultSkin'];
if ($wikvenNamedSkin !== '' && !in_array($wikvenNamedSkin, $wgWikvenSkins, true)) {
	// Named by its canonical name, which is what MediaWiki calls a skin and is not always what you
	// listed it by: "minerva" for MinervaNeue, "vector-2022" for Vector.
	error_log(
		'Wikven: WARNING in '
		. basename((string)$wikvenSiteFile)
		. ': DefaultSkin is'
		. " '$wikvenNamedSkin', which is not one of the skins this site builds ("
		. implode(', ', $wgWikvenSkins)
		. "); reading the site in '$wikvenFirstSkin' instead."
	);
	$wikvenNamedSkin = '';
}
$wgWikvenMainSkin = $wikvenNamedSkin !== '' ? $wikvenNamedSkin : $wikvenFirstSkin;
$wgDefaultSkin = $wgWikvenMainSkin;

// Per-skin build pass: WIKVEN_BUILD_SKIN renders main skin to dist root, others to dist/<skin>/.
$wikvenBuildSkin = getenv('WIKVEN_BUILD_SKIN');
if ($wikvenBuildSkin !== false && in_array($wikvenBuildSkin, $wgWikvenSkins, true)) {
	$wgDefaultSkin = $wikvenBuildSkin;
	// Source images are uploaded by the pass that populates the wiki, so by the time a skin renders
	// an "Upload file" link would point at a Special: page the export does not have. Citizen's is
	// the visible one.
	$wgEnableUploads = false;
	// The passes run beside each other, and SQLite takes one writer at a time -- a pass still
	// writes to the object cache, which is a table. build.php hands each a copy of the database
	// and names its directory here.
	$wikvenPassDatabase = getenv('WIKVEN_BUILD_DB_DIR');
	if (is_string($wikvenPassDatabase) && is_dir($wikvenPassDatabase)) {
		$wgSQLiteDataDir = $wikvenPassDatabase;
	}
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
	// The same directory-name check the skins above make; see there for why it is made here.
	if (!MediaWiki\Extension\Wikven\SiteConfig::isComponentName($extension)) {
		error_log("Wikven: refusing extension '$extension' (a name here is a directory, not a path)");
		$GLOBALS['wgWikvenMissing'][] = "extension '$extension' (a name here is a directory, not a path)";
		continue;
	}
	if (is_file("$IP/extensions/$extension/extension.json")) {
		wfLoadExtension($extension);
	} else {
		error_log("Wikven: skipping extension '$extension' (nothing here provides it)");
		$GLOBALS['wgWikvenMissing'][] =
			"extension '$extension' (not bundled, and no WikvenRepositories" . ' entry says where to fetch it)';
	}
}

// UniversalLanguageSelector would have the browser pull its webfonts from load.php, which a
// static export cannot serve; with $wgWikvenBundleWebfonts set, bakeWebfonts.php bakes them into
// a stylesheet instead. What stops the fetch is Main::onSetupAfterCache().
if (in_array('UniversalLanguageSelector', $config['extensions'], true)) {
	$GLOBALS['wgULSWebfontsEnabled'] = false;
	$GLOBALS['wgULSIMEEnabled'] = false;
}

// Citizen points its web app manifest at api.php, which the export has no server for: the tag
// would carry the build host's own URL ("http://localhost:4000/api.php?action=appmanifest") into
// every page. Nothing else in the skin depends on the manifest.
if (in_array('citizen', $wgWikvenSkins, true)) {
	$GLOBALS['wgCitizenEnableManifest'] = false;
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

// Say which config names nothing defines. This is the quietest way a line in a site's file is
// lost: $wgSettings writes the name into a global nothing reads. Silent while a name in this
// site's lists is missing.
if ($wikvenSiteFile !== null && $GLOBALS['wgWikvenMissing'] === []) {
	// Core's names are already in hand, and most of a config file is core settings. Only what they
	// leave over is worth opening two dozen manifests for, and this runs in every build process.
	$wikvenDefined = array_fill_keys($wgSettings->getDefinedConfigKeys(), true);
	$wikvenUnaccounted = array_diff_key($wikvenSiteConfig, $wikvenDefined);
	if ($wikvenUnaccounted !== []) {
		$wikvenDefined += MediaWiki\Extension\Wikven\SiteConfig::manifestConfigNames(
			array_keys(MediaWiki\Registration\ExtensionRegistry::getInstance()->getQueue())
		);
		$wikvenUndefined = MediaWiki\Extension\Wikven\SiteConfig::undefinedConfig(
			array_keys($wikvenUnaccounted),
			array_keys($wikvenDefined)
		);
		// Named again rather than carried down from the read above: the name is only ever set
		// alongside a file, and the guard on this block is the file.
		$wikvenReportedFile = basename($wikvenSiteFile);
		foreach ($wikvenUndefined as $wikvenWarning) {
			error_log("Wikven: WARNING in $wikvenReportedFile: $wikvenWarning");
		}
	}
}

// WikvenLogos ($wgWikvenLogos) mirrors $wgLogos but each src is a source-dir file name, resolved
// to its upload URL once the service container exists; see Hooks\Main::onSetupAfterCache().
