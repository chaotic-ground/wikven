<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Create or extend a translation skeleton: a <!--T:n--> marker with an empty body per source unit. */
class ScaffoldTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Write empty <!--T:n--> translation skeletons for a language.');
		$this->addOption('source', 'Source directory (default: $wgWikvenSourceDirectory).', false, true);
		$this->addOption('all', 'Scaffold every translatable page.');
		$this->addArg('language', 'Target language code, e.g. ko.');
		$this->addArg('file', 'A single source file to scaffold (omit when using --all).', false);
	}

	public function execute() {
		$source = rtrim((string)$this->getOption('source', $GLOBALS['wgWikvenSourceDirectory'] ?? ''), '/');

		$language = $this->getArg(0);
		if ($language === null) {
			$this->fatalError('Wikven: pass a language code, then a source file or --all.');
		}
		if (!$this->getServiceContainer()->getLanguageNameUtils()->isKnownLanguageTag($language)) {
			$this->fatalError("Wikven: '$language' is not a known language code.");
		}

		if ($this->hasOption('all')) {
			if ($source === '' || !is_dir($source)) {
				$this->fatalError("Wikven: source directory '$source' does not exist.");
			}
			foreach (TranslationSource::baseFiles($source) as $baseFile) {
				$this->scaffoldFile($baseFile, $language);
			}
			return;
		}

		$file = $this->getArg(1);
		if ($file === null) {
			$this->fatalError('Wikven: pass a source file, or --all.');
		}
		if (!str_starts_with($file, '/')) {
			$file = "$source/$file";
		}
		if (!is_file($file)) {
			$this->fatalError("Wikven: '$file' does not exist.");
		}
		$this->scaffoldFile($file, $language);
	}

	/** Scaffold one base page's translation for the language, then list its source units as a guide. */
	private function scaffoldFile(string $baseFile, string $language): void {
		$sourceText = (string)file_get_contents($baseFile);
		if (!TranslationSource::isTranslatable($sourceText)) {
			$this->output("not translatable, skipped: $baseFile\n");
			return;
		}

		$translationFile = TranslationSource::translationPath($baseFile, $language);
		$existing = is_file($translationFile) ? (string)file_get_contents($translationFile) : null;
		$scaffolded = StalenessComputer::scaffold($sourceText, $existing);
		if ($existing !== null && $scaffolded === $existing) {
			$this->output("unchanged: $translationFile\n");
			return;
		}

		$directory = dirname($translationFile);
		if (!is_dir($directory) && !wfMkdirParents($directory)) {
			$this->fatalError("Wikven: could not create directory $directory");
		}
		file_put_contents($translationFile, $scaffolded);
		$this->output("scaffolded: $translationFile\n");

		// List each source unit so the translator knows what each marker is for.
		foreach (StalenessComputer::splitUnits($sourceText) as $id => $unit) {
			$text = preg_replace('/<\/?translate[^>]*>/', '', $unit['text']);
			$preview = trim(preg_replace('/\s+/', ' ', $text));
			if (mb_strlen($preview) > 80) {
				$preview = mb_substr($preview, 0, 79) . '…';
			}
			$this->output("  T:$id  $preview\n");
		}
	}
}

$maintClass = ScaffoldTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
