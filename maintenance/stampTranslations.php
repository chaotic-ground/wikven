<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Rewrite one translation's marker stamps (@hash) to the current source unit hashes.
 *
 * A stamp is not a fact the tool can work out: it records that whoever wrote the translation wrote
 * it against this version of the source. Translate keeps that record in the database when a
 * translator saves through the wiki; a translation that arrives as a committed file never passes
 * through there, so this command writes it instead -- and only the person who did the reading can
 * say it is true. So it takes the translation they read, one file, and has no sweep: a sweep would
 * be that assertion made about pages nobody opened, which is how a stale translation comes to call
 * itself current.
 */
class StampTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Record the source version a translation you have read was written against.');
		$this->addOption('source', 'Source directory (default: $wgWikvenSourceDirectory).', false, true);
		$this->addArg('file', 'The translation file to stamp.', true);
	}

	public function execute() {
		$source = rtrim((string)$this->getOption('source', $GLOBALS['wgWikvenSourceDirectory'] ?? ''), '/');

		$translationFile = $this->getArg(0);
		if ($translationFile === null) {
			$this->fatalError('Wikven: pass the translation file to stamp.');
		}
		// A relative path is taken within the source directory (what the src mount maps to).
		if (!str_starts_with($translationFile, '/')) {
			$translationFile = "$source/$translationFile";
		}
		if (!is_file($translationFile)) {
			$this->fatalError("Wikven: '$translationFile' does not exist.");
		}
		// A translation "<Page>/<lang>.wikitext" restamps against its base "<Page>.wikitext".
		$baseFile = preg_replace('#/[^/]+\.wikitext$#', '.wikitext', $translationFile);
		if ($baseFile === $translationFile || !is_file($baseFile)) {
			$this->fatalError("Wikven: no base page found for '$translationFile'.");
		}
		$sourceText = (string)file_get_contents($baseFile);
		$this->restampFile(
			$sourceText,
			$translationFile,
			TranslationSource::translatableTitle($baseFile, $source, $sourceText)
		);
	}

	/** Restamp one translation file in place, reporting whether it changed. */
	private function restampFile(string $sourceText, string $translationFile, ?string $pageTitle): void {
		$before = (string)file_get_contents($translationFile);
		$after = StalenessComputer::restamp($sourceText, $before, $pageTitle);
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
