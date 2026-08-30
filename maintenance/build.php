<?php

namespace MediaWiki\Extension\Wikven;

use ImportImages;
use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Shell\Shell;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use RebuildFileCache;
use RunJobs;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Build the static site: populate the wiki, then render each enabled skin in a fresh boot. */
class Build extends Maintenance {
	/** Fallback for the frozen timestamps when the caller names none; chosen only for being fixed. */
	private const FROZEN_TIMESTAMP = '20000101000000';

	/** Names the directory a skin pass's own copy of the database goes in, beside the original. */
	private const PASS_DATABASE_PREFIX = 'wikven-pass-';

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

		// Before the output directory is emptied, because a site this build cannot render is better
		// told so with its last bake still in place.
		$this->checkLuaAgainstThisBuild();
		$this->clearOutputDirectory();
		$this->setMainPage();
		$this->importImages("$ip/maintenance/importImages.php");
		$this->step(ImportWikitext::class, "$own/importWikitext.php");
		$this->assertMainPageExists();
		$this->setLicensesPage();
		$this->setSettingsPage();
		$this->dropDeadPlaceLinks();
		$this->dropDeadCategoryLink();
		// Materialize content translations before RunJobs so rendered translation pages get exported.
		$this->step(BuildTranslations::class, "$own/buildTranslations.php");
		$this->runJobs("$ip/maintenance/runJobs.php");
		// Every page the export will hold now exists, and nothing writes another revision after
		// this, so this is where each page can be told when it was last edited, and by whom.
		$this->stampSourceHistory();
		$this->hideBuildAuthors();
		$this->forgetCachedRevisionRows();
		// The content is final here, so this is the last write the database needs and the passes
		// below can be readers of it.
		$this->freezePageTouched();
		// The search index is built by now, by the job runJobs() holds back to the end, and every
		// skin pass below copies it. Settle it here so the one copy they all take is already stable.
		$this->stabilizeSearchIndex();

