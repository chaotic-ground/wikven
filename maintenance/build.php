<?php

namespace MediaWiki\Extension\Wikven;

use ImportImages;
use Maintenance;
use MediaWiki\Category\Category;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\Wikven\Build\BuildConcurrency;
use MediaWiki\Extension\Wikven\Build\BuildFor;
use MediaWiki\Extension\Wikven\Build\BuildTimes;
use MediaWiki\Extension\Wikven\Build\SkinPass;
use MediaWiki\Extension\Wikven\PageTranslation\TranslationSource;
use MediaWiki\Extension\Wikven\Source\SourceAuthors;
use MediaWiki\Extension\Wikven\Source\SourceFile;
use MediaWiki\Extension\Wikven\Source\SourceHistory;
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
use UtfNormal\Validator;
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

	/**
	 * How many shares the parses ahead of the import are split into, before the machine has a say.
	 *
	 * passLimit() answers with the smaller of this and the processors a build may use.
	 */
	private const WARM_SHARDS = 8;

	/**
	 * Whether the job that builds the search index ran, which is what tells a bundle that failed to
	 * build from a site that had nothing to index. Only the orchestrator queues it.
	 */
	private bool $searchIndexRan = false;

	/** What each phase of this process took, printed when it is over. */
	private BuildTimes $times;

	public function __construct() {
		parent::__construct();
		$this->times = new BuildTimes();
		$this->addDescription('Run the full wikven static-site build in a single process.');
	}

	public function execute() {
		// WIKVEN_BUILD_SKIN set renders one skin; orchestrator populates then spawns a pass per skin.
		if ((string)getenv('WIKVEN_BUILD_SKIN') !== '') {
			$this->renderSkin();
			return;
		}

		$this->announceSkinPreview();

		$ip = $GLOBALS['IP'];
		$own = __DIR__;

		// Before the output directory is emptied, because a site this build cannot render is better
		// told so with its last bake still in place.
		$this->phase('check what the site listed', $this->assertEverythingListedIsHere(...));
		$this->phase('check the Lua', $this->checkLuaAgainstThisBuild(...));
		$this->phase('clear the output directory', $this->clearOutputDirectory(...));
		$this->phase('set the main page', $this->setMainPage(...));
		$this->phase('import the images', $this->importImages(...), "$ip/maintenance/importImages.php");
		$this->phase('warm the parse caches', $this->warmParseCaches(...), "$own/importWikitext.php");
		$this->step('import the pages', ImportWikitext::class, "$own/importWikitext.php");
		$this->phase('check the main page arrived', $this->assertMainPageExists(...));
		$this->phase('write the licenses page', $this->setLicensesPage(...));
		$this->phase('write the settings page', $this->setSettingsPage(...));
		$this->phase('drop the dead place links', $this->dropDeadPlaceLinks(...));
		$this->phase('drop the dead category link', $this->dropDeadCategoryLink(...));
		// Materialize content translations before RunJobs so rendered translation pages get exported.
		$this->step('build the translations', BuildTranslations::class, "$own/buildTranslations.php");
		$this->phase('run the jobs', $this->runJobs(...), "$ip/maintenance/runJobs.php");
		// Categories are written by the links update each edit queues, which runJobs above runs, so
		// this is the first point they are known -- and it is before the passes.
		$this->phase('check the categories', $this->assertNamedCategoriesAreEmpty(...));
		// Every page the export will hold now exists and nothing writes another revision after
		// this, so this is where each page can be told when it was last edited, and by whom.
		$this->phase('stamp the source history', $this->stampSourceHistory(...));
		$this->phase('hide the build authors', $this->hideBuildAuthors(...));
		$this->phase('forget the cached revision rows', $this->forgetCachedRevisionRows(...));
		// The content is final here, so this is the last write the database needs and the passes
		// below can be readers of it.
		$this->phase('freeze the page timestamps', $this->freezePageTouched(...));
		// The search index is built by now, by the job runJobs() holds back to the end, and every
		// pass below copies it. Settled here so the one copy they all take is stable.
		$this->phase('settle the search index', $this->stabilizeSearchIndex(...));

		$config = $this->getConfig();
		$skins = (array)$config->get('WikvenSkins');
		if (!$skins) {
			$skins = [(string)$config->get('DefaultSkin')];
		}
		$this->phase('render the skins', $this->renderSkinPasses(...), array_values($skins));
		$this->output($this->times->report('the build'));
	}

	/**
	 * Run one phase of this process, remembering what it took.
	 *
	 * Every phase rather than the ones a guess calls slow: what this is for is the stretch nobody
	 * expected, and a guess is what left it unmeasured.
	 *
	 * @param string $name What the phase does, as the report names it.
	 * @param callable $work
	 * @param mixed ...$arguments Handed to $work.
	 * @return mixed What $work answered, for the phases whose answer is read.
	 */
	private function phase(string $name, callable $work, mixed ...$arguments) {
		$started = hrtime(true);
		try {
			return $work(...$arguments);
		} finally {
			$this->times->add($name, ( hrtime(true) - $started ) / 1e9);
		}
	}

	/**
	 * Say that a skin preview is experimental, once, before the work starts.
	 *
	 * Not unfinished: this renders on the MediaWiki the build carries and says nothing about the
	 * others. From the orchestrating pass, so it is said once.
	 */
	private function announceSkinPreview(): void {
		if (!BuildFor::skinPreview()) {
			return;
		}
		$this->output(
			'Wikven: skin preview is experimental. It renders on the MediaWiki this build carries ('
			. MW_VERSION
			. "), and says nothing about how the skin renders on any other.\n"
		);
	}

	/**
	 * Say what this site's Lua and this build's Lua make of each other; see Scribunto.
	 *
	 * Only one of the two ends the build, and it is the one the site asked for: Scribunto listed
	 * where nothing can run it.
	 */
	private function checkLuaAgainstThisBuild(): void {
		$source = rtrim((string)$this->getConfig()->get('WikvenSourceDirectory'), '/');
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
	 * Not ImportWikitext's list, which is filtered by content model -- so with Scribunto absent a
	 * module file would be missing from exactly the case worth catching.
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
	 * Four ways, in the order Scribunto would find them: luasandbox, then the standalone engine's
	 * external lua -- this site's, one on PATH, or the one Scribunto ships and falls back to.
	 */
	private static function luaEngineAvailable(): bool {
		if (extension_loaded('luasandbox')) {
			return true;
		}
		// Scribunto's own setting, and this is a static with no getConfig() to ask. $GLOBALS also
		// answers where Scribunto is not loaded at all, which is the case being reported.
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
	 * Existing is not enough: none of the five Scribunto bundles is arm64, and it picks among them
	 * by PHP_OS and PHP_INT_SIZE, so on arm it selects the x86-64 one.
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
	 * The search index is held back to the end: SifterSearch enqueues a rebuild per revision, so a
	 * bake was running Pagefind 21 times.
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
			$this->searchIndexRan = true;
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
	 * The file's mtime gave the moment CI cloned the repository -- the one date SOURCE_DATE_EPOCH
	 * could not freeze (#406).
	 */
	private function stampSourceHistory(): void {
		$config = $this->getConfig();
		$source = rtrim((string)$config->get('WikvenSourceDirectory'), '/');
		$history = SourceHistory::forSource($source, (string)$config->get('WikvenSourceHistoryFile'));

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
	 * SourceFile's naming convention answers which file that is. The one page without one is the
	 * source-language page Translate adds.
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
	 * Minerva was offering the build's own account as the last editor (#406). DELETED_USER is how
	 * MediaWiki says an author is not public.
	 */
	private function hideBuildAuthors(): void {
		$names = [User::MAINTENANCE_SCRIPT_USER];
		if (ExtensionRegistry::getInstance()->isLoaded('Translate')) {
			$names[] = (string)$this->getConfig()->get('TranslateFuzzyBotName');
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
	 * RevisionStore caches that row for a week, keyed on ids an edit in place does not change.
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
	 * Once in the orchestrator rather than per pass, and one of the two writes that kept a pass
	 * from being a reader (#407).
	 */
	private function freezePageTouched(): void {
		$dbw = $this->getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update('page')
			->set(['page_touched' => $dbw->timestamp(self::FROZEN_TIMESTAMP)])
			->where(ISQLPlatform::ALL_ROWS)
			->caller(__METHOD__)
			->execute();

		// The modules read page_touched through LinkCache rather than the row just written; this
		// process warmed it filling the wiki, and the passes below boot onto its object cache.
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
	 * the first starts, and each writes its own output directory (#407).
	 *
	 * @param string[] $skins
	 */
	private function renderSkinPasses(array $skins): void {
		$command = $this->selfCommand(__FILE__);
		$limit = $this->passLimit(count($skins));
		$databases = $this->copyDatabasePerPass($skins);

		$this->output('Rendering ' . count($skins) . ' skin(s), up to ' . $limit . " at a time\n");

		$queued = $skins;
		$running = [];
		$finished = [];
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
				// process's own stdout as they went would interleave into something no one could
				// attribute a failure from.
				$this->reportPass($skin, $running[$skin], $exit);
				$finished[$skin] = ['exit' => $exit, 'output' => $running[$skin]['output'][1]];
				unset($running[$skin]);
			}
		}

		$this->removeDatabaseCopies($databases);
		// What the passes returned is not enough to tell a finished one from a dead one; SkinPass
		// weighs that with what they said, and answers in the words to stop on.
		foreach (SkinPass::failures($finished) as $failure) {
			$this->fatalError($failure);
		}
	}

	/**
	 * Parse every page before the import parses them one at a time.
	 *
	 * What a first parse costs is not the writing: the syntax highlighter runs pygments once per
	 * code block. These reads share nothing but the cache they fill.
	 *
	 * @param string $script The import script, run in parse-only mode.
	 */
	private function warmParseCaches(string $script): void {
		$shards = $this->passLimit(self::WARM_SHARDS);
		if ($shards < 2) {
			return;
		}
		$command = array_merge($this->selfCommand($script), ['--parse-only']);
		$children = [];
		for ($shard = 0; $shard < $shards; $shard++) {
			$children[$shard] = $this->startChild(array_merge($command, ["--shard=$shard/$shards"]));
		}
		// Not fatal: a shard that failed costs the time it would have saved, and the import, which
		// parses every page itself, is where a parse that cannot be done at all is a failure.
		$failed = 0;
		foreach ($children as $child) {
			$failed += $this->waitForChild($child) === 0 ? 0 : 1;
		}
		if ($failed > 0) {
			$this->error(
				"Wikven: $failed of $shards parse(s) ahead of the import failed; it will do that work itself."
			);
		}
		$this->output("Wikven: parsed the pages in $shards share(s) before importing them\n");
	}

	/**
	 * Start one child with its output on a pipe, for a caller that reads it when the child is over.
	 *
	 * @param string[] $command
	 * @return array{process:resource,pipes:array<int,?resource>}
	 */
	private function startChild(array $command): array {
		$descriptors = [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$pipes = [];
		$process = proc_open($command, $descriptors, $pipes, $GLOBALS['IP'], getenv());
		if ($process === false) {
			$this->fatalError('Wikven: could not start ' . implode(' ', $command));
		}
		return ['process' => $process, 'pipes' => $pipes];
	}

	/**
	 * Wait for one child, printing what it wrote to stderr.
	 *
	 * Its standard output is dropped: what these children say about their own progress is not this
	 * log's, and anything that went wrong went to the other pipe.
	 *
	 * @param array{process:resource,pipes:array<int,?resource>} $child
	 * @return int The child's exit code.
	 */
	private function waitForChild(array $child): int {
		stream_get_contents($child['pipes'][1]);
		$errors = trim((string)stream_get_contents($child['pipes'][2]));
		foreach ($child['pipes'] as $pipe) {
			fclose($pipe);
		}
		$exit = proc_close($child['process']);
		if ($errors !== '') {
			$this->error($errors);
		}
		return $exit;
	}

	/**
	 * The argv that runs one of this build's own maintenance scripts in a fresh boot.
	 *
	 * @param string $script The script to run, as an absolute path.
	 * @return string[]
	 */
	private function selfCommand(string $script): array {
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
		return array_merge($prefix, ['maintenance/run.php', $script]);
	}

	/**
	 * Start one skin's pass in a fresh boot, with its output on pipes this process reads.
	 *
	 * @param string[] $command
	 * @return array{process:resource,pipes:array<int,?resource>,output:array<int,string>}
	 */
	private function startSkinPass(array $command, string $skin, string $databaseDirectory): array {
		// The skin, the database and the working directory are passed to the child alone, so this
		// process's own environment and cwd stay untouched. Both are set even when empty, so a pass
		// never inherits a value another run left behind.
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
		// Started here rather than timed inside the pass, so what it says includes the boot a pass
		// needs before it can say anything at all.
		return [
			'process' => $process,
			'pipes' => $pipes,
			'output' => [1 => '', 2 => ''],
			'started' => hrtime(true)
		];
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
		$took = BuildTimes::seconds(( hrtime(true) - $pass['started'] ) / 1e9);
		$this->output("--- $skin pass" . ( $exit === 0 ? '' : " failed (exit $exit)" ) . ", $took ---\n");
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
	 * The object cache is a table of this database and SQLite takes one writer at a time. Copied
	 * after the populate phase, so every pass inherits its cached lookups.
	 *
	 * @param string[] $skins
	 * @return array<string,string> Skin to the directory holding its copy; empty where they share one.
	 */
	private function copyDatabasePerPass(array $skins): array {
		$config = $this->getConfig();
		$directory = rtrim((string)$config->get('SQLiteDataDir'), '/');
		if (count($skins) < 2 || $config->get('DBtype') !== 'sqlite' || !is_dir($directory)) {
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
		$dir = rtrim((string)$this->getConfig()->get('WikvenHtmlDirectory'), '/');
		if ($dir !== '' && !wfMkdirParents($dir)) {
			$this->fatalError("Wikven: could not create output directory $dir");
		}

		// Before anything renders or is dumped: the bundle path reaches the client inside the script
		// bundle buildScripts writes below, so this pass has to be pointed at its own copy first.
		$searchBundle = $this->phase('point the search at this copy', $this->pointSearchAtThisCopy(...));

		$this->step('render the pages', RebuildFileCache::class, "$ip/maintenance/rebuildFileCache.php", [
			'overwrite' => true
		]);
		// RebuildFileCache renders in the content language; re-render translations in their own.
		$this->step('re-render the translations', RetranslateChrome::class, "$own/retranslateChrome.php");
		// Every page is rendered by now: drop what each one recorded about the request that made it.
		$this->step('strip the build stamps', StripBuildStamps::class, "$own/stripBuildStamps.php");
		$this->step('build the styles', BuildStyles::class, "$own/buildStyles.php");
		// Opt-in: bake ULS webfonts into a static stylesheet rewriteScripts links below.
		$this->step('bake the webfonts', BakeWebfonts::class, "$own/bakeWebfonts.php");
		$this->step('build the scripts', BuildScripts::class, "$own/buildScripts.php");
		$this->step('rewrite the scripts', RewriteScripts::class, "$own/rewriteScripts.php");
		// Minerva takes no navigation from the sidebar, so its menu is filled in the rendered pages.
		$this->step('fill the Minerva menu', FillMinervaMenu::class, "$own/fillMinervaMenu.php");
		$this->step('store the images', StoreImages::class, "$own/storeImages.php");
		$named = $this->nameCachedPages("$own/rename.php");
		// Rename has expanded translation pages into "<Page>/<lang>.html"; resolve MyLanguage links now.
		$this->step(
			'resolve the translation links',
			ResolveTranslationLinks::class,
			"$own/resolveTranslationLinks.php"
		);
		// After the pages have their final names and links, so what the sitemap names is what the
		// site serves. Writes nothing unless the site said where it will be published.
		$this->step('build the sitemap', BuildSitemap::class, "$own/buildSitemap.php");

		// SkippedHistoryAction leaves RebuildFileCache nothing to write here, so this finds nothing
		// on a normal bake; it stays as the guard for a pass over an output directory that already
		// holds a history/ tree, which the static host would not serve.
		$history = "$dir/history";
		if (is_dir($history)) {
			// Say so rather than tidy up in silence: a tree here means the skipped action is no longer
			// being asked for, and every page has paid for a render nobody reads.
			$this->output("Wikven: removing a history/ tree the export does not want ($history)\n");
			$this->removeDirectory($history);
		}

		// Last, so nothing above walks the bundle looking for pages to rewrite.
		if ($searchBundle !== null) {
			$this->phase('copy the search bundle', $this->copySearchBundle(...), $searchBundle);
		}

		$this->output($this->times->report('the ' . getenv('WIKVEN_BUILD_SKIN') . ' pass'));
		// After everything, because that is the whole of what it says; see SkinPass.
		$this->output(SkinPass::wrote($named) . "\n");
	}

	/**
	 * Put the search bundle's entry file in an order it will come out in again next bake.
	 *
	 * Pagefind writes its language map in whatever order it iterated, so two bakes disagree on
	 * this file alone (#411).
	 */
	private function stabilizeSearchIndex(): void {
		// SifterSearch's own settings stay on $GLOBALS here and below: they exist only where that
		// extension is loaded, and Config::get() throws on a key nothing has defined.
		$bundle = rtrim((string)( $GLOBALS['wgSifterSearchOutputDir'] ?? '' ), '/');
		if ($bundle === '') {
			return;
		}
		$path = "$bundle/" . Search::INDEX_ENTRY_FILE;
		if (!is_file($path)) {
			// Three things used to end here together and only two are fine: search off, search on with
			// nothing to index, and search on with a bundle that did not get built. The job having run
			// tells the third.
			if ($this->searchIndexRan) {
				$this->fatalError(
					"Wikven: the search index was not written ($path), though the job that builds it"
					. ' ran. Every page would ship a search box with nothing behind it; aborting the'
					. ' build. Set SifterSearchOutputDir to an empty value to build without search.'
				);
			}
			return;
		}
		$stable = Search::stableIndexEntry((string)file_get_contents($path));
		if ($stable !== null) {
			file_put_contents($path, $stable);
		}
	}

	/**
	 * Point this pass's search at a bundle of its own, and say where it goes.
	 *
	 * The index was the one thing a skin copy read out of the export root, which sent a reader
	 * back to the root copy (#399).
	 *
	 * @return ?string The directory the bundle must be copied to, or null where nothing is to change.
	 */
	private function pointSearchAtThisCopy(): ?string {
		$config = $this->getConfig();
		$skin = (string)$config->get('DefaultSkin');
		$main = (string)$config->get('WikvenMainSkin');
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
		return rtrim((string)$config->get('WikvenHtmlDirectory'), '/') . '/' . basename(rtrim($path, '/'));
	}

	/**
	 * Duplicate the built Pagefind bundle into this pass's copy of the site.
	 *
	 * The index job is held back to the end of the populate phase, before any skin renders, so
	 * nothing writes to the bundle while this reads it.
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
		$dir = rtrim((string)$this->getConfig()->get('WikvenHtmlDirectory'), '/');
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

	/**
	 * Stop on a category the site said a finished build must find empty.
	 *
	 * MediaWiki files a page with a fault in it into a tracking category rather than refusing it,
	 * and the export publishes the page and drops the category.
	 */
	private function assertNamedCategoriesAreEmpty(): void {
		$named = (array)$this->getConfig()->get('WikvenFailOnCategories');
		$members = [];
		foreach ($named as $entry) {
			$title = $this->categoryNamed((string)$entry);
			if ($title) {
				$members[$title->getText()] = $this->pagesIn($title);
			}
		}
		if (!$members) {
			return;
		}
		// Every one of them at once: a reader who fixes the first and bakes again to meet the
		// second has paid for a whole build to be told something this run already knew.
		$failures = FailOnCategories::failures($members);
		if ($failures) {
			$this->fatalError(implode("\n", $failures));
		}
	}

	/**
	 * The category one entry of WikvenFailOnCategories names, or null where it names none.
	 *
	 * An entry is a message key where this wiki has that message and a category title otherwise, a
	 * message being how MediaWiki holds a tracking category's name.
	 */
	private function categoryNamed(string $entry): ?Title {
		$message = wfMessage($entry)->inContentLanguage();
		if ($message->exists()) {
			if ($message->isDisabled()) {
				return null;
			}
			$entry = $message->plain();
			// A tracking category message may name one category per namespace, through $1. Rendering that
			// here would ask about one namespace and call the rest empty, so it is passed over.
			if (str_contains($entry, '{{')) {
				return null;
			}
		}
		$title = Title::newFromText($entry, NS_CATEGORY);
		if (!$title || $title->getNamespace() !== NS_CATEGORY) {
			$this->fatalError("Wikven: WikvenFailOnCategories names '$entry', which is not a category");
		}
		return $title;
	}

	/**
	 * The pages in one category, as this wiki names them.
	 *
	 * Through Category rather than a query of our own: categorylinks reaches its rows by way of
	 * linktarget in this MediaWiki and did not in the last one.
	 *
	 * @return string[]
	 */
	private function pagesIn(Title $category): array {
		$pages = [];
		foreach (Category::newFromTitle($category)->getMembers() as $member) {
			$pages[] = $member->getPrefixedText();
		}
		return $pages;
	}

	/**
	 * Name the cached pages, and say how many there were.
	 *
	 * The number is this pass's own account of what it produced, and the pass above has no other
	 * way to come by it; see SkinPass.
	 */
	private function nameCachedPages(string $file): int {
		$rename = $this->step('name the pages', Rename::class, $file);
		// createChild() is typed to Maintenance, and this is the one step whose answer is read.
		return $rename instanceof Rename ? $rename->named : 0;
	}

	/**
	 * Run one build step as a child maintenance script, applying $options first.
	 *
	 * The child is handed back for the one caller that wants a number out of it; every other one
	 * runs the step for its effect and drops it.
	 *
	 * @param string $name What the timing report calls this step.
	 * @param string $class
	 * @param string $file
	 * @param array $options
	 */
	private function step(string $name, string $class, string $file, array $options = []): Maintenance {
		$child = $this->createChild($class, $file);
		foreach ($options as $option => $value) {
			$child->setOption($option, $value);
		}
		// A child returning false signals failure (e.g. a page didn't import); abort the build.
		if ($this->phase($name, $child->execute(...)) === false) {
			$this->fatalError("Wikven: $class reported failures; aborting the build.");
		}
		return $child;
	}

	/**
	 * Stop on a name in the site's extensions or skins list that nothing here provides.
	 *
	 * WikvenSettings collects these rather than failing, because fetchExtensions.php boots it to
	 * install the very components that are missing. By here they are installed.
	 */
	private function assertEverythingListedIsHere(): void {
		$missing = $this->getConfig()->get('WikvenMissing');
		if (!is_array($missing) || $missing === []) {
			return;
		}
		foreach ($missing as $one) {
			$this->error("Wikven: nothing provides $one");
		}
		$this->fatalError(
			'Wikven: the site lists '
			. count($missing)
			. ' name(s) nothing here provides, and a'
			. ' build that went on would publish a site without them; aborting the build.'
		);
	}

	/** Import source-dir images into the File: namespace so pages render with local thumbnails. */
	private function importImages(string $file): void {
		$config = $this->getConfig();
		$directory = rtrim((string)$config->get('WikvenSourceDirectory'), '/');
		$extensions = (array)$config->get('FileExtensions');
		$sources = ImageImport::sources($directory, $extensions);

		// The walk follows links, as core's does, so an image that is one -- or one under a linked
		// directory -- would have the build upload whatever is on the other side.
		$outside = ImageImport::outside($directory, $sources);
		if ($outside !== []) {
			foreach ($outside as $one) {
				$this->error("Wikven: '$one' is not a file in $directory");
			}
			$this->fatalError(
				'Wikven: an image has to be a file in the source tree, since what a link points at is'
				. ' not part of what you are publishing. Replace each with the file; aborting the build.'
			);
		}

		// A File: title is the file's name alone, so two images sharing a name in two directories are
		// one page, and the importer would take the first and skip the second with a line nobody reads.
		$collisions = ImageImport::collisions($sources);
		if ($collisions !== []) {
			foreach ($collisions as $name => $paths) {
				$this->error("Wikven: more than one image would import as '$name': " . implode(', ', $paths));
			}
			$this->fatalError(
				'Wikven: an image is named by its file name alone, so each of those would be the same'
				. ' page. Rename all but one of each; aborting the build.'
			);
		}

		$child = $this->createChild(ImportImages::class, $file);
		$child->setArg(0, $directory);
		$child->setOption('extensions', implode(',', $extensions));
		// No --skip-dupes. It skips a file whose *content* matches one already imported, so a logo
		// shipped twice under two names left the second with no File: page.
		//
		// Subdirectories, because pages are read from them.
		$child->setOption('search-recursively', true);
		// An image the wiki rejected leaves every page embedding it with a red File: link, so this
		// step aborts the build. A source holding no image at all answers the same false and is not
		// a failure.
		if (ImageImport::failed($child->execute(), $sources)) {
			// The count is of the images offered, not of the ones that failed: the importer's own
			// summary above holds that number, and it named each file as it choked on it.
			$count = count($sources);
			$this->fatalError("Wikven: not all $count image(s) in $directory imported; aborting the build.");
		}
		$this->assertEveryImageGotItsPage($sources);
	}

	/**
	 * Every source image now has a File: page, or the build stops naming the ones that do not.
	 *
	 * The importer answers "I skipped that one" with the same success as "I imported them all".
	 *
	 * @param string[] $sources Absolute paths, as ImageImport::sources() returns them.
	 */
	private function assertEveryImageGotItsPage(array $sources): void {
		$missing = [];
		foreach ($sources as $path) {
			// The importer's own two lines, so this asks about the page it would have made rather
			// than about one this file names differently.
			$title = Title::makeTitleSafe(NS_FILE, Validator::cleanUp(basename($path)));
			if ($title === null || !$title->exists()) {
				$missing[] = $path;
			}
		}
		if ($missing === []) {
			return;
		}
		foreach ($missing as $path) {
			$this->error("Wikven: '$path' did not become a File: page");
		}
		$this->fatalError(
			'Wikven: '
			. count($missing)
			. ' image(s) were offered to the importer and are not here,'
			. ' so every page embedding one would publish a red link; aborting the build.'
		);
	}

	/** Point the wiki's main page at $wgWikvenMainPage (imported later; see assertMainPageExists). */
	private function setMainPage(): void {
		// Whatever the installer made the main page, before the message below repoints it.
		$installed = Title::newMainPage();

		$title = Title::newFromText('MediaWiki:Mainpage');
		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle($title);

		$updater = $page->newPageUpdater($user);
		$content = ContentHandler::makeContent((string)$this->getConfig()->get('WikvenMainPage'), $title);
		$updater->setContent(SlotRecord::MAIN, $content);
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Set the main page'));

		$this->dropInstalledMainPage($installed, $user);
	}

	/**
	 * Delete the page the installer wrote, unless the source provides one by that name.
	 *
	 * It holds MediaWiki's "has been installed" boilerplate, and is the one page created before
	 * wikven's settings load, so its revision kept the real clock.
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
	 * the browser rather than in a user account. It stands in for Special:MobileOptions, and the
	 * controls are drawn into the placeholders below.
	 */
	private function setSettingsPage(): void {
		$config = $this->getConfig();
		$name = (string)$config->get('WikvenSettingsPage');
		if ($name === '') {
			return;
		}
		$title = Title::newFromText($name);
		if (!$title || $title->exists()) {
			return;
		}

		// Special:MobileOptions is an empty form its own script fills, so this page is the same
		// empty form. Wikitext carries no <form> and that stylesheet's layout rules all name one,
		// so fillMinervaMenu.php puts a real form inside this.
		$text = "<div id=\"wikven-settings-form\"></div>\n";
		if (count((array)$config->get('WikvenSkins')) > 1) {
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
	 * Every page carries other people's code -- MediaWiki's module closure, each skin's CSS -- and
	 * this lists what the export carries and nothing else.
	 */
	private function setLicensesPage(): void {
		$title = LicensesPage::title();
		if (!$title) {
			return;
		}

		// Only where the site left the page to the build. A site that wrote its own is writing the
		// language pages under it too, so a copy of ours would be one it never asked for.
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

		// The footer entry is chrome, and a skin preview has none of wikven's -- see BuildFor. The
		// page is still written; what is missing is the link to it.
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
		$source = rtrim((string)$this->getConfig()->get('WikvenSourceDirectory'), '/');
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
	 * What the build ran on: MediaWiki, PHP and the database, with their versions, and the server
	 * besides where a standalone binary is what ran it.
	 *
	 * Only MediaWiki is redistributed, and the registry cannot answer for it.
	 */
	private function coreTable(?string $lang): string {
		$db = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$software = array_merge(
			[
				['[https://www.mediawiki.org/ MediaWiki]', MW_VERSION, self::coreLicense()],
				['[https://www.php.net/ PHP]', PHP_VERSION . ' (' . PHP_SAPI . ')', ''],
				[ucfirst($db->getType()), $db->getServerVersion(), '']
			],
			self::runtimeSoftware()
		);

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

	/**
	 * The server a standalone-binary build ran on, as further rows for the table above, or none
	 * where it ran on something else.
	 *
	 * FrankenPHP and Caddy are compiled into the executable wikven redistributes, and a Go build
	 * records no licenses.
	 *
	 * @return list<array{string, string, string}>
	 */
	private static function runtimeSoftware(): array {
		$declared = (string)getenv('WIKVEN_RUNTIME');
		if ($declared === '') {
			return [];
		}

		$known = [
			'FrankenPHP' => ['https://frankenphp.dev/', 'MIT'],
			'Caddy' => ['https://caddyserver.com/', 'Apache-2.0'],
			'Mercure' => ['https://mercure.rocks/', 'AGPL-3.0'],
			'Vulcain' => ['https://vulcain.rocks/', 'AGPL-3.0']
		];

		$rows = [];
		foreach (explode(';', $declared) as $entry) {
			[$name, $version] = array_pad(explode(' ', trim($entry), 2), 2, '');
			// An entry this version has no license for is dropped rather than shown bare: a licenses page
			// is the wrong place to learn that wikven has stopped keeping up with what it ships.
			if (!isset($known[$name])) {
				continue;
			}
			[$url, $license] = $known[$name];
			$rows[] = ["[$url $name]", $version, $license];
		}
		return $rows;
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
	 * The license is the one the component declares in its own extension.json, and a component
	 * declaring none leaves the cell empty.
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
		// Sortable: these are the only tables on the page long enough for that to matter. buildScripts
		// sees the class and puts jquery.tablesorter in the bundle, which an export needs (#483).
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
		$name = (string)$this->getConfig()->get('WikvenMainPage');
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
