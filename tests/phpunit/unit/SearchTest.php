<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use FilesystemIterator;
use MediaWiki\Extension\Wikven\Search;
use MediaWikiUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @covers \MediaWiki\Extension\Wikven\Search
 */
class SearchTest extends MediaWikiUnitTestCase {
	private const MAIN = 'vector';

	public function testASkinCopyLoadsAndPublishesTheIndexInsideItself() {
		$this->assertSame(
			['path' => '/wikven/citizen/pagefind/', 'directory' => '/w/dist/citizen/pagefind'],
			$this->copyFor('citizen', '/wikven/pagefind/', '/w/dist/pagefind', '/w/dist/citizen')
		);
	}

	public function testABundleAtTheSiteRootIsHandledTheSameWay() {
		$copy = $this->copyFor('minerva', '/pagefind/', '/w/dist/pagefind', '/w/dist/minerva');
		$this->assertSame('/minerva/pagefind/', $copy['path']);
	}

	public function testATrailingSlashIsNotRequiredAndTheBundlePathAlwaysGetsOne() {
		$copy = $this->copyFor('citizen', '/wikven/pagefind', '/w/dist/pagefind/', '/w/dist/citizen/');
		$this->assertSame('/wikven/citizen/pagefind/', $copy['path']);
		$this->assertSame('/w/dist/citizen/pagefind', $copy['directory']);
	}

	public function testTheDirectoryTheIndexWasBuiltIntoNamesTheCopy() {
		$copy = $this->copyFor('citizen', '/w/static/index/', '/w/dist/index', '/w/dist/citizen');
		$this->assertSame('/w/static/citizen/index/', $copy['path']);
		$this->assertSame('/w/dist/citizen/index', $copy['directory']);
	}

	/** Its output is the export root, so results already resolve against the index's own directory. */
	public function testTheMainSkinGetsNoCopy() {
		$this->assertNull($this->copyFor(self::MAIN, '/wikven/pagefind/', '/w/dist/pagefind', '/w/dist'));
	}

	/** Not a skin pass at all: the orchestrator, which renders nothing. */
	public function testNoSkinGetsNoCopy() {
		$this->assertNull($this->copyFor('', '/wikven/pagefind/', '/w/dist/pagefind', '/w/dist'));
	}

	/** Search is off (no output directory), so there is no index to copy. */
	public function testNoIndexGetsNoCopy() {
		$this->assertNull($this->copyFor('citizen', '/wikven/pagefind/', '', '/w/dist/citizen'));
	}

	/** A bundle on another host says nothing about where this site's root is, and is not ours. */
	public function testABundleOffThisSiteGetsNoCopy() {
		foreach (['//cdn.example.org/pagefind/', 'https://cdn.example.org/pagefind/', 'pagefind/'] as $path) {
			$this->assertNull(
				$this->copyFor('citizen', $path, '/w/dist/pagefind', '/w/dist/citizen'),
				"$path names no root of this site"
			);
		}
	}

	public function testPublishingCopiesTheWholeBundleTree() {
		$root = $this->makeTempDir();
		$this->write("$root/pagefind/pagefind.js", 'bundle');
		$this->write("$root/pagefind/fragment/en_1731fcb.pf_fragment", 'fragment');

		$this->assertNull(Search::publishBundle("$root/pagefind", "$root/citizen/pagefind"));
		$this->assertSame('bundle', file_get_contents("$root/citizen/pagefind/pagefind.js"));
		$this->assertSame(
			'fragment',
			file_get_contents("$root/citizen/pagefind/fragment/en_1731fcb.pf_fragment")
		);
	}

	public function testPublishingOverAnExistingCopyReplacesIt() {
		$root = $this->makeTempDir();
		$this->write("$root/pagefind/pagefind.js", 'new');
		$this->write("$root/citizen/pagefind/pagefind.js", 'stale');

		$this->assertNull(Search::publishBundle("$root/pagefind", "$root/citizen/pagefind"));
		$this->assertSame('new', file_get_contents("$root/citizen/pagefind/pagefind.js"));
	}

	/** The main skin's pass asks to publish the index where it already is. */
	public function testPublishingOntoItselfDoesNothing() {
		$root = $this->makeTempDir();
		$this->write("$root/pagefind/pagefind.js", 'bundle');
		$this->assertNull(Search::publishBundle("$root/pagefind", "$root/pagefind/"));
		$this->assertSame('bundle', file_get_contents("$root/pagefind/pagefind.js"));
	}

	public function testNothingToPublishIsNotAFailure() {
		$root = $this->makeTempDir();
		$this->assertNull(Search::publishBundle('', "$root/citizen/pagefind"));
		$this->assertNull(Search::publishBundle("$root/pagefind", ''));
		// Search was on but the index was never built.
		$this->assertNull(Search::publishBundle("$root/absent", "$root/citizen/pagefind"));
		$this->assertFalse(is_dir("$root/citizen/pagefind"));
	}

	/** A build that cannot publish the index has to say so rather than ship a copy without one. */
	public function testAnUnwritableDestinationIsReported() {
		$root = $this->makeTempDir();
		$this->write("$root/pagefind/pagefind.js", 'bundle');
		$this->write("$root/citizen", 'a file where the directory should go');

		$error = Search::publishBundle("$root/pagefind", "$root/citizen/pagefind");
		$this->assertStringContainsString("could not create directory $root/citizen/pagefind", (string)$error);
	}

	/** @return ?array{path:string,directory:string} */
	private function copyFor(string $skin, string $bundlePath, string $indexSource, string $htmlDir): ?array {
		return Search::bundleCopyFor($skin, self::MAIN, $bundlePath, $indexSource, $htmlDir);
	}

	private function write(string $path, string $contents): void {
		$directory = dirname($path);
		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}
		file_put_contents($path, $contents);
	}

	private function makeTempDir(): string {
		$dir = sys_get_temp_dir() . '/wikven-search-' . uniqid();
		mkdir($dir);
		$this->dirsToClean[] = $dir;
		return $dir;
	}

	/** @var string[] */
	private array $dirsToClean = [];

	protected function tearDown(): void {
		foreach ($this->dirsToClean as $dir) {
			if (!is_dir($dir)) {
				continue;
			}
			$entries = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
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
		parent::tearDown();
	}
}
