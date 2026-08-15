<?php
/**
 * Translate is an optional, image-bundled dependency that phan does not analyse, so its symbols
 * are undeclared to static analysis here. Reading a page with Translate's own parser only happens
 * when Translate is loaded.
 *
 * @phan-file-suppress PhanUndeclaredClassMethod
 * @phan-file-suppress PhanUndeclaredClassCatch
 */

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Translate\PageTranslation\ParsingFailure;
use MediaWiki\Extension\Translate\Services as TranslateServices;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Report broken source pages, and out-of-date or missing translations, in the source tree. */
class CheckTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Report broken source pages, and translations that are out of date or missing.');
		$this->addOption('source', 'Source directory to check (default: $wgWikvenSourceDirectory).', false, true);
		$this->addOption('path-prefix', 'Prefix for reported file names, making them repo-relative.', false, true);
		$this->addOption('gate', 'Exit non-zero when a source page has an error. Staleness never gates.');
	}

	/**
	 * @return bool Whether the run itself succeeded (what it found is reported, and gated, separately).
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

		// Counted apart because only one of them gates: a broken source page is the author's to fix
		// before it bakes wrong, while a translation falling behind is the translation system working.
		$errors = 0;
		$stale = 0;
		foreach (TranslationSource::baseFiles($source, $isKnownLanguage) as $baseFile) {
			$sourceText = (string)file_get_contents($baseFile);
			// A source page that marks a unit <!--T:title--> collides with the page-title unit, which
			// sourceUnits() refuses outright. Report the source file here rather than let every
			// translation of it fail, since the page is what has to be fixed.
			if (StalenessComputer::usesReservedId($sourceText)) {
				$errors++;
				$reportSource = $prefix . substr($baseFile, strlen($source) + 1);
				$reserved = StalenessComputer::TITLE_UNIT_ID;
				$this->output(
					"::error file=$reportSource::Reserved translation unit id T:$reserved"
					. " (it belongs to the page title); renumber that unit\n"
				);
				continue;
			}
			$errors += $this->checkSourcePage($prefix . substr($baseFile, strlen($source) + 1), $sourceText);
			$pageTitle = TranslationSource::translatableTitle($baseFile, $source, $sourceText);
			foreach (TranslationSource::translationLanguages($baseFile, $isKnownLanguage) as $lang) {
				$translationFile = TranslationSource::translationPath($baseFile, $lang);
				$translationText = (string)file_get_contents($translationFile);
				$reportFile = $prefix . substr($translationFile, strlen($source) + 1);

				foreach (StalenessComputer::analyze($sourceText, $translationText, $pageTitle) as $unit) {
					if ($unit['status'] === StalenessComputer::OK) {
						continue;
					}
					$stale++;
					// GitHub Actions annotation; a harmless plain line in any other console.
					$this->output(
						"::warning file=$reportFile::"
						. ucfirst($unit['status'])
						. " translation unit T:{$unit['id']} ($lang)\n"
					);
				}
			}
		}

		if ($errors === 0 && $stale === 0) {
			$this->output("All translations are up to date.\n");
			return true;
		}

		$summary = [];
		if ($errors > 0) {
			$summary[] = "$errors source page error(s)";
		}
		if ($stale > 0) {
			$summary[] = "$stale translation(s) out of date or missing";
		}
		$this->output("\n" . implode(', ', $summary) . ".\n");
		if ($errors > 0 && $this->hasOption('gate')) {
			$this->fatalError('Wikven: source pages have errors (see annotations above).');
		}
		return true;
	}

	/**
	 * Read a source page with Translate's own parser and report where wikven would read it
	 * differently, so the author hears it here rather than from a page that bakes wrong.
	 *
	 * @return int Errors found.
	 */
	private function checkSourcePage(string $reportFile, string $sourceText): int {
		try {
			$output = TranslateServices::getInstance()->getTranslatablePageParser()->parse($sourceText);
		} catch (ParsingFailure $failure) {
			// The bake skips such a page, leaving it untranslated; nothing downstream would say why.
			$this->output(
				"::error file=$reportFile::Translate cannot parse this page: {$failure->getMessage()}\n"
			);
			return 1;
		}

		// Same ids and same hashes means the two readings agree on every byte staleness turns on.
		// No page title is passed, so the title unit Translate has no counterpart for is never added.
		$translate = [];
		$unmarked = 0;
		foreach ($output->units() as $unit) {
			if ((string)$unit->id === '-1') {
				$unmarked++;
			} else {
				$translate[(string)$unit->id] = StalenessComputer::hashUnit($unit->text);
			}
		}
		$wikven = [];
		foreach (StalenessComputer::sourceUnits($sourceText) as $id => $unit) {
			$wikven[(string)$id] = StalenessComputer::hashUnit($unit['text']);
		}

		$errors = 0;
		if ($unmarked > 0) {
			$errors++;
			$this->output(
				"::error file=$reportFile::$unmarked translation unit(s) have no <!--T:n--> marker;"
				. " run translate mark\n"
			);
		}
		if (array_keys($wikven) !== array_keys($translate)) {
			$errors++;
			$this->output(
				"::error file=$reportFile::Translate reads units "
				. $this->unitList(array_keys($translate))
				. ' where wikven reads '
				. $this->unitList(array_keys($wikven))
				. "\n"
			);
		} elseif ($wikven !== $translate) {
			$errors++;
			$this->output(
				"::error file=$reportFile::Translate and wikven read different text for unit(s) "
				. $this->unitList(array_keys(array_diff_assoc($wikven, $translate)))
				. "\n"
			);
		}
		return $errors;
	}

	/**
	 * @param list<string> $ids
	 */
	private function unitList(array $ids): string {
		return $ids === [] ? '(none)' : 'T:' . implode(', T:', $ids);
	}
}

$maintClass = CheckTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
