<?php

namespace MediaWiki\Extension\Wikven;

use ImportImages;
use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RebuildFileCache;
use RunJobs;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Build the whole static site in a single MediaWiki boot.
 *
 * This replaces the long sequence of separate `php maintenance/run.php ...`
 * invocations the build used to run (a fresh MediaWiki boot per step) with one
 * process that runs every step as a child maintenance script. It keeps the
 * orchestration in PHP instead of shell and avoids paying the bootstrap cost
 * once per step.
 *
 * Steps, in order: set the main page, import the wikitext, run queued jobs,
 * (re)build the file cache, then dump styles and scripts, rewrite them for the
 * static host, store remote images locally, and give the files readable names.
 */
class Build extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Run the full wikven static-site build in a single process.');
	}

	public function execute() {
		$ip = $GLOBALS['IP'];
		$own = __DIR__;

		$this->clearOutputDirectory();
		$this->setMainPage();

		$this->importImages("$ip/maintenance/importImages.php");
		$this->step(ImportWikitext::class, "$own/importWikitext.php");
		$this->assertMainPageExists();
		$this->step(RunJobs::class, "$ip/maintenance/runJobs.php");
		$this->step(RebuildFileCache::class, "$ip/maintenance/rebuildFileCache.php", ['overwrite' => true]);
		$this->step(BuildStyles::class, "$own/buildStyles.php");
		$this->step(BuildScripts::class, "$own/buildScripts.php");
		$this->step(RewriteScripts::class, "$own/rewriteScripts.php");
		$this->step(StoreImages::class, "$own/storeImages.php");
		$this->step(Rename::class, "$own/rename.php");
	}

	/**
	 * Empty the output directory so each build starts from a clean slate. The
	 * dump/rewrite steps edit HTML in place and rename files, so output left by
	 * an earlier run into a persistent (e.g. mounted) dist would otherwise
	 * accumulate: orphaned renamed pages, doubly-rewritten image references, and
	 * never-collected img-* files. The directory itself is kept (it may be a
	 * mount point); only its contents are removed.
	 */
	private function clearOutputDirectory(): void {
		$dir = rtrim($GLOBALS['wgWikvenHtmlDirectory'], '/');
		if ($dir === '' || !is_dir($dir)) {
			return;
		}
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($entries as $entry) {
			if ($entry->isDir()) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}
	}

	/**
	 * Run one build step as a child maintenance script, setting $options on the
	 * child before it runs.
	 */
	private function step(string $class, string $file, array $options = []): void {
		$child = $this->createChild($class, $file);
		foreach ($options as $name => $value) {
			$child->setOption($name, $value);
		}
		$child->execute();
	}

	/**
	 * Upload the image files in the source directory into the File: namespace,
	 * so pages that embed them render with local thumbnails. Runs core's
	 * importImages.php over the source directory; non-image files are ignored.
	 */
	private function importImages(string $file): void {
		$child = $this->createChild(ImportImages::class, $file);
		$child->setArg(0, rtrim($GLOBALS['wgWikvenSourceDirectory'], '/'));
		$child->setOption('extensions', implode(',', $GLOBALS['wgFileExtensions']));
		$child->setOption('skip-dupes', true);
		$child->execute();
	}

	/**
	 * Point the wiki's main page at the configured article ($wgWikvenMainPage,
	 * "index" by default). The article itself is imported afterwards; see
	 * assertMainPageExists().
	 */
	private function setMainPage(): void {
		$title = Title::newFromText('MediaWiki:Mainpage');
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);

		$updater = $page->newPageUpdater($user);
		$content = ContentHandler::makeContent($GLOBALS['wgWikvenMainPage'], $title);
		$updater->setContent(SlotRecord::MAIN, $content);
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Set the main page'));
	}

	/**
	 * Fail the build if the configured main page was not imported. Without this
	 * the main page points at a non-existent article, so the static host serves
	 * no page at the site root while the build otherwise reports success.
	 */
	private function assertMainPageExists(): void {
		$name = $GLOBALS['wgWikvenMainPage'];
		$title = Title::newFromText($name);
		if (!$title || !$title->exists()) {
			$this->fatalError(
				"Wikven: the main page '$name' was not imported. Add a source file for it "
				. "(e.g. '$name.wikitext') or set \$wgWikvenMainPage to an imported page."
			);
		}
	}
}

$maintClass = Build::class;
require_once RUN_MAINTENANCE_IF_MAIN;
