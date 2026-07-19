<?php
/**
 * Translate is an optional, image-bundled dependency that phan does not analyse, so its symbols
 * are undeclared to static analysis here. This script only runs when Translate is loaded.
 *
 * @phan-file-suppress PhanUndeclaredClassMethod
 * @phan-file-suppress PhanUndeclaredClassConstant
 * @phan-file-suppress PhanUndeclaredConstant
 */

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePageSettings;
use MediaWiki\Extension\Translate\PageTranslation\UpdateTranslatablePageJob;
use MediaWiki\Extension\Translate\Services as TranslateServices;
use MediaWiki\Extension\Translate\Statistics\MessageGroupStats;
use MediaWiki\Extension\Wikven\PageTranslation\StalenessComputer;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDBAccessObject;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Materialize content translations: mark each <translate> base page for translation, load the
 * translated units from "<Page>/<lang>.wikitext" source files (flagging stale ones fuzzy), and
 * render the translated pages so Translate's <languages/> and stats reflect them in the export.
 */
class BuildTranslations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Mark translatable pages and load their translations from source files.');
	}

	/** @return bool Always true; a page that cannot be marked is reported and skipped, not fatal. */
	public function execute() {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			return true;
		}
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		if ($source === '' || !is_dir($source)) {
			return true;
		}

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		RequestContext::getMain()->setUser($user);
		$isKnownLanguage = [$this->getServiceContainer()->getLanguageNameUtils(), 'isKnownLanguageTag'];

		foreach (TranslationSource::baseFiles($source) as $baseFile) {
			$relative = substr($baseFile, strlen($source) + 1);
			$title = Title::newFromText(SourceFile::filenameToTitle($relative));
			if (!$title) {
				$this->output("Wikven: skipping translatable page with invalid title: $relative\n");
				continue;
			}
			$languages = TranslationSource::translationLanguages($baseFile, $isKnownLanguage);
			$this->materialize($title, (string)file_get_contents($baseFile), $languages, $user);
		}

		return true;
	}

	/** Mark one page for translation, load its translations, and render the translated pages. */
	private function materialize(Title $title, string $sourceText, array $languages, User $user): void {
		$services = $this->getServiceContainer();

		// importWikitext saved the base as an old revision, which bypasses the PageSaveComplete hook
		// that writes the "ready for translation" tag. A normal edit restores it.
		$page = $services->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($sourceText, $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Prepare for translation'), EDIT_FORCE_BOT);

		$marker = TranslateServices::getInstance()->getTranslatablePageMarker();
		$record = $services->getPageStore()->getPageByReference($title, IDBAccessObject::READ_LATEST);
		if (!$record) {
			$this->output("Wikven: could not load {$title->getPrefixedText()} for translation; skipping\n");
			return;
		}
		$operation = $marker->getMarkOperation($record, null, true);
		if (!$operation->getUnitValidationStatus()->isOK()) {
			$this->output("Wikven: {$title->getPrefixedText()} has invalid translation units; skipping\n");
			return;
		}
		// Body units only: the file model has no place to author a title translation, and a
		// translatable title would leave every page short of 100%. No priority languages,
		// transclusion, or forced syntax upgrade either.
		$settings = new TranslatablePageSettings([], false, '', [], false, false, false);
		$marker->markForTranslation($operation, $settings, RequestContext::getMain(), $user);

		// markForTranslation only queues the update job; run the queue so the source units exist
		// before we fill in translations.
		$this->drainJobs();

		$this->loadTranslations($title, $sourceText, $languages, $user);

		// Render translated pages and refresh stats now that the units are in place.
		$translatable = TranslatablePage::newFromTitle($title);
		foreach (UpdateTranslatablePageJob::getRenderJobs($translatable, true) as $job) {
			$job->run();
		}
		MessageGroupStats::forGroup(
			$translatable->getMessageGroupId(),
			MessageGroupStats::FLAG_NO_CACHE | MessageGroupStats::FLAG_IMMEDIATE_WRITES
		);
		$this->output("Wikven: translated {$title->getPrefixedText()}\n");
	}

	/** Write each translated unit to its Translations: page, prefixing stale ones with !!FUZZY!!. */
	private function loadTranslations(Title $title, string $sourceText, array $languages, User $user): void {
		$services = $this->getServiceContainer();
		$sourceUnits = StalenessComputer::splitUnits($sourceText);
		$prefixed = $title->getPrefixedText();

		foreach ($languages as $lang) {
			$translationFile = TranslationSource::translationPath(
				rtrim($GLOBALS['wgWikvenSourceDirectory'], '/') . '/' . SourceFile::titleToFilename($prefixed),
				$lang
			);
			if (!is_file($translationFile)) {
				continue;
			}
			$translationText = (string)file_get_contents($translationFile);
			$units = StalenessComputer::splitUnits($translationText);

			$status = [];
			foreach (StalenessComputer::analyze($sourceText, $translationText) as $unit) {
				$status[$unit['id']] = $unit['status'];
			}

			foreach ($sourceUnits as $id => $sourceUnit) {
				$text = isset($units[$id]) ? trim($units[$id]['text']) : '';
				if ($text === '') {
					// Absent, or an empty (scaffolded, not-yet-filled) unit: leave it untranslated
					// so Translate renders the source language.
					continue;
				}
				if (( $status[(string)$id] ?? '' ) === StalenessComputer::STALE) {
					$text = TRANSLATE_FUZZY . $text;
				}
				$unitTitle = Title::makeTitle(NS_TRANSLATIONS, "$prefixed/$id/$lang");
				$unitPage = $services->getWikiPageFactory()->newFromTitle($unitTitle);
				$unitUpdater = $unitPage->newPageUpdater($user);
				$unitUpdater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $unitTitle));
				$unitUpdater->saveRevision(
					CommentStoreComment::newUnsavedComment('Import translation'),
					EDIT_FORCE_BOT
				);
			}
		}
	}

	/** Run every queued job synchronously (the export has no background runner). */
	private function drainJobs(): void {
		$group = $this->getServiceContainer()->getJobQueueGroup();
		$job = $group->pop();
		while ($job) {
			$job->run();
			$group->ack($job);
			$job = $group->pop();
		}
	}
}

$maintClass = BuildTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
