<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Copy the UniversalLanguageSelector webfonts the exported languages need next to the HTML. */
class StoreWebfonts extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Make the ULS webfonts for the site\'s languages local to the export.');
	}

	/**
	 * @return bool Whether every font that was asked for is now in the output directory.
	 */
	public function execute() {
		$loaded = ExtensionRegistry::getInstance()->isLoaded('UniversalLanguageSelector');
		if (!$loaded || !( $GLOBALS['wgULSWebfontsEnabled'] ?? false )) {
			return true;
		}
		$uls = $this->getServiceContainer()->getMainConfig()->get('ExtensionDirectory') . '/UniversalLanguageSelector';
		$dir = rtrim((string)$GLOBALS['wgWikvenHtmlDirectory'], '/');
		if ($dir === '' || !is_dir($uls)) {
			return true;
		}

		$repositoryFile = "$uls/" . Webfonts::REPOSITORY_FILE;
		$repository = is_file($repositoryFile)
			? Webfonts::repository((string)file_get_contents($repositoryFile))
			: null;
		if ($repository === null) {
			$this->output("Wikven: cannot read the ULS font repository; shipping no webfonts\n");
			return false;
		}

		$languages = $this->languages();
		$families = Webfonts::familiesFor($repository, $languages);
		if (!$families) {
			$this->output(
				'Wikven: no webfont needed for ' . implode(', ', $languages) . "; the system fonts cover them\n"
			);
			return true;
		}

		$target = "$dir/" . Webfonts::OUTPUT_DIR;
		$ok = true;
		$bytes = 0;
		foreach (Webfonts::filesFor($repository, $families) as $family => $relative) {
			$source = "$uls/" . Webfonts::FONTS_DIR . "/$relative";
			if (!is_file($source)) {
				$this->output("Wikven: missing webfont for $family ($relative)\n");
				$ok = false;
				continue;
			}
			$destination = "$target/$relative";
			if (!wfMkdirParents(dirname($destination))) {
				$this->output("Wikven: could not create $destination\n");
				$ok = false;
				continue;
			}
			copy($source, $destination);
			$bytes += (int)filesize($source);
			$this->copyLicense($uls, dirname($source), dirname($destination));
		}

		$this->output(sprintf(
			"Stored %d webfont(s) for %s (%.0f KiB)\n",
			count($families),
			implode(', ', $languages),
			$bytes / 1024
		));
		return $ok;
	}

	/**
	 * The languages the export contains: the content language plus every translation in the source.
	 *
	 * @return string[]
	 */
	private function languages(): array {
		$languages = [(string)$GLOBALS['wgLanguageCode']];
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		if ($source !== '' && is_dir($source)) {
			$isKnown = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];
			foreach (TranslationSource::baseFiles($source) as $baseFile) {
				$languages = array_merge($languages, TranslationSource::translationLanguages($baseFile, $isKnown));
			}
		}
		$languages = array_values(array_unique($languages));
		sort($languages);
		return $languages;
	}

	/** Copy the license text a family's font.ini names, so the fonts do not travel without it. */
	private function copyLicense(string $uls, string $familyDir, string $destinationDir): void {
		$ini = "$familyDir/font.ini";
		if (!is_file($ini)) {
			return;
		}
		$name = Webfonts::licenseFile((string)file_get_contents($ini));
		if ($name === null) {
			return;
		}
		// A family may keep its own copy; otherwise the text is one of the repository's shared ones.
		$source = is_file("$familyDir/$name") ? "$familyDir/$name" : "$uls/" . Webfonts::LICENSES_DIR . "/$name";
		if (is_file($source)) {
			copy($source, "$destinationDir/$name");
		}
	}
}

$maintClass = StoreWebfonts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
