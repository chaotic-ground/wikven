<?php

wfLoadExtension('Wikven');

// Static-export build internals; user-overridable defaults live in default.yml.

// Paths derive from one workdir (src input, dist output, .cache ephemeral state). BuildPaths holds
// the rule; this file asks it again after a site's configuration is applied, and one copy of the
// rule cannot disagree with the other. Required by hand: wfLoadExtension above only queues the
// extension, so its autoloader is not there yet.
require_once "$IP/extensions/Wikven/includes/BuildPaths.php";
$wikvenWorkEnv = getenv('WIKVEN_WORKDIR');
$wikvenWork = $wikvenWorkEnv !== false && $wikvenWorkEnv !== '' ? $wikvenWorkEnv : '/workspace';
$wikvenPaths = MediaWiki\Extension\Wikven\BuildPaths::fromWorkdir($wikvenWork);
$wikvenSrc = $wikvenPaths['source'];
$wikvenDist = $wikvenPaths['dist'];
$wikvenCache = $wikvenPaths['cache'];

// The static export is MediaWiki's own file cache, written to the output dir.
$wgUseFileCache = true;
$wgFileCacheDepth = 0;
$wgFileCacheDirectory = $wikvenDist;
$wgWikvenSourceDirectory = $wikvenSrc;
$wgWikvenHtmlDirectory = $wikvenDist;

// That file cache holds two actions per page, and the export is one of them. rebuildFileCache.php
// renders ?action=history for every page as well, in every skin pass, into a tree the pass then
// deletes -- and no exported page ever links to a local history page. Swap the action for one that
// renders nothing rather than pay for it (see SkippedHistoryAction for why not $wgActions off).
// Through $GLOBALS because the setting defaults to an empty array that is never written here.
$GLOBALS['wgActions']['history'] = MediaWiki\Extension\Wikven\SkippedHistoryAction::class;

// Per-page "last edited" dates and authors come from the source tree's git history. A bake usually
// cannot reach it -- actions/bake mounts the source directory alone, without the .git beside it --
// so the action dumps the log on the runner and mounts it here instead. Absent, the build asks git
// directly, which answers when the source directory is itself in a checkout (see SourceHistory).
$wgWikvenSourceHistoryFile = $wikvenPaths['history'];

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

// Core keeps a site's address in two halves -- $wgCanonicalServer is scheme and host, a path lives
// elsewhere -- and a site should not have to write it twice. It writes WikvenSiteUrl once and this
// hands core the half core understands, so an absolute URL core or any extension builds names the
// right host. The path half is wikven's to add; see SiteUrl.
//
// Only where the site said. Left alone, $wgCanonicalServer keeps the install's localhost, which is
// wrong but is what everything downstream already expects of a build that has not been told.
//
// This is where the setting is read. SiteUrl is handed the written value rather than fetching it,
// so the same class serves this file, a maintenance script holding configuration, and a test
// holding neither.
$wikvenSiteUrl = MediaWiki\Extension\Wikven\SiteUrl::fromWritten((string)( $wgWikvenSiteUrl ?? '' ));
if ($wikvenSiteUrl->isKnown()) {
	$wgCanonicalServer = $wikvenSiteUrl->canonicalServer();
}

// And take back the three the build works out for itself, which apply() has just handed to
// whatever a site's file said. A site that set WikvenSourceDirectory would move where its pages are
// read from without moving where its own config file was looked for -- SiteConfig::locate() ran
// against the workdir long before this line -- so it would be configured from one tree and built
// from another. SiteConfig::lint() warns about the key; this is what makes the warning true.
$wgWikvenSourceDirectory = $wikvenPaths['source'];
$wgWikvenHtmlDirectory = $wikvenPaths['dist'];
$wgWikvenSourceHistoryFile = $wikvenPaths['history'];

