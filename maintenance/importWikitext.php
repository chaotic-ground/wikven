<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\StubObject\StubGlobalUser;
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
		StubGlobalUser::setUser($user);

		$ok = true;
		foreach ($this->wikitextFiles($sourceDirectory) as $filename) {
			$title = Title::newFromText($this->filenameToTitle($filename, $sourceDirectory));
			if (!$title) {
				$this->output('Invalid title: ' . basename($filename) . "\n");
				continue;
			}

			// The static export derives a page's edit/history link filename from
			// its title. If the title does not round-trip back to this source
			// file name (MediaWiki normalised it), those links would 404; warn so
			// the author can rename the file to an already-normalised title.
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

			// File: description sidecars and MediaWiki: system pages (Common.js, the
			// gadget definition and gadget code, ...) are saved as the current
			// revision via a normal edit. For a File: page the upload already created
			// it with a default description that an old revision would land behind;
			// for MediaWiki: pages the edit hooks must fire so registries like the
			// gadget list are invalidated before the pages render. importOldRevision
			// would skip both.
			if ($title->getNamespace() === NS_FILE || $title->getNamespace() === NS_MEDIAWIKI) {
				$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
				$updater = $page->newPageUpdater($user);
				$updater->setContent(SlotRecord::MAIN, $content);
				$updater->saveRevision(CommentStoreComment::newUnsavedComment('Import'));
				$this->output(" done\n");
				continue;
			}

			// Import as an old revision (like core's importTextFiles.php with
			// --use-timestamp) so the source file's modification time becomes the
			// revision timestamp and the footer shows the real last-modified date
			// instead of the build time.
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
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Find every page file under the source directory, recursing into
	 * subdirectories so subpages can be supplied as nested files (e.g. a
	 * template's "Template:Foo/styles.css" in a "Template:Foo/" folder).
	 *
	 * @return string[] Absolute paths, sorted for a stable import order.
	 */
	private function wikitextFiles(string $sourceDirectory): array {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($iterator as $file) {
			$pathname = $file->getPathname();
			$relative = substr($pathname, strlen($sourceDirectory) + 1);
			if ($file->isFile() && SourceFile::isPageFile($relative)) {
				$files[] = $pathname;
			}
		}
		sort($files);
		return $files;
	}

	/**
	 * Map a page file to its title: the path relative to the source directory,
	 * resolved by SourceFile's naming convention.
	 */
	private function filenameToTitle(string $name, string $sourceDirectory): string {
		$relative = substr($name, strlen($sourceDirectory) + 1);
		return SourceFile::filenameToTitle($relative);
	}
}

$maintClass = ImportWikitext::class;
require_once RUN_MAINTENANCE_IF_MAIN;
