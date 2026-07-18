<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Rewrite translation marker stamps (@hash) to the current source unit hashes, marking them fresh. */
class StampTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Update translation marker stamps to the current source unit hashes.');
		$this->addOption('source', 'Source directory (default: $wgWikvenSourceDirectory).', false, true);
		$this->addOption('all', 'Restamp every translation under the source directory.');
		$this->addArg('file', 'A single translation file to restamp (omit when using --all).', false);
	}

	public function execute() {
		$source = rtrim((string)$this->getOption('source', $GLOBALS['wgWikvenSourceDirectory'] ?? ''), '/');

		if ($this->hasOption('all')) {
			if ($source === '' || !is_dir($source)) {
				$this->fatalError("Wikven: source directory '$source' does not exist.");
			}
			$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];
			foreach (TranslationSource::baseFiles($source) as $baseFile) {
				$sourceText = (string)file_get_contents($baseFile);
				foreach (TranslationSource::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
					$this->restampFile($sourceText, TranslationSource::translationPath($baseFile, $lang));
				}
			}
			return;
		}

		$translationFile = $this->getArg(0);
		if ($translationFile === null) {
			$this->fatalError('Wikven: pass a translation file, or --all.');
		}
		if (!is_file($translationFile)) {
			$this->fatalError("Wikven: '$translationFile' does not exist.");
		}
		// A translation "<Page>/<lang>.wikitext" restamps against its base "<Page>.wikitext".
		$baseFile = preg_replace('#/[^/]+\.wikitext$#', '.wikitext', $translationFile);
		if ($baseFile === $translationFile || !is_file($baseFile)) {
			$this->fatalError("Wikven: no base page found for '$translationFile'.");
		}
		$this->restampFile((string)file_get_contents($baseFile), $translationFile);
	}

	/** Restamp one translation file in place, reporting whether it changed. */
	private function restampFile(string $sourceText, string $translationFile): void {
		$before = (string)file_get_contents($translationFile);
		$after = StalenessComputer::restamp($sourceText, $before);
		if ($after === $before) {
			$this->output("unchanged: $translationFile\n");
			return;
		}
		file_put_contents($translationFile, $after);
		$this->output("stamped:   $translationFile\n");
	}
}

$maintClass = StampTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