// Say which setting core cannot accept, while the site's file is still the obvious suspect. Without
// this a wrong-typed value is carried until something reads it, and what the site sees is a stack
// trace from a part of MediaWiki it never named. This cannot catch a misspelled key: validate()
// walks the schema's keys rather than the file's, so a name core does not define is never visited.
foreach (MediaWiki\Extension\Wikven\SiteConfig::schemaErrors($wgSettings->validate()) as $wikvenBadSetting) {
	error_log("Wikven: WARNING in configuration: $wikvenBadSetting");
}

// Dedupe so each extension/skin loads at most once.
$config['extensions'] = array_values(array_unique(array_filter($config['extensions'], 'is_string'), SORT_STRING));
$config['skins'] = array_values(array_unique(array_filter($config['skins'], 'is_string'), SORT_STRING));

// A name in these two lists is a directory in this image, and both loops below turn it straight
// into a path: a name carrying a path separator resolves outside the image -- into the mounted
// source tree, say -- and is then loaded as if the image had shipped it. fetchExtensions.php
// already refuses such a name as a WikvenRepositories key, so refuse it where the loading actually
// happens too. SiteConfig::lint() is the other channel available and is the wrong one here: it only
// warns, and only about the site file, while these lists are that file merged with default.yml.
// error_log() is where this file reports the names it passes over, so a refused one lands beside
// them.

// Anything named below that is not on disk is a name whose settings nobody here can account for;
// the report at the end of this file says why that silences it. Collected rather than counted,
// because the build fails on it and has to be able to say which names: this file cannot fail on it
// itself, since fetchExtensions.php boots this file in order to install the very names that are
// missing at that point.
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

// Which skin the site is read in. A site names it the way MediaWiki does, with DefaultSkin, and the
// skins list says which to build -- so the two questions are asked separately, and neither answer is
// the other's by accident. Without one, the first built skin, which is the only answer a one-skin
// site needs. The name has to be one that was built: a default nothing renders leaves the output
// root with no pages in it at all, which is worse than reading the site in a skin you did not name.
$wikvenNamedSkin = $wikvenSiteData['config']['DefaultSkin'] ?? '';
if (!is_string($wikvenNamedSkin)) {
	$wikvenNamedSkin = '';
}
$wikvenFirstSkin = $wgWikvenSkins[0] ?? $wgDefaultSkin;
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
	// Source images are uploaded by the pass that populates the wiki, so by the time a skin
	// renders there is nothing left to upload -- and an "Upload file" link in the rendered
	// chrome would point at a Special: page the export does not have. Citizen's is the visible
	// one: it moves the entry out of the toolbox (which Hider empties) into the sidebar.
	$wgEnableUploads = false;
	// The passes run beside each other, and SQLite takes one writer at a time -- a pass still
	// writes to the object cache, which is a table. build.php hands each one a copy of the
	// database to work on and names its directory here; nothing reads the copies afterwards.
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

// Say which config names nothing defines, now that everything that could define one is queued.
// This is the quietest way a line in a site's file is lost: $wgSettings writes the name into a
// global, no code ever reads that global, and the build succeeds having ignored the line. Core is
// not in a position to report it -- validate() walks the schema's keys rather than the file's, and
// an extension's settings never reach that schema at all, ExtensionRegistry writing them straight
// to globals -- so the queued manifests are read here instead. getQueue() is the installer's, and
// used here because at this point in the boot it is the only complete answer to what is about to
// load; a wrong answer costs a warning either way and never a build.
//
// Silent while a name in this site's lists is missing from the image, because the extensions that
// would account for its settings are exactly the ones that are not there. fetchExtensions.php
// boots this file to install them, and so boots it before they exist.
if ($wikvenSiteFile !== null && $GLOBALS['wgWikvenMissing'] === []) {
	// Core's names are already in hand, and most of a config file is core settings. Only what they
	// leave over is worth opening two dozen manifests for, which is usually nothing at all -- and
	// this runs in every process a build starts.
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
