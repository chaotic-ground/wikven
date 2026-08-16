<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Import\WikiRevision;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

class ImportWikitext extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Import *.wikitext files from the given path');
	}

	/**
	 * @return bool Whether every file was imported successfully.
	 */
	public function execute() {
		global $wgWikvenSourceDirectory, $wgWikvenSourceHistoryFile;
		$sourceDirectory = rtrim($wgWikvenSourceDirectory, '/');
		$history = SourceHistory::forSource($sourceDirectory, (string)$wgWikvenSourceHistoryFile);

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		RequestContext::getMain()->setUser($user);
		$authors = new SourceAuthors($this->getServiceContainer()->getUserFactory(), $user);

		$failed = [];
		foreach ($this->wikitextFiles($sourceDirectory) as $filename) {
			$title = Title::newFromText($this->filenameToTitle($filename, $sourceDirectory));
			if (!$title) {
				$this->output('Invalid title: ' . basename($filename) . "\n");
				$failed[] = basename($filename);
				continue;
			}

			// Warn if the title won't round-trip to the filename; export edit/history links would 404.
			$relative = substr($filename, strlen($sourceDirectory) + 1);
			if (SourceFile::titleToFilename($title->getPrefixedText()) !== $relative) {
				$this->output(
					"Warning: '$relative' imports as page '{$title->getPrefixedText()}'; "
					. "edit/history links may not resolve back to the file.\n"
				);
			}

			$text = file_get_contents($filename);
			$content = ContentHandler::makeContent($text, $title);

			// What the footer's "last edited" line ends up reporting. The commit that last touched
			// the file is the answer git gives, and the one the "View history" link leads to; with
			// no history to read, the frozen build clock dates the page at the commit being built,
			// which is true of the export as a whole (see SourceHistory and WikvenSettings.php).
			$timestamp = $history->timestamp($relative) ?? ConvertibleTimestamp::time();
			$editor = $authors->accountFor($history->author($relative));

			$this->output("Saving... $title");

			// File:/MediaWiki: pages need a current-revision edit so upload desc and edit hooks apply.
			if ($title->getNamespace() === NS_FILE || $title->getNamespace() === NS_MEDIAWIKI) {
				$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
				$updater = $page->newPageUpdater($editor);
				$updater->setContent(SlotRecord::MAIN, $content);
				// PageUpdater stamps the revision from the clock and offers no way to set it, so the
				// frozen clock moves to the file's own date for the length of the save. setFakeTime()
				// hands back what it replaced, so this puts the build's own instant back exactly.
				$frozen = ConvertibleTimestamp::setFakeTime($timestamp);
				try {
					$updater->saveRevision(CommentStoreComment::newUnsavedComment('Import'));
				} finally {
					ConvertibleTimestamp::setFakeTime($frozen ?? false);
				}
				// These are exactly the pages an edit hook may veto -- AbuseFilter or SpamBlacklist on a
				// File: description, CSS validation on MediaWiki:Common.css.
				if ($updater->wasSuccessful()) {
					$this->output(" done\n");
				} else {
					$status = $updater->getStatus();
					$reason = $status ? $status->getWikiText(false, false, 'en') : 'no revision was saved';
					$this->output(' failed: ' . $reason . "\n");
					$failed[] = $relative;
				}
				continue;
			}

			// Import as an old revision, which is the only path that takes a timestamp of its own.
			$revision = new WikiRevision();
			$revision->setContent(SlotRecord::MAIN, $content);
			$revision->setTitle($title);
			$revision->setUserObj($editor);
			$revision->setComment('');
			$revision->setTimestamp(wfTimestamp(TS_MW, $timestamp));

			// WikiRevision::importOldRevision() has been a deprecated shim for this service since 1.31.
			$importer = $this->getServiceContainer()->getWikiRevisionOldRevisionImporter();
			if ($importer->import($revision)) {
				$this->output(" done\n");
			} else {
				$this->output(" failed\n");
				$failed[] = $relative;
			}
		}

		if ($failed) {
			// Fail loudly so build.php's step() aborts; a silent partial export is worse than none.
			$this->error(
				'Failed to import ' . count($failed) . " page(s):\n  " . implode("\n  ", $failed)
			);
			return false;
		}

		return true;
	}

	/**
	 * Find every page file under the source directory, recursing into subpages.
	 *
	 * @return string[] Absolute paths, sorted for a stable import order.
	 */
	private function wikitextFiles(string $sourceDirectory): array {
		// With Translate enabled, translation files (<Page>/<lang>.wikitext) are not imported as
		// pages; the build's materialize step renders them into the generated <Page>/<lang> pages.
		$isTranslation = static function (string $unused): bool {
			return false;
		};
		if (ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			$languageNameUtils = $this->getServiceContainer()->getLanguageNameUtils();
			$isTranslation = static function (string $path) use ($languageNameUtils): bool {
				return TranslationSource::isTranslationFile($path, [$languageNameUtils, 'isKnownLanguageTag']);
			};
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($iterator as $file) {
			$pathname = $file->getPathname();
			$relative = substr($pathname, strlen($sourceDirectory) + 1);
			if ($file->isFile() && SourceFile::isPageFile($relative) && !$isTranslation($pathname)) {
				$files[] = $pathname;
			}
		}
		sort($files);
		return $files;
	}

	/** Map a page file to its title via SourceFile's naming convention. */
	private function filenameToTitle(string $name, string $sourceDirectory): string {
		$relative = substr($name, strlen($sourceDirectory) + 1);
		return SourceFile::filenameToTitle($relative);
	}
}

$maintClass = ImportWikitext::class;
require_once RUN_MAINTENANCE_IF_MAIN;
