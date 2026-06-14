<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use Maintenance;
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
	 * Find every *.wikitext file under the source directory, recursing into
	 * subdirectories so subpages can be supplied as nested files (e.g. a
	 * template's "Template:Foo/styles.css.wikitext" in a "Template:Foo/" folder).
	 *
	 * @param string $sourceDirectory
	 * @return string[] Absolute paths, sorted for a stable import order.
	 */
	private function wikitextFiles($sourceDirectory) {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($iterator as $file) {
			if ($file->isFile() && str_ends_with($file->getFilename(), '.wikitext')) {
				$files[] = $file->getPathname();
			}
		}
		sort($files);
		return $files;
	}

	/**
	 * Map a wikitext file to its page title: the path relative to the source
	 * directory, minus the .wikitext suffix. A nested file thus becomes a
	 * subpage, so "Template:Foo/styles.css.wikitext" imports as the subpage
	 * "Template:Foo/styles.css".
	 *
	 * @param string $name
	 * @param string $sourceDirectory
	 * @return string
	 */
	private function filenameToTitle($name, $sourceDirectory) {
		$relative = substr($name, strlen($sourceDirectory) + 1);
		return preg_replace('/\.wikitext$/', '', $relative);
	}
}

$maintClass = ImportWikitext::class;
require_once RUN_MAINTENANCE_IF_MAIN;
