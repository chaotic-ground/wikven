<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use ImportImages;
use Maintenance;
use MediaWiki\Revision\SlotRecord;
use RebuildFileCache;
use RunJobs;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

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

		$this->setMainPage();

		$this->importImages("$ip/maintenance/importImages.php");
		$this->step(ImportWikitext::class, "$own/importWikitext.php");
		$this->step(RunJobs::class, "$ip/maintenance/runJobs.php");
		$this->step(RebuildFileCache::class, "$ip/maintenance/rebuildFileCache.php", ['overwrite' => true]);
		$this->step(BuildStyles::class, "$own/buildStyles.php");
		$this->step(BuildScripts::class, "$own/buildScripts.php");
		$this->step(RewriteScripts::class, "$own/rewriteScripts.php");
		$this->step(StoreImages::class, "$own/storeImages.php");
		$this->step(Rename::class, "$own/rename.php");
	}

	/**
	 * Run one build step as a child maintenance script.
	 *
	 * @param string $class
	 * @param string $file
	 * @param array $options Options to set on the child before it runs.
	 */
	private function step($class, $file, array $options = []) {
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
	 *
	 * @param string $file
	 */
	private function importImages($file) {
		$child = $this->createChild(ImportImages::class, $file);
		$child->setArg(0, rtrim($GLOBALS['wgWikvenSourceDirectory'], '/'));
		$child->setOption('extensions', implode(',', $GLOBALS['wgFileExtensions']));
		$child->setOption('skip-dupes', true);
		$child->execute();
	}

	/**
	 * Point the wiki's main page at the imported "index" article.
	 */
	private function setMainPage() {
		$title = Title::newFromText('MediaWiki:Mainpage');
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);

		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent('index', $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Set the main page'));
	}
}

$maintClass = Build::class;
require_once RUN_MAINTENANCE_IF_MAIN;
