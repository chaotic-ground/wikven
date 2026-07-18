<?php

namespace MediaWiki\Extension\Wikven;

use ImportImages;
use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RebuildFileCache;
use RunJobs;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Build the static site: populate the wiki, then render each enabled skin in a fresh boot. */
class Build extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Run the full wikven static-site build in a single process.');
	}

	public function execute() {
		// WIKVEN_BUILD_SKIN set renders one skin; orchestrator populates then spawns a pass per skin.
		if ((string)getenv('WIKVEN_BUILD_SKIN') !== '') {
			$this->renderSkin();
			return;
		}

		$ip = $GLOBALS['IP'];
		$own = __DIR__;

		$this->clearOutputDirectory();
		$this->setMainPage();
		$this->importImages("$ip/maintenance/importImages.php");
		$this->step(ImportWikitext::class, "$own/importWikitext.php");
		$this->assertMainPageExists();
		$this->setVersionPage();
		$this->dropDeadFooterPlaces();
		$this->dropDeadCategoryLink();
		// Materialize content translations before RunJobs so rendered translation pages get exported.
		$this->step(BuildTranslations::class, "$own/buildTranslations.php");
		$this->step(RunJobs::class, "$ip/maintenance/runJobs.php");

		$skins = $GLOBALS['wgWikvenSkins'] ?? [];
		if (!$skins) {
			$skins = [$GLOBALS['wgDefaultSkin']];
		}
		foreach ($skins as $skin) {
			$this->renderSkinPass($skin);
		}
	}

	/** Render one skin in a fresh boot by re-invoking this script with WIKVEN_BUILD_SKIN set. */
	private function renderSkinPass(string $skin): void {
		$self = PHP_BINARY;
		$prefix = [$self];
		// Embedded FrankenPHP leaves PHP_BINARY empty; re-run the binary itself as "<self> php-cli".
		if ($self === '' || !is_executable($self)) {
			$self = is_link('/proc/self/exe') ? ( readlink('/proc/self/exe') ?: '' ) : '';
			$prefix = [$self, 'php-cli'];
		}
		if ($self === '' || !is_executable($self)) {
			$this->fatalError('Wikven: cannot locate the PHP executable to render skins');
		}

		// run.php resolves relative to the install root (binary php-cli needs it), so chdir there.
		chdir($GLOBALS['IP']);
		$command = array_merge($prefix, ['maintenance/run.php', __FILE__]);

		$previous = getenv('WIKVEN_BUILD_SKIN');
		putenv("WIKVEN_BUILD_SKIN=$skin");
		passthru(implode(' ', array_map('escapeshellarg', $command)), $exit);
		if ($previous === false) {
			putenv('WIKVEN_BUILD_SKIN');
		} else {
			putenv("WIKVEN_BUILD_SKIN=$previous");
		}

		if ($exit !== 0) {
			$this->fatalError("Wikven: build failed for skin '$skin' (exit $exit)");
		}
	}

	/** Render the already-imported content in the WIKVEN_BUILD_SKIN skin's output directory. */
	private function renderSkin(): void {
		$ip = $GLOBALS['IP'];
		$own = __DIR__;
		$dir = rtrim($GLOBALS['wgWikvenHtmlDirectory'], '/');
		if ($dir !== '' && !wfMkdirParents($dir)) {
			$this->fatalError("Wikven: could not create output directory $dir");
		}

		$this->step(RebuildFileCache::class, "$ip/maintenance/rebuildFileCache.php", ['overwrite' => true]);
		$this->step(BuildStyles::class, "$own/buildStyles.php");
		$this->step(BuildScripts::class, "$own/buildScripts.php");
		$this->step(RewriteScripts::class, "$own/rewriteScripts.php");
		$this->step(StoreImages::class, "$own/storeImages.php");
		$this->step(Rename::class, "$own/rename.php");

		// RebuildFileCache emits a per-page history/ tree the static host won't serve; drop it.
		$history = "$dir/history";
		if (is_dir($history)) {
			$this->removeDirectory($history);
		}
	}

	/** Empty the output dir (kept, may be a mount) so in-place edits don't leave stale output. */
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

	/** Recursively delete a directory and everything under it. */
	private function removeDirectory(string $dir): void {
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
		rmdir($dir);
	}

	/** Run one build step as a child maintenance script, applying $options first. */
	private function step(string $class, string $file, array $options = []): void {
		$child = $this->createChild($class, $file);
		foreach ($options as $name => $value) {
			$child->setOption($name, $value);
		}
		// A child returning false signals failure (e.g. a page didn't import); abort the build.
		if ($child->execute() === false) {
			$this->fatalError("Wikven: $class reported failures; aborting the build.");
		}
	}

	/** Import source-dir images into the File: namespace so pages render with local thumbnails. */
	private function importImages(string $file): void {
		$child = $this->createChild(ImportImages::class, $file);
		$child->setArg(0, rtrim($GLOBALS['wgWikvenSourceDirectory'], '/'));
		$child->setOption('extensions', implode(',', $GLOBALS['wgFileExtensions']));
		$child->setOption('skip-dupes', true);
		$child->execute();
	}

	/** Point the wiki's main page at $wgWikvenMainPage (imported later; see assertMainPageExists). */
	private function setMainPage(): void {
		$title = Title::newFromText('MediaWiki:Mainpage');
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);

		$updater = $page->newPageUpdater($user);
		$content = ContentHandler::makeContent($GLOBALS['wgWikvenMainPage'], $title);
		$updater->setContent(SlotRecord::MAIN, $content);
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Set the main page'));
	}

	/** Hide about/privacy/disclaimer footer links with no imported target (blank label to "-"). */
	private function dropDeadFooterPlaces(): void {
		// Label message (controls whether the link shows) => page-name message (the link's target).
		$places = [
			'Privacy' => 'privacypage',
			'Aboutsite' => 'aboutpage',
			'Disclaimers' => 'disclaimerpage'
		];
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		foreach ($places as $label => $pageMessage) {
			$target = Title::newFromText(wfMessage($pageMessage)->inContentLanguage()->text());
			if ($target && $target->exists()) {
				continue;
			}
			$title = Title::newFromText("MediaWiki:$label");
			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
			$updater = $page->newPageUpdater($user);
			$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent('-', $title));
			$updater->saveRevision(CommentStoreComment::newUnsavedComment('Disable dead footer link'));
		}
	}

	/** Blank "pagecategorieslink" so the category label isn't a dead Special:Categories link. */
	private function dropDeadCategoryLink(): void {
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$title = Title::newFromText('MediaWiki:Pagecategorieslink');
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent('', $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Drop the dead category link'));
	}

	/** Generate a Version page (static Special:Version) listing software, extensions and skins. */
	private function setVersionPage(): void {
		$name = $GLOBALS['wgWikvenVersionPage'] ?? 'Version';
		if ($name === '') {
			return;
		}
		$title = Title::newFromText($name);
		if (!$title || $title->exists()) {
			return;
		}

		$db = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$software = [
			['[https://www.mediawiki.org/ MediaWiki]', MW_VERSION],
			['[https://www.php.net/ PHP]', PHP_VERSION . ' (' . PHP_SAPI . ')'],
			[ucfirst($db->getType()), $db->getServerVersion()]
		];

		$text = $this->contentMsg('wikven-version-intro') . "\n\n";
		$text .= '== ' . $this->contentMsg('version-software') . " ==\n";
		$text .=
			"{| class=\"wikitable\"\n! "
			. $this->contentMsg('version-software-product')
			. ' !! '
			. $this->contentMsg('version-software-version')
			. "\n";
		foreach ($software as [$product, $version]) {
			$text .= "|-\n| $product\n| $version\n";
		}
		$text .= "|}\n\n";

		// Split components into extensions and skins (skins live under skins/), each in its own section.
		$extensions = [];
		$skins = [];
		foreach (ExtensionRegistry::getInstance()->getAllThings() as $thingName => $credits) {
			if (str_contains($credits['path'] ?? '', '/skins/')) {
				$skins[$thingName] = $credits;
			} else {
				$extensions[$thingName] = $credits;
			}
		}
		$text .= $this->componentTable('version-extensions', 'version-ext-colheader-name', $extensions);
		$text .= $this->componentTable('version-skins', 'version-skin-colheader-name', $skins);

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Generate the version page'));
	}

	/** A message in the wiki's content language (the version page is content, not UI chrome). */
	private function contentMsg(string $key): string {
		return wfMessage($key)->inContentLanguage()->text();
	}

	/** A wikitext table of components with versions and project links, under the given messages. */
	private function componentTable(string $headingKey, string $nameColKey, array $things): string {
		if (!$things) {
			return '';
		}
		ksort($things);
		$text = '== ' . $this->contentMsg($headingKey) . " ==\n";
		$text .=
			"{| class=\"wikitable\"\n! "
			. $this->contentMsg($nameColKey)
			. ' !! '
			. $this->contentMsg('version-ext-colheader-version')
			. "\n";
		foreach ($things as $thingName => $credits) {
			$url = $credits['url'] ?? '';
			$label = $url !== '' ? "[$url $thingName]" : $thingName;
			$text .= "|-\n| $label\n| " . ( $credits['version'] ?? '' ) . "\n";
		}
		$text .= "|}\n";
		return $text;
	}

	/** Fail the build if the configured main page wasn't imported (else the site root 404s). */
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
