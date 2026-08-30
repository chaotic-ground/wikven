<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Insert <!--T:n--> markers into the still-unmarked units of translatable source pages. */
class MarkTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Number the unmarked <translate> units of source pages with <!--T:n--> markers.');
		$this->addOption('source', 'Source directory (default: $wgWikvenSourceDirectory).', false, true);
		$this->addOption('all', 'Mark every translatable page under the source directory.');
		$this->addArg('file', 'A single source file to mark (omit when using --all).', false);
	}

	public function execute() {
		$source = rtrim((string)$this->getOption('source', $GLOBALS['wgWikvenSourceDirectory'] ?? ''), '/');
		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		if ($this->hasOption('all')) {
			if ($source === '' || !is_dir($source)) {
				$this->fatalError("Wikven: source directory '$source' does not exist.");
			}
			foreach (TranslationSource::baseFiles($source, $isKnownLanguage) as $baseFile) {
				$this->markFile($baseFile, $isKnownLanguage);
			}
			return;
		}

		$file = $this->getArg(0);
		if ($file === null) {
			$this->fatalError('Wikven: pass a source file, or --all.');
		}
		// A relative path is taken within the source directory (what the src mount maps to).
		if (!str_starts_with($file, '/')) {
			$file = "$source/$file";
		}
		if (!is_file($file)) {
			$this->fatalError("Wikven: '$file' does not exist.");
		}
		$this->markFile($file, $isKnownLanguage);
	}

	/**
	 * Mark one file in place, reporting whether it changed.
	 *
	 * @param string $file
	 * @param callable(string):bool $isKnownLanguage
	 */
	private function markFile(string $file, callable $isKnownLanguage): void {
		$before = (string)file_get_contents($file);
		$after = StalenessComputer::mark($before, $this->translationsOf($file, $isKnownLanguage));
		if ($after === $before) {
			$this->output("unchanged: $file\n");
			return;
		}
		file_put_contents($file, $after);
		$this->output("marked:    $file\n");
	}

	/**
	 * The text of every translation a page already has.
	 *
	 * Numbering reads them because a number one of them still carries stays taken even after the unit
	 * it was written for is deleted; a file that is not a base page simply has none.
	 *
	 * @param string $baseFile
	 * @param callable(string):bool $isKnownLanguage
	 * @return string[]
	 */
	private function translationsOf(string $baseFile, callable $isKnownLanguage): array {
		$translations = [];
		foreach (TranslationSource::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
			$translations[] = (string)file_get_contents(TranslationSource::translationPath($baseFile, $lang));
		}
		return $translations;
	}
}

$maintClass = MarkTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
