<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Report (and optionally gate on) out-of-date or missing translations in the source tree. */
class CheckTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Report translations that are out of date or missing.');
		$this->addOption('source', 'Source directory to check (default: $wgWikvenSourceDirectory).', false, true);
		$this->addOption('path-prefix', 'Prefix for reported file names, making them repo-relative.', false, true);
		$this->addOption('gate', 'Exit non-zero when any translation is stale or missing.');
	}

	/**
	 * @return bool Whether the run itself succeeded (staleness is reported, and gated, separately).
	 */
	public function execute() {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			$this->output("Translate is not enabled; nothing to check.\n");
			return true;
		}

		$source = rtrim((string)$this->getOption('source', $GLOBALS['wgWikvenSourceDirectory'] ?? ''), '/');
		if ($source === '' || !is_dir($source)) {
			$this->fatalError("Wikven: source directory '$source' does not exist.");
		}
		$prefix = (string)$this->getOption('path-prefix', '');
		$prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		$problems = 0;
		foreach (TranslationSource::baseFiles($source) as $baseFile) {
			$sourceText = (string)file_get_contents($baseFile);
			foreach (TranslationSource::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
				$translationFile = TranslationSource::translationPath($baseFile, $lang);
				$translationText = (string)file_get_contents($translationFile);
				$reportFile = $prefix . substr($translationFile, strlen($source) + 1);

				foreach (StalenessComputer::analyze($sourceText, $translationText) as $unit) {
					if ($unit['status'] === StalenessComputer::OK) {
						continue;
					}
					$problems++;
					// GitHub Actions annotation; a harmless plain line in any other console.
					$this->output(
						"::warning file=$reportFile::"
						. ucfirst($unit['status'])
						. " translation unit T:{$unit['id']} ($lang)\n"
					);
				}
			}
		}

		if ($problems === 0) {
			$this->output("All translations are up to date.\n");
			return true;
		}

		$this->output("\n$problems translation issue(s) found.\n");
		if ($this->hasOption('gate')) {
			$this->fatalError('Wikven: translations are out of date or missing (see annotations above).');
		}
		return true;
	}
}

$maintClass = CheckTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
