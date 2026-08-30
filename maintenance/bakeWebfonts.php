<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\Webfonts\FontCopier;
use MediaWiki\Extension\Wikven\Webfonts\FontRepository;
use MediaWiki\Registration\ExtensionRegistry;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Bake UniversalLanguageSelector webfonts into the export as a plain stylesheet:
 * @font-face plus :lang() rules for the languages the pages use, with the woff2
 * files copied alongside. It reproduces the font ULS would apply by default,
 * without shipping ULS's runtime JavaScript (which pulls fonts from load.php,
 * an endpoint a static host cannot serve).
 *
 * Opt-in via $wgWikvenBundleWebfonts, since it adds font payload; a no-op when
 * off or when ULS is not installed. The stylesheet is linked like site.styles.css
 * (rewriteScripts wires it in, rename's reparenting keeps the <link> correct at
 * any subpage depth), and its font url()s resolve against the stylesheet itself,
 * so they hold from every page regardless of depth or host subdirectory.
 */
class BakeWebfonts extends Maintenance {
	/** Output subdirectory (under the dist root) the woff2 files are copied into. */
	private const FONTS_SUBDIR = 'fonts/uls';

	public function __construct() {
		parent::__construct();
		$this->addDescription('Bake ULS webfonts for the export languages into a static stylesheet.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory, $wgWikvenAssetDirectory, $wgLanguageCode;

		if (!$this->getConfig()->get('WikvenBundleWebfonts')) {
			return;
		}
		if (!ExtensionRegistry::getInstance()->isLoaded('UniversalLanguageSelector')) {
			$this->output("Wikven: UniversalLanguageSelector not installed; skipping webfont bundling.\n");
			return;
		}

		$ulsDir = $this->ulsDirectory();
		if ($ulsDir === null) {
			$this->output("Wikven: could not locate UniversalLanguageSelector; skipping webfont bundling.\n");
			return;
		}
		$repository = $this->readRepository($ulsDir);
		if ($repository === null) {
			$this->output("Wikven: could not read the ULS font repository; skipping webfont bundling.\n");
			return;
		}

		$htmlDir = rtrim($wgWikvenHtmlDirectory, '/');
		$cssDir = AssetFile::path($htmlDir, $wgWikvenAssetDirectory);

		$languages = $this->discoverLanguages($htmlDir, $wgLanguageCode);

		// The woff2 live at <htmlDir>/fonts/uls/...; the stylesheet sits in $cssDir, so its url()s
		// step up out of any asset subdirectory to reach them.
		$basePath = str_repeat('../', AssetFile::depth($wgWikvenAssetDirectory)) . self::FONTS_SUBDIR . '/';

		$built = ( new FontRepository($repository) )->build($languages, $basePath);
		if (trim($built['css']) === '' || $built['files'] === []) {
			$this->output("Wikven: no bundled webfonts apply to the export's languages.\n");
			return;
		}

		$missing = FontCopier::copy("$ulsDir/data/fontrepo/fonts", "$htmlDir/" . self::FONTS_SUBDIR, $built['files']);
		// The stylesheet names every one of these files, so shipping it without them gives the
		// reader the tofu the site opted into bundled fonts to spare them. The site asked for these
		// fonts; a build that cannot deliver them has not done what it was asked, and says so.
		if ($missing !== []) {
			$counts = count($missing) . ' of ' . count($built['files']);
			$this->fatalError("Wikven: $counts webfont file(s) could not be copied: " . implode(', ', $missing));
		}

		if (!is_dir($cssDir) && !wfMkdirParents($cssDir)) {
			$this->fatalError("Wikven: could not create asset directory $cssDir");
		}
		file_put_contents("$cssDir/webfonts.css", $built['css'], LOCK_EX);
		$this->output('Wrote webfonts.css (' . count($built['files']) . " font file(s))\n");
	}

	/**
	 * The install directory of the UniversalLanguageSelector extension, or null if not found.
	 *
	 * @return string|null
	 */
	private function ulsDirectory(): ?string {
		$things = ExtensionRegistry::getInstance()->getAllThings();
		$path = $things['UniversalLanguageSelector']['path'] ?? null;
		if (!is_string($path) || !is_file($path)) {
			return null;
		}
		return dirname($path);
	}

	/**
	 * Read ULS's generated font-repository module, whose object literal is valid JSON.
	 *
	 * @param string $ulsDir
	 * @return array|null The decoded repository (languages/fonts maps), or null on any failure.
	 */
	private function readRepository(string $ulsDir): ?array {
		$file = "$ulsDir/resources/js/ext.uls.webfonts.repository.js";
		if (!is_file($file)) {
			return null;
		}
		$js = file_get_contents($file);
		if ($js === false) {
			return null;
		}
		// The file assigns `$.webfonts.repository = { ... };`; capture that object literal.
		if (!preg_match('/\.repository\s*=\s*(\{.*\})\s*;/s', $js, $m)) {
			return null;
		}
		$data = json_decode($m[1], true);
		return is_array($data) ? $data : null;
	}

	/**
	 * The set of languages the export renders in: the content language plus the lang of every
	 * rendered page (translated pages carry their own). Read from the <html lang="..."> tag.
	 *
	 * @param string $htmlDir
	 * @param string $contentLang
	 * @return string[]
	 */
	private function discoverLanguages(string $htmlDir, string $contentLang): array {
		$languages = [$contentLang];
		foreach (glob("$htmlDir/*.html") as $file) {
			// The <html> tag is at the top; a small read avoids loading whole pages.
			$head = file_get_contents($file, false, null, 0, 2048);
			if ($head !== false && preg_match('/<html[^>]*\blang="([^"]+)"/', $head, $m)) {
				$languages[] = $m[1];
			}
		}
		return $languages;
	}
}

$maintClass = BakeWebfonts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