		$skins = $GLOBALS['wgWikvenSkins'] ?? [];
		if (!$skins) {
			$skins = [$GLOBALS['wgDefaultSkin']];
		}
		$this->renderSkinPasses(array_values($skins));
	}

	/**
	 * Say what this site's Lua and this build's Lua make of each other; see Scribunto for the ways
	 * they used to pass quietly and produce a site with braces in it.
	 *
	 * Only one of the two ends the build, and it is the one the site asked for: Scribunto listed where
	 * nothing can run it. The other is a remark about Module: files the site never asked to run.
	 */
	private function checkLuaAgainstThisBuild(): void {
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		$listed = ExtensionRegistry::getInstance()->isLoaded(Scribunto::EXTENSION);

		$problem = Scribunto::problem($listed, self::luaEngineAvailable());
		if ($problem !== null) {
			$this->fatalError($problem);
		}

		$modules = $source !== '' && is_dir($source) ? Scribunto::modulePages(self::sourcePaths($source)) : [];
		$warning = Scribunto::warning($listed, $modules);
		if ($warning !== null) {
			$this->output("$warning\n");
		}
	}

	/**
	 * Every file under the source directory, relative to it.
	 *
	 * Not ImportWikitext's list, which is filtered by SourceFile::isPageFile() -- and that asks
	 * MediaWiki for the title's content model, so with Scribunto absent a module file is not a page
	 * file and would be missing from exactly the case worth catching.
	 *
	 * @return string[]
	 */
	private static function sourcePaths(string $source): array {
		$paths = [];
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($entries as $entry) {
			if ($entry->isFile()) {
				$paths[] = substr($entry->getPathname(), strlen($source) + 1);
			}
		}
		sort($paths);
		return $paths;
	}

	/**
	 * Whether anything here can run Lua.
	 *
	 * Four ways, in the order Scribunto would find them. luasandbox is the engine the image carries.
	 * Failing that, Scribunto's standalone engine runs an external lua: the one this site configured,
	 * one on PATH, or -- and this is the case that is easy to forget -- one Scribunto ships itself,
	 * which is what it falls back to when luaPath is null.
	 */
	private static function luaEngineAvailable(): bool {
		if (extension_loaded('luasandbox')) {
			return true;
		}
		$configured = (string)( $GLOBALS['wgScribuntoEngineConf']['luastandalone']['luaPath'] ?? '' );
		if ($configured !== '' && is_executable($configured)) {
			return true;
		}
		foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
			foreach (['lua5.1', 'lua'] as $name) {
				if ($dir !== '' && is_executable("$dir/$name")) {
					return true;
				}
			}
		}
		return self::bundledLuaRuns();
	}

	/**
	 * Whether the lua binary Scribunto carries can run here.
	 *
	 * Existing is not enough, which is why this runs it. None of the five binaries Scribunto bundles is
	 * arm64 -- they are Intel, Linux and Windows in 32- and 64-bit and macOS in 64 -- and
	 * LuaStandaloneInterpreter picks among them by PHP_OS and PHP_INT_SIZE, so on arm it selects the
	 * x86-64 one, passes its own is_executable() check on it, and only finds out when the process will
	 * not start. The path below mirrors that choice for 64-bit Linux, which is the only platform either
	 * wikven product runs on; executing it is what makes the mirror safe, since a wrong guess answers
	 * no rather than promising Lua that never arrives.
	 */
	private static function bundledLuaRuns(): bool {
		$lua =
			$GLOBALS['IP']
			. '/extensions/'
			. Scribunto::EXTENSION
			. '/includes/Engines/LuaStandalone/binaries/lua5_1_5_linux_64_generic/lua';
		if (PHP_OS !== 'Linux' || PHP_INT_SIZE !== 8 || !is_executable($lua)) {
			return false;
		}
		return Shell::command($lua, '-v')->includeStderr()->execute()->getExitCode() === 0;
	}

	/**
	 * Run the queue one type at a time, in name order: the runner otherwise shuffles the types.
	 *
	 * The search index is held back to the end. SifterSearch enqueues a rebuild for every revision
	 * inserted, and although a pending one absorbs the rest, each pass of the queue picks up
	 * whichever has arrived since. A bake was running Pagefind 21 times and leaving 20 dead
	 * generations of hashed index files in the output, since Pagefind writes into a directory rather
	 * than replacing it. Held back, it runs once, over the finished content.
	 */
	private function runJobs(string $file): void {
		$group = $this->getServiceContainer()->getJobQueueGroup();
		while (true) {
			$types = array_diff($group->getQueuesWithJobs(), [Search::INDEX_JOB]);
			if (!$types) {
				break;
			}
			sort($types);
			foreach ($types as $type) {
				$this->runJobsOfType($file, $type);
			}
		}
		if (in_array(Search::INDEX_JOB, $group->getQueuesWithJobs(), true)) {
			$this->runJobsOfType($file, Search::INDEX_JOB);
		}
	}

	private function runJobsOfType(string $file, string $type): void {
		$child = $this->createChild(RunJobs::class, $file);
		$child->setOption('type', $type);
		$child->execute();
	}

	/**
	 * Date every page at the commit that last changed the file it was written from, and credit
	 * that commit's author.
	 *
	 * The footer's "last edited" line reports a page's newest revision, and every revision here is
	 * written by the build: the import stamps its own instant, and a translatable page is written
	 * again by Translate's marker and once more per language by its render jobs. Chasing each of
	 * those writes means guessing which one lands last; stamping the rows once the content is
	 * final does not, and it is the same answer for all of them. What the file's mtime used to
	 * give was the moment CI cloned the repository -- one instant for every page, a different one
	 * for every bake of the same commit, and the one date SOURCE_DATE_EPOCH could not freeze
	 * (#406). git has the real one, and it is what the "View history" link opens.
	 *
	 * A page the history says nothing about -- one generated by the build, like Licenses and
	 * Settings, or a source file never committed -- keeps the frozen build clock, which dates it
	 * at the commit being built. That is true of the export as a whole, if not of the page.
	 */
	private function stampSourceHistory(): void {
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		$history = SourceHistory::forSource($source, (string)( $GLOBALS['wgWikvenSourceHistoryFile'] ?? '' ));

		$services = $this->getServiceContainer();
		$build = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$authors = new SourceAuthors($services->getUserFactory(), $build);
		$actorStore = $services->getActorStore();
		$titleFactory = $services->getTitleFactory();

		$dbw = $this->getPrimaryDB();
		$pages = $dbw->newSelectQueryBuilder()
			->select(['page_namespace', 'page_title', 'page_latest'])
			->from('page')
			->caller(__METHOD__)
			->fetchResultSet();

		foreach ($pages as $page) {
			$title = $titleFactory->makeTitle((int)$page->page_namespace, $page->page_title);
			$change = $this->sourceChangeFor($title->getPrefixedText(), $history);
			if ($change === null) {
				continue;
			}

			$set = ['rev_timestamp' => $dbw->timestamp($change['timestamp'])];
			// No usable name among the author's gives the build's own account back, which is the
			// one it already has; hideBuildAuthors() below then leaves that page unattributed.
			$author = $authors->accountFor($change['authors']);
			if (!$author->equals($build)) {
				$set['rev_actor'] = $actorStore->acquireActorId($author, $dbw);
			}

			$dbw->newUpdateQueryBuilder()
				->update('revision')
				->set($set)
				->where(['rev_id' => (int)$page->page_latest])
				->caller(__METHOD__)
				->execute();
		}
	}

	/**
	 * What the history says about the file a page was written from, or null for a page the build
	 * wrote itself.
	 *
	 * SourceFile's naming convention answers which file that is for an imported page and for a
	 * translated one alike: "Skins" came from "Skins.wikitext" and "Skins/ko" from
	 * "Skins/ko.wikitext". The one page with no file of its own is the source-language page
	 * Translate adds ("Skins/en"), which follows the page it repeats.
	 *
	 * @return ?array{timestamp:int,authors:string[]}
	 */
	private function sourceChangeFor(string $prefixedText, SourceHistory $history): ?array {
		$files = [SourceFile::titleToFilename($prefixedText)];
		$slash = strrpos($prefixedText, '/');
		if ($slash !== false) {
			$files[] = SourceFile::titleToFilename(substr($prefixedText, 0, $slash));
		}

		foreach ($files as $file) {
			$timestamp = $history->timestamp($file);
			if ($timestamp !== null) {
				return ['timestamp' => $timestamp, 'authors' => $history->authors($file)];
			}
		}
		return null;
	}

	/**
	 * Stop the "last edited" lines naming the accounts the build writes under.
	 *
	 * The wiki is filled by maintenance scripts, so unless the source history just named a page's
	 * author, the account on its newest revision is the build's own: "Maintenance script" for
	 * everything the import and the page setters above wrote, and Translate's FuzzyBot for the
	 * translated pages its jobs render. Minerva's footer bar was offering that name to every
	 * reader as the person who last edited the page (#406). It names nobody, and the export has no
	 * user page, contributions or account for it to lead to.
	 *
	 * MediaWiki's own way of saying that a revision's author is not public is the DELETED_USER bit,
	 * and skins answer it without a name: Minerva emits no editor at all for a revision whose
	 * RevisionRecord::getUser() is null, leaving its bar to read "Last edited <when> by an
	 * anonymous user" -- which is what the export knows -- and with no name there is no link to a
	 * user page the export does not have. Vector's #footer-info-lastmod never carried an author.
	 * The pages that would report the bit itself, history and diffs, are not rendered, and page
	 * content hangs off a separate bit (DELETED_TEXT) this leaves alone.
	 */
	private function hideBuildAuthors(): void {
		$names = [User::MAINTENANCE_SCRIPT_USER];
		if (ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			$names[] = (string)( $GLOBALS['wgTranslateFuzzyBotName'] ?? 'FuzzyBot' );
		}

		$dbw = $this->getPrimaryDB();
		$actors = $dbw->newSelectQueryBuilder()
			->select('actor_id')
			->from('actor')
			->where(['actor_name' => $names])
			->caller(__METHOD__)
			->fetchFieldValues();
		if (!$actors) {
			return;
		}

		// Assigned rather than or-ed in: a build never deletes a revision, so the field is 0 here.
		$dbw->newUpdateQueryBuilder()
			->update('revision')
			->set(['rev_deleted' => RevisionRecord::DELETED_USER])
			->where(['rev_actor' => $actors])
			->caller(__METHOD__)
			->execute();
	}

	/**
	 * Drop the cached copies of the revision rows the two steps above rewrote in place.
	 *
	 * RevisionStore keeps the row of a page's newest revision in the object cache for a week, keyed
	 * on the page and revision ids alone (RevisionStore::ROW_CACHE_KEY), and an edit in place
	 * changes neither id. The build reads most pages long before those two steps run -- the job
	 * queue parses every one of them -- so without this the skin passes, fresh processes over the
	 * same database-backed cache, would render each page with the date it had before it was
	 * stamped. Nothing else in the cache is touched: the Commons thumbnail lookups it also holds
	 * are why the build has one at all (see WikvenSettings.php).
	 */
	private function forgetCachedRevisionRows(): void {
		$cache = $this->getServiceContainer()->getMainWANObjectCache();
		$dbw = $this->getPrimaryDB();
		$pages = $dbw->newSelectQueryBuilder()
			->select(['page_id', 'page_latest'])
			->from('page')
			->caller(__METHOD__)
			->fetchResultSet();

		foreach ($pages as $page) {
			$cache->delete(
				$cache->makeGlobalKey(
					RevisionStore::ROW_CACHE_KEY,
					$dbw->getDomainID(),
					(int)$page->page_id,
					(int)$page->page_latest
				)
			);
		}
	}

	/**
	 * Freeze page_touched once content is final; wiki-page modules fold it into their version hash.
	 *
	 * Once, in the orchestrator, rather than once per skin pass: what it writes is content state,
	 * the same in every pass and derived from nothing a pass did, so a three-skin bake was running
	 * the same full-table update three times for two no-ops. It is also one of the two writes that
	 * kept a pass from being a reader of the database -- rebuildFileCache.php puts the wiki in
	 * read-only mode for its own duration, so a pass has no business writing around it -- and a
	 * reader is what a pass has to be to run beside another one (#407).
	 *
	 * Freezing here also means the pages are rendered with the frozen value rather than with
	 * whatever the import and the job queue left, since the passes now boot after it. That is the
	 * point of the freeze rather than a cost of moving it: SOURCE_DATE_EPOCH differs per commit,
	 * so a page_touched written under the frozen clock differs per commit too, and pinning it is
	 * what keeps the module version hashes embedded in the HTML stable between bakes.
	 */
	private function freezePageTouched(): void {
		$dbw = $this->getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update('page')
			->set(['page_touched' => $dbw->timestamp(self::FROZEN_TIMESTAMP)])
			->where(ISQLPlatform::ALL_ROWS)
			->caller(__METHOD__)
			->execute();

		// The modules read page_touched through LinkCache, not the row just written: this process
		// has warmed it filling the wiki, and its MediaWiki: entries are kept in the object cache
		// as well, which the passes below boot onto rather than build for themselves.
		$linkCache = $this->getServiceContainer()->getLinkCache();
		$pages = $dbw->newSelectQueryBuilder()
			->select(['page_namespace', 'page_title'])
			->from('page')
			->caller(__METHOD__)
			->fetchResultSet();
		foreach ($pages as $page) {
			$linkCache->invalidateTitle(new TitleValue((int)$page->page_namespace, $page->page_title));
		}
		$linkCache->clear();
	}

	/**
	 * Render every enabled skin, several passes at a time.
	 *
	 * The passes are independent by construction: everything they read is in the database before
	 * the first one starts, each writes into an output directory of its own, and each is a
	 * separate process with its own boot. So the skin phase is the half of the bake that
	 * parallelizes, and the half that grows -- the populate phase above is O(pages) and stays
	 * serial, while this is O(pages x skins) and gains a pass every time a skin is added (#407).
	 *
	 * What the passes still share is the database, and a pass is not quite a reader of it: the
	 * object cache is a table ($wgMainCacheType = CACHE_DB), and SQLite takes one writer at a
	 * time. Each therefore gets a copy of the database file to work on, which also keeps a pass
	 * from reading what another one cached -- so a pass renders from the same state whether it
	 * runs first, last or beside the others, which is what keeps the output reproducible (#411).
	 *
	 * @param string[] $skins
	 */
	private function renderSkinPasses(array $skins): void {
		$command = $this->skinPassCommand();
		$limit = $this->passLimit(count($skins));
		$databases = $this->copyDatabasePerPass($skins);

		$this->output('Rendering ' . count($skins) . ' skin(s), up to ' . $limit . " at a time\n");

		$queued = $skins;
		$running = [];
		$failed = [];
		while ($queued || $running) {
			while ($queued && count($running) < $limit) {
				$skin = array_shift($queued);
				$running[$skin] = $this->startSkinPass($command, $skin, $databases[$skin] ?? '');
			}

			$this->waitForPassOutput($running);
			foreach (array_keys($running) as $skin) {
				$this->readPass($running[$skin]);
				$exit = $this->reapPass($running[$skin]);
				if ($exit === null) {
					continue;
				}
				// Whole and in one piece, now that the pass is done: three renders writing to this
				// process's own stdout as they go would interleave into something no one could
				// attribute a failure from, and the log is how a bake is debugged.
				$this->reportPass($skin, $running[$skin], $exit);
				if ($exit !== 0) {
					$failed[] = "$skin (exit $exit)";
				}
				unset($running[$skin]);
			}
		}

		$this->removeDatabaseCopies($databases);
		if ($failed) {
			$this->fatalError('Wikven: build failed for skin ' . implode(', ', $failed));
		}
	}

	/**
	 * The argv that re-invokes this script for one skin, resolved once for every pass.
	 *
	 * @return string[]
	 */
	private function skinPassCommand(): array {
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
		return array_merge($prefix, ['maintenance/run.php', __FILE__]);
	}

	/**
	 * Start one skin's pass in a fresh boot, with its output on pipes this process reads.
	 *
	 * @param string[] $command
	 * @return array{process:resource,pipes:array<int,?resource>,output:array<int,string>}
	 */
	private function startSkinPass(array $command, string $skin, string $databaseDirectory): array {
		// The skin, the database and the working directory are passed to the child alone, so this
		// process's own environment and cwd stay untouched while a skin renders. run.php resolves
		// relative to the install root, which the binary's php-cli requires, hence $GLOBALS['IP']
		// as the child's cwd. An array argv also means the arguments never pass through a shell to
		// be quoted for. Both variables are set even when empty, so that a pass never inherits a
		// value another run left in this process's environment.
		$environment = ['WIKVEN_BUILD_SKIN' => $skin, 'WIKVEN_BUILD_DB_DIR' => $databaseDirectory] + getenv();
		$descriptors = [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$pipes = [];
		$process = proc_open($command, $descriptors, $pipes, $GLOBALS['IP'], $environment);
		if ($process === false) {
			$this->fatalError("Wikven: could not start the build for skin '$skin'");
		}
		// Non-blocking, so reading a quiet pass never holds up a talkative one.
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		return ['process' => $process, 'pipes' => $pipes, 'output' => [1 => '', 2 => '']];
	}

	/**
	 * Block until one of the running passes has something to say, or briefly if none of them has.
	 *
	 * @param array[] $running
	 */
	private function waitForPassOutput(array $running): void {
		$read = [];
		foreach ($running as $pass) {
			foreach ($pass['pipes'] as $pipe) {
				// A pipe this process has closed is left as null, and there is nothing to wait for.
				if ($pipe !== null) {
					$read[] = $pipe;
				}
			}
		}
		if (!$read) {
			// Every pass has closed its pipes and none has been reaped yet; that gap is short.
			usleep(50_000);
			return;
		}
		$write = [];
		$except = [];
		// The timeout is what bounds the wait, since a pass that exits without a last word leaves
		// nothing to wake this up. A closed pipe reads as ready, so an ending pass is seen at once.
		stream_select($read, $write, $except, 0, 200_000);
	}

	/**
	 * Take whatever a pass has written so far, so its pipe buffer never fills and blocks it.
	 *
	 * @param array &$pass
	 */
	private function readPass(array &$pass): void {
		foreach ($pass['pipes'] as $descriptor => $pipe) {
			if ($pipe === null) {
				continue;
			}
			$chunk = fread($pipe, 65_536);
			while ($chunk !== false && $chunk !== '') {
				$pass['output'][$descriptor] .= $chunk;
				$chunk = fread($pipe, 65_536);
			}
			// Dropped once the pass has closed its end, so it stops waking the select above.
			if (feof($pipe)) {
				fclose($pipe);
				$pass['pipes'][$descriptor] = null;
			}
		}
	}

	/**
	 * Close a finished pass and answer how it exited, or null while it is still running.
	 *
	 * @param array &$pass
	 */
	private function reapPass(array &$pass): ?int {
		$status = proc_get_status($pass['process']);
		if ($status['running']) {
			return null;
		}
		// Anything the pass wrote before exiting is still in the pipes; a pipe outlives its writer.
		$this->readPass($pass);
		foreach ($pass['pipes'] as $descriptor => $pipe) {
			if ($pipe !== null) {
				fclose($pipe);
				$pass['pipes'][$descriptor] = null;
			}
		}
		// The status above already reaped the child, so proc_close's own answer is not the exit
		// code any more; it is called to release the handle.
		proc_close($pass['process']);
		return (int)$status['exitcode'];
	}

	/**
	 * Print one pass's output under a heading naming it, once the pass is over.
	 *
	 * @param string $skin
	 * @param array $pass
	 * @param int $exit
	 */
	private function reportPass(string $skin, array $pass, int $exit): void {
		$this->output("--- $skin pass" . ( $exit === 0 ? '' : " failed (exit $exit)" ) . " ---\n");
		$this->output($pass['output'][1]);
		if ($pass['output'][2] !== '') {
			$this->error(rtrim($pass['output'][2], "\n"));
		}
	}

	/** How many passes to run at once; BuildConcurrency reads what the answer is made of. */
	private function passLimit(int $passes): int {
		return BuildConcurrency::limit(
			$passes,
			(string)getenv('WIKVEN_BUILD_JOBS'),
			$this->readFile('/proc/cpuinfo'),
			$this->readFile('/sys/fs/cgroup/cpu.max')
		);
	}

	/** A file that describes the machine, or "" where this one does not have it. */
	private function readFile(string $path): string {
		return is_readable($path) ? (string)file_get_contents($path) : '';
	}

	/**
	 * Give each pass a copy of the database to render from.
	 *
	 * A pass reads the content, but it still writes: the object cache is a table of this database
	 * ($wgMainCacheType = CACHE_DB, which is there so the Commons thumbnail lookups a bake makes
	 * are made once). SQLite takes one writer at a time, so passes sharing the file would be
	 * serialized by it at best and fail with SQLITE_BUSY at worst. The file is small and the
	 * content is final by now, so a copy each is the cheap way out -- and the copies are taken
	 * after the populate phase, so each pass inherits its lookups rather than repeating them.
	 *
	 * A server database has no such limit and is left alone, as is a single-pass bake.
	 *
	 * @param string[] $skins
	 * @return array<string,string> Skin to the data directory holding its copy; empty when the
	 *   passes share the database.
	 */
	private function copyDatabasePerPass(array $skins): array {
		$directory = rtrim((string)( $GLOBALS['wgSQLiteDataDir'] ?? '' ), '/');
		if (count($skins) < 2 || ( $GLOBALS['wgDBtype'] ?? '' ) !== 'sqlite' || !is_dir($directory)) {
			return [];
		}

		// Everything this process wrote has to be in the file before it is copied.
		$this->getServiceContainer()->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
		$databases = glob("$directory/*.sqlite");
		if (!$databases) {
			// Nothing to copy is nothing to isolate; leave the passes on the database as it is.
			return [];
		}
		// The write-ahead log alongside a database holds commits the database file does not have
		// yet; the shared-memory index beside it is rebuilt from that log and is not copied.
		$logs = glob("$directory/*.sqlite-wal");
		$files = array_merge($databases, $logs === false ? [] : $logs);

		$copies = [];
		foreach ($skins as $skin) {
			$destination = "$directory/" . self::PASS_DATABASE_PREFIX . $skin;
			if (!wfMkdirParents($destination)) {
				$this->fatalError("Wikven: could not create the database directory $destination");
			}
			foreach ($files as $file) {
				if (!copy($file, $destination . '/' . basename($file))) {
					$this->fatalError("Wikven: could not copy $file for the '$skin' pass");
				}
			}
			$copies[$skin] = $destination;
		}
		return $copies;
	}

	/**
	 * Drop the per-pass databases, which say nothing about the site once its pages are rendered.
	 *
	 * @param array<string,string> $copies
	 */
	private function removeDatabaseCopies(array $copies): void {
		foreach ($copies as $directory) {
			if (is_dir($directory)) {
				$this->removeDirectory($directory);
			}
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

		// Before anything renders or is dumped: the bundle path reaches the client inside the script
		// bundle buildScripts writes below, so this pass has to be pointed at its own copy first.
		$searchBundle = $this->pointSearchAtThisCopy();

		$this->step(RebuildFileCache::class, "$ip/maintenance/rebuildFileCache.php", ['overwrite' => true]);
		// RebuildFileCache renders in the content language; re-render translations in their own.
		$this->step(RetranslateChrome::class, "$own/retranslateChrome.php");
		// Every page is rendered by now: drop what each one recorded about the request that made it.
		$this->step(StripBuildStamps::class, "$own/stripBuildStamps.php");
		$this->step(BuildStyles::class, "$own/buildStyles.php");
		// Opt-in: bake ULS webfonts into a static stylesheet rewriteScripts links below.
		$this->step(BakeWebfonts::class, "$own/bakeWebfonts.php");
		$this->step(BuildScripts::class, "$own/buildScripts.php");
		$this->step(RewriteScripts::class, "$own/rewriteScripts.php");
		// Minerva takes no navigation from the sidebar, so its menu is filled in the rendered pages.
		$this->step(FillMinervaMenu::class, "$own/fillMinervaMenu.php");
		$this->step(StoreImages::class, "$own/storeImages.php");
		$this->step(Rename::class, "$own/rename.php");
		// Rename has expanded translation pages into "<Page>/<lang>.html"; resolve MyLanguage links now.
		$this->step(ResolveTranslationLinks::class, "$own/resolveTranslationLinks.php");

		// SkippedHistoryAction leaves RebuildFileCache nothing to write here, so this finds nothing
		// on a normal bake; it stays as the guard for a pass over an output directory that already
		// holds a history/ tree, which the static host would not serve.
		$history = "$dir/history";
		if (is_dir($history)) {
			// Say so rather than tidy up in silence: on a bake that starts from an empty output
			// directory, a tree here means the skipped action is no longer being asked for, and
			// every page has paid for a render nobody reads.
			$this->output("Wikven: removing a history/ tree the export does not want ($history)\n");
			$this->removeDirectory($history);
		}

		// Last, so nothing above walks the bundle looking for pages to rewrite.
		if ($searchBundle !== null) {
			$this->copySearchBundle($searchBundle);
		}
	}

	/**
	 * Put the search bundle's entry file in an order it will come out in again next bake.
	 *
	 * Pagefind names each language's index in one JSON map and writes that map in whatever order it
	 * happened to iterate, so two bakes of one source produce two spellings of the same fact: the
	 * index hashes and page counts match, the order of the languages does not. One language has no
	 * order to get wrong, which is why this surfaced only once translations were indexed in their
	 * own language rather than all as the wiki's.
	 *
	 * Reproducibility is the build's promise (#411), not the indexer's, so the build keeps it --
	 * exactly as StripBuildStamps drops the per-request ids MediaWiki leaves in a page. Search
	 * explains what is safe to rewrite and why nothing reading the bundle can tell.
	 */
	private function stabilizeSearchIndex(): void {
		$bundle = rtrim((string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' ), '/');
		if ($bundle === '') {
			return;
		}
		$path = "$bundle/" . Search::INDEX_ENTRY_FILE;
		if (!is_file($path)) {
			// Search is off, or on with nothing indexed. Either way there is no bundle to settle.
			return;
		}
		$stable = Search::stableIndexEntry((string)file_get_contents($path));
		if ($stable !== null) {
			file_put_contents($path, $stable);
		}
	}

	/**
	 * Point this pass's search at a bundle of its own, and say where that bundle has to be written.
	 *
	 * A skin copy already holds its own pages, styles and scripts; the search index was the one
	 * thing it went on reading out of the export root, and reading it there is what sent a reader
	 * who searched back to the root copy (#399, and Search::copyBundlePath for why the bundle's
	 * location decides that). So the copy gets its own, and every URL SifterSearch derives from it
	 * -- a result, the results page, the form's target, the "containing" row -- lands in the copy
	 * without anything on the client having to correct it.
	 *
	 * The main skin renders into the export root, where the index already is, so it needs none of
	 * this; nor does a site whose bundle sits somewhere this cannot put a copy beside.
	 *
	 * @return ?string The directory the bundle must be copied to, or null where nothing is to change.
	 */
	private function pointSearchAtThisCopy(): ?string {
		$skin = (string)( $GLOBALS['wgDefaultSkin'] ?? '' );
		$main = (string)( $GLOBALS['wgWikvenMainSkin'] ?? '' );
		if ($skin === '' || $skin === $main || !Search::isActive()) {
			return null;
		}
		$path = Search::copyBundlePath((string)( $GLOBALS['wgSifterSearchBundlePath'] ?? '' ), $skin);
		if ($path === null) {
			return null;
		}
		$GLOBALS['wgSifterSearchBundlePath'] = $path;
		// The copy is served at the copy's own output directory, and copyBundlePath left the
		// bundle's last segment alone, so that segment is the directory to write under it.
		return rtrim($GLOBALS['wgWikvenHtmlDirectory'], '/') . '/' . basename(rtrim($path, '/'));
	}

	/**
	 * Duplicate the built Pagefind bundle into this pass's copy of the site.
	 *
	 * The index job is held back to the end of the populate phase (see runJobs), which is before
	 * any skin renders, so the bundle is complete and nothing writes to it again while this reads.
	 */
	private function copySearchBundle(string $destination): void {
		$source = rtrim((string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' ), '/');
		if ($source === '' || !is_dir($source)) {
			// Search is on but nothing was indexed -- an empty wiki, or a Pagefind run that failed
			// and reported itself. There is no bundle to serve from the root copy either.
			return;
		}
		if (!wfMkdirParents($destination, null, __METHOD__)) {
			$this->fatalError("Wikven: could not create search bundle directory $destination");
		}
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($entries as $entry) {
			// Cut the source off each path rather than ask the iterator for the relative one:
			// getSubPathname() is the inner directory iterator's, reached through __call.
			$target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);
			if ($entry->isDir()) {
				if (!wfMkdirParents($target, null, __METHOD__)) {
					$this->fatalError("Wikven: could not create $target");
				}
			} elseif (!copy($entry->getPathname(), $target)) {
				$this->fatalError("Wikven: could not copy {$entry->getPathname()} to $target");
			}
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
		$directory = rtrim($GLOBALS['wgWikvenSourceDirectory'], '/');
		$extensions = $GLOBALS['wgFileExtensions'];
		$sources = ImageImport::sources($directory, $extensions);

		$child = $this->createChild(ImportImages::class, $file);
		$child->setArg(0, $directory);
		$child->setOption('extensions', implode(',', $extensions));
		$child->setOption('skip-dupes', true);
		// An image the wiki rejected leaves every page embedding it with a red File: link, so this
		// step aborts the build the way step() aborts it for a page that did not import. A source
		// holding no image at all is answered with the same false and is not a failure.
		if (ImageImport::failed($child->execute(), $sources)) {
			// The count is of the images offered, not of the ones that failed: the importer's own
			// summary above holds that number, and it named each file as it choked on it.
			$count = count($sources);
			$this->fatalError("Wikven: not all $count image(s) in $directory imported; aborting the build.");
		}
	}

	/** Point the wiki's main page at $wgWikvenMainPage (imported later; see assertMainPageExists). */
	private function setMainPage(): void {
		// Whatever the installer made the main page, before the message below repoints it.
		$installed = Title::newMainPage();

		$title = Title::newFromText('MediaWiki:Mainpage');
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);

		$updater = $page->newPageUpdater($user);
		$content = ContentHandler::makeContent($GLOBALS['wgWikvenMainPage'], $title);
		$updater->setContent(SlotRecord::MAIN, $content);
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Set the main page'));

		$this->dropInstalledMainPage($installed, $user);
	}

	/**
	 * Delete the page the installer wrote, unless the source provides one by that name.
	 *
	 * It holds MediaWiki's "MediaWiki has been installed" boilerplate, which every bake was
	 * exporting as a page of the site. It is also the one page created before wikven's settings
	 * load, so its revision keeps the real clock and made the export differ between bakes.
	 */
	private function dropInstalledMainPage(Title $installed, User $user): void {
		if (!$installed->canExist() || !$installed->exists()) {
			return;
		}
		if (SourceFile::exists($installed->getPrefixedText())) {
			return;
		}
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($installed);
		$status = $this->getServiceContainer()
			->getDeletePageFactory()
			->newDeletePage($page, $user)
			->deleteUnsafe('Wikven: installer page, not part of the source');
		if (!$status->isOK()) {
			$this->output("Wikven: could not delete the installer's main page {$installed->getPrefixedText()}\n");
		}
	}

	/** Hide project links with no imported target (blank label to "-"), in the footer and menus. */
	private function dropDeadPlaceLinks(): void {
		// Label message (controls whether the link shows) => page-name message (the link's target).
		// Community portal is here for Minerva, which builds its menu from its own definitions and
		// reads this message directly; the other skins take it from MediaWiki:Sidebar.
		$places = [
			'Privacy' => 'privacypage',
			'Aboutsite' => 'aboutpage',
			'Disclaimers' => 'disclaimerpage',
			'Portal' => 'portal-url'
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

	/**
	 * Generate a Settings page: the reader's own display choices, which a static export keeps in
	 * the browser rather than in a user account. It stands in for Special:MobileOptions, which is
	 * MobileFrontend's and which no bake writes, and it is a page rather than a panel so every
	 * skin can link to it.
	 *
	 * The controls themselves are drawn by ext.Wikven.appearance into the placeholders below;
	 * wikitext cannot carry a radio, and the choices mean nothing without the script anyway.
	 */
	private function setSettingsPage(): void {
		$name = $GLOBALS['wgWikvenSettingsPage'] ?? '';
		if ($name === '') {
			return;
		}
		$title = Title::newFromText($name);
		if (!$title || $title->exists()) {
			return;
		}

		// Special:MobileOptions is an empty form its own script fills, so this page is the same
		// empty form: Adder queues the modules, and MediaWiki draws the controls it would have
		// drawn there. Wikitext carries no <form>, and that stylesheet's layout rules all name
		// one, so fillMinervaMenu.php puts a real form inside this. The skin list is wikven's own,
		// and follows in a section of its own.
		$text = "<div id=\"wikven-settings-form\"></div>\n";
		if (count($GLOBALS['wgWikvenSkins'] ?? []) > 1) {
			$text .= $this->settingsSection(
				'wikven-skins',
				'wikven-skins-description',
				'wikven-appearance-skins'
			);
		}

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Generate the settings page'));
	}

	/** One titled and described section of the settings page, around an empty placeholder. */
	private function settingsSection(string $title, string $description, string $id): string {
		return (
			'<div class="wikven-setting">'
			. '<div class="wikven-setting-title">'
			. $this->contentMsg($title)
			. '</div>'
			. '<div class="wikven-setting-description">'
			. $this->contentMsg($description)
			. '</div>'
			. "<div class=\"wikven-setting-control\" id=\"$id\"></div>"
			. "</div>\n"
		);
	}

	/**
	 * Give the site a page saying what it redistributes, and link it from every page.
	 *
	 * A built site is not only the author's words. buildScripts bakes MediaWiki's own module
	 * closure into modules-static.js and buildStyles writes each skin's and extension's CSS beside
	 * it, so every page the export serves carries other people's code, under other people's
	 * licenses. Something has to say so, and it has to be reachable: a page only a reader who goes
	 * looking would find is not an acknowledgement, which is why the footer links this one.
	 *
	 * One page, and not the two it is tempting to write. A site's own introduction is the site's to
	 * write and the build has nothing to add to it; what the site redistributes is not an
	 * introduction, and is the one thing here the build knows and the author cannot. So the
	 * introduction is left alone and this is what gets generated.
	 *
	 * The whole page is written here rather than assembled from generated templates. Templates
	 * were the earlier shape and their cost fell on every site rather than on this one: a template
	 * the build writes unconditionally is a title in the site's own Template namespace that the
	 * site can no longer use, and no site should carry a reserved name so that this page can be
	 * built out of parts.
	 *
	 * What it lists is what the published site carries and nothing else. MediaWiki, because
	 * buildScripts bakes its module closure into modules-static.js; the extensions and skins,
	 * because buildStyles writes their CSS and their scripts go into the same bundle. PHP, the
	 * database and the tools that made the build are not in it: a static site does not ship them,
	 * and a licenses page that named them would be claiming a redistribution that never happened.
	 *
	 * A source page of the configured name is used as written, as every generated page's is: a site
	 * that writes this one itself has taken the question on deliberately. Set the name empty and no
	 * page is written and the footer entry goes with it, which is a site saying it will acknowledge
	 * this its own way.
	 *
	 * It is written once per language the site is built in, at "<Page>/<lang>", because a notice
	 * nobody can read is not one either: every string on it is a message, wikven's own and core's
	 * Special:Version ones, so each language costs a render and nothing else. The footer's
	 * Special:MyLanguage link then lands on the reader's own copy.
	 *
	 * The languages are the ones the source tree has translations in, not every language MediaWiki
	 * knows. Nothing could reach the rest: resolveTranslationLinks settles each link by the
	 * language of the page carrying it, and a reader is only ever on a page in a language the site
	 * has content in, so a copy in any other language would be a file no link in the export points
	 * at -- several hundred of them, once per skin, in the search index.
	 */
	private function setLicensesPage(): void {
		$title = LicensesPage::title();
		if (!$title) {
			return;
		}

		// Only where the site left the page to the build. A site that wrote its own is writing the
		// language pages under it too, or marking it for translation and letting buildTranslations
		// write them; either way a copy of ours under its title would be one it never asked for.
		if (!$title->exists()) {
			$this->savePage($title->getPrefixedText(), $this->licensesText(null), 'Generate the licenses page');
			foreach ($this->translatedLanguages() as $lang) {
				// Left alone for the same reason as the page above it: a page the source provided
				// under this title is the site's, and overwriting it would say nothing.
				$copy = Title::newFromText(LicensesPage::inLanguage($title, $lang));
				if ($copy && !$copy->exists()) {
					$this->savePage(
						$copy->getPrefixedText(),
						$this->licensesText($lang),
						"Generate the licenses page in $lang"
					);
				}
			}
		}

		// The footer entry is chrome, and a skin preview has none of wikven's -- see BuildFor.
		// The page is still written, so nothing is lost; what is missing is the link to it, and a
		// preview that quietly dropped the one link saying what the site carries would be teaching
		// the wrong lesson about the mode.
		if (BuildFor::skinPreview()) {
			$this->output(
				"Wikven: skin preview -- the footer does not link {$title->getPrefixedText()};"
				. " a published site links it from every page.\n"
			);
		}
	}

	/** The whole licenses page, in the named language or the wiki's content language for null. */
	private function licensesText(?string $lang): string {
		return (
			$this->contentMsg('wikven-licenses-intro', $lang)
			. "\n\n"
			. rtrim($this->coreTable($lang) . $this->componentLists($lang))
			. "\n"
		);
	}

	/**
	 * Every language the source tree carries a translation in, bar the content language, which the
	 * page at the unsuffixed title is already written in.
	 *
	 * Empty without Translate, where nothing renders a per-language page and
	 * resolveTranslationLinks never runs to link one.
	 *
	 * @return list<string>
	 */
	private function translatedLanguages(): array {
		if (!ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			return [];
		}
		$source = rtrim((string)( $GLOBALS['wgWikvenSourceDirectory'] ?? '' ), '/');
		if ($source === '' || !is_dir($source)) {
			return [];
		}

		$services = $this->getServiceContainer();
		$contentLanguage = $services->getContentLanguage()->getCode();
		$languages = TranslationSource::languages(
			$source,
			[$services->getLanguageNameUtils(), 'isKnownLanguageTag']
		);
		// The page at the unsuffixed title is written in the content language already.
		return array_values(array_diff($languages, [$contentLanguage]));
	}

	/**
	 * What the build ran on: MediaWiki, PHP and the database, with their versions.
	 *
	 * Only one of the three is redistributed, and it is the one that carries the most: every page
	 * of the export loads modules-static.js, which is MediaWiki's module closure, so core goes out
	 * with every site whatever else it installs -- and it is the component the registry below
	 * cannot answer for, since it is not an entry in it. So only MediaWiki's license is named. PHP
	 * and the database ran the build and stayed behind; a license beside them would read as a claim
	 * that the site ships them, and it ships neither. The lead-in says so, because an empty cell on
	 * its own would read as a component that declared nothing.
	 *
	 * They are here at all because "what built this" is worth knowing and an export has no
	 * Special:Version to ask.
	 *
	 * MediaWiki's license is read from core's own composer.json rather than written here, the same
	 * way each extension's is read from its extension.json: this file should not be the second
	 * place that fact is kept. Unreadable leaves the cell empty, because a guess on a licenses page
	 * is worse than a gap.
	 */
	private function coreTable(?string $lang): string {
		$db = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$software = [
			['[https://www.mediawiki.org/ MediaWiki]', MW_VERSION, self::coreLicense()],
			['[https://www.php.net/ PHP]', PHP_VERSION . ' (' . PHP_SAPI . ')', ''],
			[ucfirst($db->getType()), $db->getServerVersion(), '']
		];

		$text = '== ' . $this->contentMsg('version-software', $lang) . " ==\n";
		$text .= $this->contentMsg('wikven-licenses-software', $lang) . "\n\n";
		$text .=
			"{| class=\"wikitable\"\n! "
			. $this->contentMsg('version-software-product', $lang)
			. ' !! '
			. $this->contentMsg('version-software-version', $lang)
			. ' !! '
			. $this->contentMsg('version-ext-colheader-license', $lang)
			. "\n";
		foreach ($software as [$product, $version, $license]) {
			$text .= "|-\n| $product\n| $version\n| $license\n";
		}
		return $text . "|}\n\n";
	}

	/** The license MediaWiki declares for itself, or '' where its manifest cannot be read. */
	private static function coreLicense(): string {
		$manifest = MW_INSTALL_PATH . '/composer.json';
		if (!is_readable($manifest)) {
			return '';
		}
		$declared = json_decode((string)file_get_contents($manifest), true);
		return is_array($declared) && is_string($declared['license'] ?? null) ? $declared['license'] : '';
	}

	/** The extensions and skins, with the version and license each one declares for itself. */
	private function componentLists(?string $lang): string {
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
		return (
			$this->componentTable('version-extensions', 'version-ext-colheader-name', $extensions, $lang)
			. $this->componentTable('version-skins', 'version-skin-colheader-name', $skins, $lang)
		);
	}

	/** Write one page the build generates, creating or replacing it. */
	private function savePage(string $titleText, string $text, string $summary): void {
		$title = Title::newFromText($titleText);
		if (!$title) {
			return;
		}
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment($summary));
	}

	/**
	 * A message in the language a generated page is written in (these pages are content, not UI
	 * chrome), which is the wiki's content language unless a language is named.
	 */
	private function contentMsg(string $key, ?string $lang = null): string {
		$message = wfMessage($key);
		return ( $lang === null ? $message->inContentLanguage() : $message->inLanguage($lang) )->text();
	}

	/**
	 * A wikitext table of components with versions, project links and licenses, under the given
	 * messages.
	 *
	 * The license is the one the component declares in its own extension.json, which is the only
	 * place that fact is kept and the reason a page acknowledging it need not be written by hand.
	 * Special:Version links each one to the license text it ships; an export has no such page, so
	 * the identifier stands on its own, and a component declaring none leaves the cell empty rather
	 * than being guessed at.
	 */
	private function componentTable(
		string $headingKey,
		string $nameColKey,
		array $things,
		?string $lang
	): string {
		if (!$things) {
			return '';
		}
		ksort($things);
		// Sortable: a reader looking for one license reads down the license column, and these are
		// the only tables on the page long enough for that to be the difference between finding it
		// and reading everything. buildScripts sees the class and puts jquery.tablesorter in the
		// bundle, which an export needs because nothing can fetch it later (#483).
		$text = '== ' . $this->contentMsg($headingKey, $lang) . " ==\n";
		$text .=
			"{| class=\"wikitable sortable\"\n! "
			. $this->contentMsg($nameColKey, $lang)
			. ' !! '
			. $this->contentMsg('version-ext-colheader-version', $lang)
			. ' !! '
			. $this->contentMsg('version-ext-colheader-license', $lang)
			. "\n";
		foreach ($things as $thingName => $credits) {
			$url = $credits['url'] ?? '';
			$label = $url !== '' ? "[$url $thingName]" : $thingName;
			$text .=
				"|-\n| $label\n| " . ( $credits['version'] ?? '' ) . "\n| " . ( $credits['license-name'] ?? '' ) . "\n";
		}
		$text .= "|}\n\n";
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
