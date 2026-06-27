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

/**
 * Build the whole static site.
 *
 * Run without WIKVEN_BUILD_SKIN this is the orchestrator: it populates the wiki
 * once (set the main page, import images and wikitext, run queued jobs) and then
 * renders each enabled skin, re-invoking itself with WIKVEN_BUILD_SKIN set so
 * every skin gets a fresh MediaWiki boot and never inherits another skin's
 * cached state.
 *
 * A per-skin pass (WIKVEN_BUILD_SKIN set) renders the already-imported content
 * into that skin's output directory: (re)build the file cache, dump styles and
 * scripts, rewrite them for the static host, store images locally, and give the
 * files readable names. The main skin lands in the dist root, others in
 * dist/<skin>/ (see WikvenSettings).
 */
class Build extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Run the full wikven static-site build in a single process.');
	}

	public function execute() {
		// A per-skin pass (WIKVEN_BUILD_SKIN set) renders the imported content in
		// one skin; the orchestrating run populates the wiki, then spawns a pass
		// per enabled skin.
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
		$this->step(RunJobs::class, "$ip/maintenance/runJobs.php");

		$skins = $GLOBALS['wgWikvenSkins'] ?? [];
		if (!$skins) {
			$skins = [$GLOBALS['wgDefaultSkin']];
		}
		foreach ($skins as $skin) {
			$this->renderSkinPass($skin);
		}
	}

	/**
	 * Render one enabled skin in a fresh MediaWiki boot by re-invoking this script
	 * with WIKVEN_BUILD_SKIN set. A fresh process gives the pass its own
	 * $wgDefaultSkin and output directory without inheriting cached skin or
	 * ResourceLoader state. Re-invocation mirrors however this process started:
	 * plain php exposes PHP_BINARY, the embedded FrankenPHP binary leaves it empty
	 * and is re-run as "<self> php-cli".
	 */
	private function renderSkinPass(string $skin): void {
		$self = PHP_BINARY;
		$prefix = [$self];
		if ($self === '' || !is_executable($self)) {
			$self = is_link('/proc/self/exe') ? ( readlink('/proc/self/exe') ?: '' ) : '';
			$prefix = [$self, 'php-cli'];
		}
		if ($self === '' || !is_executable($self)) {
			$this->fatalError('Wikven: cannot locate the PHP executable to render skins');
		}

		// run.php is resolved relative to the install root (required by the binary's
		// php-cli), so run the child from there; the script itself is absolute.
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

	/**
	 * Render the already-imported content in the skin selected by
	 * WIKVEN_BUILD_SKIN, into that skin's output directory.
	 */
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

		// RebuildFileCache emits a per-page history/ tree the static host does not
		// serve; drop it from this pass's output dir.
		$history = "$dir/history";
		if (is_dir($history)) {
			$this->removeDirectory($history);
		}
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
	 * Recursively delete a directory and everything under it.
	 */
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

	/**
	 * Run one build step as a child maintenance script, setting $options on the
	 * child before it runs.
	 */
	private function step(string $class, string $file, array $options = []): void {
		$child = $this->createChild($class, $file);
		foreach ($options as $name => $value) {
			$child->setOption($name, $value);
		}
		// A child that returns false reports failure (e.g. ImportWikitext could
		// not import every page); abort rather than publish a site missing
		// content with a success exit code. Steps that return null are unaffected.
		if ($child->execute() === false) {
			$this->fatalError("Wikven: $class reported failures; aborting the build.");
		}
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
	 * Drop footer links whose target page was not imported: the about, privacy
	 * and disclaimer links point at project pages a static export usually has
	 * none of, so they would 404. A site that wants one just adds the page (e.g.
	 * a "Wikven:Privacy policy" source file) and the link is kept. The footer
	 * omits a link when its label message is disabled, so the missing ones get
	 * their label message blanked to "-" (the SkinAddFooterLinks hook only adds,
	 * it cannot remove these defaults). Runs after import so the pages exist.
	 */
	private function dropDeadFooterPlaces(): void {
		// Label message (the MediaWiki: page that controls whether the link
		// shows) => page-name message (whose page the link points at).
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

	/**
	 * Generate a Version page (mirroring Special:Version, which a static export
	 * has no server to serve) listing the installed software, extensions and
	 * skins. Skipped when $wgWikvenVersionPage is empty or the site already
	 * provides a same-named page.
	 */
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

		// Split the registered components into extensions and skins (skins live
		// under skins/), each in its own section as Special:Version does.
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

	/**
	 * A message in the wiki's content language, for the generated version page
	 * (which is content, not interface chrome, so it follows the site language).
	 */
	private function contentMsg(string $key): string {
		return wfMessage($key)->inContentLanguage()->text();
	}

	/**
	 * A wikitext table of installed components (extensions or skins) with their
	 * versions and project links, under the given section/name-column messages.
	 */
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
