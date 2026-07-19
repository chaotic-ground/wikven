<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use WikiRevision;

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
		global $wgWikvenSourceDirectory;
		$sourceDirectory = rtrim($wgWikvenSourceDirectory, '/');

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		RequestContext::getMain()->setUser($user);

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

			$this->output("Saving... $title");

			// File:/MediaWiki: pages need a current-revision edit so upload desc and edit hooks apply.
			if ($title->getNamespace() === NS_FILE || $title->getNamespace() === NS_MEDIAWIKI) {
				$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
				$updater = $page->newPageUpdater($user);
				$updater->setContent(SlotRecord::MAIN, $content);
				$updater->saveRevision(CommentStoreComment::newUnsavedComment('Import'));
				$this->output(" done\n");
				continue;
			}

			// Import as an old revision so the file mtime becomes the footer's last-modified timestamp.
			$revision = new WikiRevision();
			$revision->setContent(SlotRecord::MAIN, $content);
			$revision->setTitle($title);
			$revision->setUserObj($user);
			$revision->setComment('');
			$revision->setTimestamp(wfTimestamp(TS_UNIX, filemtime($filename)));

			if ($revision->importOldRevision()) {
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
