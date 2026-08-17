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

// Per-page "last edited" dates and authors come from the source tree's git history. A bake usually
// cannot reach it -- actions/bake mounts the source directory alone, without the .git beside it --
// so the action dumps the log on the runner and mounts it here instead. Absent, the build asks git
// directly, which answers when the source directory is itself in a checkout (see SourceHistory).
$wikvenHistoryFile = "$wikvenWork/source-history";
$wgWikvenSourceHistoryFile = is_file($wikvenHistoryFile) ? $wikvenHistoryFile : '';

// $wgCacheEpoch would otherwise follow LocalSettings.php's mtime, which the entrypoint rewrites
// every bake, and it is a version input of any module carrying a versionCallback.
$wgInvalidateCacheOnLocalSettingsChange = false;

// The database queue pops jobs in random order by default (JobQueueDB::optimalOrder), to spread
// concurrent runners over different rows. A build has one runner and wants a fixed order: the jobs
// that render translated pages create those pages, so a random order gives them different page and
// revision ids on every bake.
// Only the order is overridden, so the class and claimTTL core picked stay as they are.
$GLOBALS['wgJobTypeConf']['default']['order'] = 'fifo';

// The NewPP limit report is a wall-clock measurement of the parse, so it differs between bakes.
// It is addressed to someone debugging a live wiki, and nothing in an export can act on it.
$wgEnableParserLimitReporting = false;

// Run the whole build as of one instant. Every revision and upload is created while the build runs,
// so with a live clock the pages report themselves as edited seconds ago, differently in each bake:
// "last edited" lines, File: history, {{CURRENTTIMESTAMP}}, page_touched, cache stamps. Freezing the
// clock settles all of them at the source, which is what SOURCE_DATE_EPOCH means
// (https://reproducible-builds.org/docs/source-date-epoch/); wikven's GitHub action passes the
// commit being built, so the dates are true of it. Without it a fixed date is used, a wrong-but-
// fixed one being easier to live with than a wrong-and-moving one.
$wikvenEpoch = getenv('SOURCE_DATE_EPOCH');
$wikvenEpoch = is_string($wikvenEpoch) && preg_match('/^\d+$/', trim($wikvenEpoch))
	? (int)trim($wikvenEpoch)
	: 946_684_800;
Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime($wikvenEpoch);

// A build parses every page many times over (the import, the job queue, the translated pages, one
// pass per skin), and each parse of a page embedding a Wikimedia Commons image asks
// commons.wikimedia.org for that image's thumbnail URL again. MediaWiki caches those lookups in
// the main object cache, which the installer leaves at CACHE_NONE; all that is left then is a
// three-entry per-process cache, which a site using more than three distinct Commons URLs
// immediately thrashes. Point the main cache at the build's own database so each lookup is made
// once per build instead of once per parse. The parser cache is unaffected: CACHE_ANYTHING already
// resolved to the database. The lookups still left are worth retrying rather than trusting to one
// attempt; the Retrier hook handler sees to that.
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
	// Core's own environment checks locate the very same binaries with this, so the two agree on where
	// they are: it splits PATH on PATH_SEPARATOR and also searches the standard bin directories a
	// stripped-down PATH omits (/usr/local/bin, /opt/csw/bin, ...). It reaches nothing but getenv() and
	// is_executable() at this point, so it is safe this early; guarded anyway, since LocalSettings runs
	// before the extension can assume anything about the core it was dropped into.
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

// Where each skin pass writes, which is the one place that knows the export's layout: the main
// skin renders to the dist root, and every other skin to a subdirectory of it. A pass walking its
// own output reads this to prune the other skins' pages out of the walk -- the main skin's
// directory holds all of them (see OutputTree).
$wgWikvenSkinDirectories = [];
foreach ($wgWikvenSkins as $wikvenSkin) {
	$wgWikvenSkinDirectories[$wikvenSkin] = $wikvenSkin === $wgWikvenMainSkin ? $wikvenDist : "$wikvenDist/$wikvenSkin";
}

// Per-skin build pass: WIKVEN_BUILD_SKIN renders main skin to dist root, others to dist/<skin>/.
$wikvenBuildSkin = getenv('WIKVEN_BUILD_SKIN');
if ($wikvenBuildSkin !== false && in_array($wikvenBuildSkin, $wgWikvenSkins, true)) {
	$wgDefaultSkin = $wikvenBuildSkin;
	// Source images are uploaded by the pass that populates the wiki, so by the time a skin
	// renders there is nothing left to upload -- and an "Upload file" link in the rendered
	// chrome would point at a Special: page the export does not have. Citizen's is the visible
	// one: it moves the entry out of the toolbox (which Hider empties) into the sidebar.
	$wgEnableUploads = false;
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
// module and font files from load.php, which a static export cannot serve. Turn its runtime
// webfonts off; when $wgWikvenBundleWebfonts is set, maintenance/bakeWebfonts.php instead bakes
// the same fonts into a static stylesheet (see includes/Webfonts/FontRepository.php).
// Its input methods go the same way: focusing any text input -- the search box, most visibly --
// has the browser fetch ext.uls.ime and jquery.ime from load.php. Nothing in an export takes
// typed input anywhere, so there is nothing for a transliteration keyboard to type into. The flag
// below is not what stops that fetch: Main::onSetupAfterCache() empties the list of selectors the
// handler binds to, and says there why that cannot be done from here.
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

// Where the built index is, whichever pass is asking. It is built once, by the job the
// orchestrator drains before any skin pass runs, and so always lands in the main skin's output.
$wgWikvenSearchIndexSource = (string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' );

// Each skin copy searches within itself. Pagefind reports a result's URL relative to the crawl
// root, and the client resolves it against the directory the bundle sits in -- so a copy loading
// the root's bundle sends every result out of the copy and back to the root's pages (#399). The
// index describes one site and the copies hold the same pages, so what a copy needs is that same
// bundle inside its own directory: Build::renderSkin() puts it there, and pointing the client at
// it makes the copy's own directory the root each result resolves against.
//
// SifterSearch's own default is used where the site named no path, since its extension.json has
// not been read at this point -- LocalSettings.php runs before the registry materializes it. For
// the same reason Search is loaded by hand here, as SiteConfig is above.
require_once "$IP/extensions/Wikven/includes/Search.php";
$wikvenSkinPass = $wikvenBuildSkin !== false && in_array($wikvenBuildSkin, $wgWikvenSkins, true)
	? $wikvenBuildSkin
	: '';
$wikvenSearchCopy = MediaWiki\Extension\Wikven\Search::bundleCopyFor(
	$wikvenSkinPass,
	$wgWikvenMainSkin,
	(string)( $GLOBALS['wgSifterSearchBundlePath'] ?? '/pagefind/' ),
	$wgWikvenSearchIndexSource,
	$wgWikvenHtmlDirectory
);
if ($wikvenSearchCopy !== null) {
	$GLOBALS['wgSifterSearchBundlePath'] = $wikvenSearchCopy['path'];
	$GLOBALS['wgSifterSearchOutputDir'] = $wikvenSearchCopy['directory'];
}

// WikvenLogos ($wgWikvenLogos) mirrors $wgLogos but each src is a source-dir file name, resolved
// to its upload URL once the service container exists; see Hooks\Main::onSetupAfterCache().
