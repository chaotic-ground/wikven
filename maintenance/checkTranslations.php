<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Extension\Translate\PageTranslation\ParsingFailure;
use MediaWiki\Extension\Translate\Services as TranslateServices;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationAdvice;
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
		$this->addOption(
			'comment-file',
			'Write what was found as a Markdown comment body to post where the change is reviewed'
			. ' (see TranslationAdvice).',
			false,
			true
		);
		$this->addOption(
			'comment-paths',
			'File listing the paths the change touches, one per line; the comment is then only about'
			. ' those files and the translations of them.',
			false,
			true
		);
		$this->addOption(
			'comment-languages',
			'Languages to write that comment in, besides English: "auto" for the languages of the'
			. ' translations the change touches, or a comma-separated list of codes.',
			false,
			true
		);
	}

	/**
	 * Everything reported this run, for the comment body. The annotations above are one line each,
	 * on the file they belong to; the comment groups the same findings and says what to run.
	 *
	 * @var list<array{kind:string,file:string,unit?:string,lang?:string,line?:string,detail?:string}>
	 */
	private array $findings = [];

	/**
	 * The language each translation file in the tree is written in, keyed by the name the findings
	 * give the file: what an auto-languages comment reads its languages off.
	 *
	 * @var array<string,string>
	 */
	private array $translations = [];

	/**
	 * @return bool Whether the run itself succeeded (what it found is reported, and gated, separately).
	 */
	public function execute() {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			$this->output("Translate is not enabled; nothing to check.\n");
			return true;
		}

		$source = rtrim((string)$this->getOption('source', $this->getConfig()->get('WikvenSourceDirectory')), '/');
		if ($source === '' || !is_dir($source)) {
			$this->fatalError("Wikven: source directory '$source' does not exist.");
		}
		$prefix = (string)$this->getOption('path-prefix', '');
		$prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		// Counted apart because only one of them gates: a page that bakes wrong is the author's to fix,
		// while a translation falling behind is the translation system working.
		$errors = 0;
		$stale = 0;
		foreach (TranslationSource::baseFiles($source, $isKnownLanguage) as $baseFile) {
			$sourceText = (string)file_get_contents($baseFile);
			$languages = TranslationSource::translationLanguages($baseFile, $isKnownLanguage);
			// Recorded before anything can skip the page: a comment's languages come from the
			// translations a change touches, whatever state their source page is in.
			foreach ($languages as $lang) {
				$translationFile = TranslationSource::translationPath($baseFile, $lang);
				$this->translations[$prefix . substr($translationFile, strlen($source) + 1)] = $lang;
			}
			// A source page that marks a unit <!--T:title--> collides with the page-title unit, which
			// sourceUnits() refuses outright. Report the source file here rather than let every
			// translation of it fail, since the page is what has to be fixed.
			if (StalenessComputer::usesReservedId($sourceText)) {
				$errors++;
				$reportSource = $prefix . substr($baseFile, strlen($source) + 1);
				$reserved = StalenessComputer::TITLE_UNIT_ID;
				$this->findings[] = ['kind' => 'reserved', 'file' => $reportSource, 'unit' => $reserved];
				$this->output(
					"::error file=$reportSource::Reserved translation unit id T:$reserved"
					. " (it belongs to the page title); renumber that unit\n"
				);
				continue;
			}
			$errors += $this->checkSourcePage($prefix . substr($baseFile, strlen($source) + 1), $sourceText);
			$pageTitle = TranslationSource::translatableTitle($baseFile, $source, $sourceText);
			foreach ($languages as $lang) {
				$translationFile = TranslationSource::translationPath($baseFile, $lang);
				$translationText = (string)file_get_contents($translationFile);
				$reportFile = $prefix . substr($translationFile, strlen($source) + 1);

				// A tag the translation should not carry at all, which is not a unit falling behind but a unit
				// that will not be used: read before the units, since every unit in such a file reads as current.
				foreach (StalenessComputer::strayTranslateTags($translationText) as $line) {
					$errors++;
					$this->findings[] = [
						'kind' => 'markup',
						'file' => $reportFile,
						'source' => $prefix . substr($baseFile, strlen($source) + 1),
						'line' => (string)$line,
						'lang' => $lang
					];
					$this->output(
						"::error file=$reportFile,line=$line::A <translate> tag belongs to the source"
						. " page; here it is swallowed by the unit above it, which is then not used\n"
					);
				}

				foreach (StalenessComputer::analyze($sourceText, $translationText, $pageTitle) as $unit) {
					if ($unit['status'] === StalenessComputer::OK) {
						continue;
					}
					$stale++;
					// The source page is carried alongside so a comment kept to one change can tell which
					// translations that change is answerable for.
					$this->findings[] = [
						'kind' => $unit['status'],
						'file' => $reportFile,
						'source' => $prefix . substr($baseFile, strlen($source) + 1),
						'unit' => (string)$unit['id'],
						'lang' => $lang
					];
					// GitHub Actions annotation; a harmless plain line in any other console.
					$this->output(
						"::warning file=$reportFile::"
						. ucfirst($unit['status'])
						. " translation unit T:{$unit['id']} ($lang)\n"
					);
				}
			}

			// Named for a language but carrying none of the source's unit markers, so read as a page in its
			// own right. The code goes in as detail, not as lang: an auto-languages run must not write in it.
			foreach (TranslationSource::pagesNamedForALanguage($baseFile, $isKnownLanguage) as $lang => $page) {
				$reportPage = $prefix . substr($page, strlen($source) + 1);
				$this->findings[] = [
					'kind' => 'standalone',
					'file' => $reportPage,
					'source' => $prefix . substr($baseFile, strlen($source) + 1),
					'detail' => $lang
				];
				$this->output(
					"::notice file=$reportPage::Read as a page of its own rather than as the $lang"
					. " translation of this page's parent; it carries no <!--T:n--> marker\n"
				);
			}
		}

		$this->writeComment();
		if ($errors === 0 && $stale === 0) {
			$this->output("All translations are up to date.\n");
			return true;
		}

		$summary = [];
		if ($errors > 0) {
			$summary[] = "$errors error(s)";
		}
		if ($stale > 0) {
			$summary[] = "$stale translation(s) out of date or missing";
		}
		$this->output("\n" . implode(', ', $summary) . ".\n");
		if ($errors > 0 && $this->hasOption('gate')) {
			$this->fatalError('Wikven: the source tree has translation errors (see annotations above).');
		}
		return true;
	}

	/**
	 * Write the comment body for --comment-file, or nothing when the option is not given.
	 *
	 * A clean run still writes one, carrying CLEAR_MARKER: a consumer could not otherwise tell an
	 * answered complaint from a run that never happened.
	 */
	private function writeComment(): void {
		$path = (string)$this->getOption('comment-file', '');
		if ($path === '') {
			return;
		}
		$advice = TranslationAdvice::usingMessages();
		$paths = $this->commentPaths();
		if ($paths !== null) {
			$advice = $advice->about($paths);
		}
		$languages = $this->commentLanguages($advice);
		$body = $advice->comment($this->findings, $languages) ?? $advice->allClear($languages);
		if (file_put_contents($path, $body) === false) {
			$this->fatalError("Wikven: could not write the comment body to '$path'.");
		}
	}

	/**
	 * The paths --comment-paths named, or null when it named none and the comment is about the
	 * whole tree.
	 *
	 * A run that cannot read the file comments about everything, which is what this narrows.
	 *
	 * @return list<string>|null
	 */
	private function commentPaths(): ?array {
		$path = (string)$this->getOption('comment-paths', '');
		if ($path === '') {
			return null;
		}
		if (!is_readable($path)) {
			$this->output("::warning::Cannot read '$path'; the translations comment covers every page\n");
			return null;
		}
		$paths = [];
		foreach (explode("\n", (string)file_get_contents($path)) as $line) {
			$line = trim($line);
			if ($line !== '') {
				$paths[] = $line;
			}
		}
		return $paths;
	}

	/**
	 * The languages the comment is written in: English, then whatever --comment-languages asked for.
	 *
	 * English leads as the one language a reader of the change is likely to share; "auto" adds
	 * those of the translations the change touches.
	 *
	 * @return list<string>
	 */
	private function commentLanguages(TranslationAdvice $advice): array {
		$languages = ['en'];
		$option = trim((string)$this->getOption('comment-languages', ''));
		if ($option === '') {
			return $languages;
		}
		$wanted = $option === 'auto'
			? $advice->languagesFor($this->translations, $this->findings)
			: array_map('trim', explode(',', $option));
		sort($wanted);

		$languageNameUtils = $this->getServiceContainer()->getLanguageNameUtils();
		foreach ($wanted as $language) {
			if ($language === '' || in_array($language, $languages, true)) {
				continue;
			}
			// A code nobody knows is the caller's typo, not a reason to lose the comment: the
			// English half is what most readers of the change read anyway.
			if (!$languageNameUtils->isKnownLanguageTag($language)) {
				$this->output("::warning::Unknown language '$language' for the translations comment; skipped\n");
				continue;
			}
			$languages[] = $language;
		}
		return $languages;
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
			$this->findings[] = [
				'kind' => 'parse',
				'file' => $reportFile,
				'detail' => $failure->getMessage()
			];
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
			$this->findings[] = [
				'kind' => 'unmarked',
				'file' => $reportFile,
				'detail' => "$unmarked unit(s)"
			];
			$this->output(
				"::error file=$reportFile::$unmarked translation unit(s) have no <!--T:n--> marker;"
				. " run translate mark\n"
			);
		}
		if (array_keys($wikven) !== array_keys($translate)) {
			$errors++;
			$this->findings[] = [
				'kind' => 'disagree',
				'file' => $reportFile,
				'detail' =>
					'Translate reads '
						. $this->unitList(array_keys($translate))
						. ' where wikven reads '
						. $this->unitList(array_keys($wikven))
			];
			$this->output(
				"::error file=$reportFile::Translate reads units "
				. $this->unitList(array_keys($translate))
				. ' where wikven reads '
				. $this->unitList(array_keys($wikven))
				. "\n"
			);
		} elseif ($wikven !== $translate) {
			$errors++;
			$this->findings[] = [
				'kind' => 'disagree',
				'file' => $reportFile,
				'detail' => 'different text for ' . $this->unitList(array_keys(array_diff_assoc($wikven, $translate)))
			];
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
