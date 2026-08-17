<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use FilesystemIterator;
use MediaWiki\Extension\Wikven\OutputTree;
use MediaWikiUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @covers \MediaWiki\Extension\Wikven\OutputTree
 */
class OutputTreeTest extends MediaWikiUnitTestCase {
	public function testAMissingDirectoryHasNoPages() {
		$this->assertSame([], OutputTree::pages($this->makeTempDir() . '/absent'));
		$this->assertSame([], OutputTree::pages(''));
	}

	public function testPagesAreTheHtmlFilesAtEveryDepth() {
		$dist = $this->makeExport();
		$this->assertSame(
			[
				"$dist/Deploying/ko.html",
				"$dist/Installation.html",
				"$dist/index.html"
			],
			OutputTree::pages($dist, $this->skinDirectories($dist))
		);
	}

	public function testTheMainSkinsWalkStopsAtTheOtherSkinsDirectories() {
		$dist = $this->makeExport();
		$pages = OutputTree::pages($dist, $this->skinDirectories($dist));
		// dist/ is the main skin's own output directory and the parent of every other skin's.
		$this->assertNotContains("$dist/citizen/Installation.html", $pages);
		$this->assertNotContains("$dist/minerva/Deploying/ko.html", $pages);
	}

	public function testASkinsWalkStopsAtTheHistoryTreeItWrote() {
		$dist = $this->makeExport();
		$this->assertNotContains(
			"$dist/history/Installation.html",
			OutputTree::pages($dist, $this->skinDirectories($dist))
		);
	}

	public function testASkinPassSeesItsOwnPagesAndOnlyThose() {
		$dist = $this->makeExport();
		$this->assertSame(
			["$dist/citizen/Installation.html"],
			OutputTree::pages("$dist/citizen", $this->skinDirectories($dist))
		);
	}

	public function testAPageDirectoryNamedAfterASkinIsStillWalked() {
		$dist = $this->makeExport();
		// "citizen" only names another skin's output directly under dist/; a page of that name
		// inside a skin's own tree is a page like any other.
		$this->write("$dist/minerva/citizen/ko.html");
		$this->assertContains(
			"$dist/minerva/citizen/ko.html",
			OutputTree::pages("$dist/minerva", $this->skinDirectories($dist))
		);
	}

	public function testWithNoSkinsNamedOnlyTheNonPageTreesArePruned() {
		$dist = $this->makeExport();
		$pages = OutputTree::pages($dist);
		// Nothing said where the other skins are, so their pages are walked; history/ is known.
		$this->assertContains("$dist/citizen/Installation.html", $pages);
		$this->assertNotContains("$dist/history/Installation.html", $pages);
	}

	public function testOnlyTheSkinDirectoriesUnderThisOneArePruned() {
		$this->assertSame(
			['/w/dist/citizen', '/w/dist/history'],
			OutputTree::prunedDirectories('/w/dist', [
				'main' => '/w/dist',
				'citizen' => '/w/dist/citizen'
			])
		);
		// From a skin subdirectory the main skin's tree is above, so the walk never reaches it.
		$this->assertSame(
			['/w/dist/citizen/history'],
			OutputTree::prunedDirectories('/w/dist/citizen', [
				'main' => '/w/dist',
				'citizen' => '/w/dist/citizen'
			])
		);
	}

	public function testATrailingSlashNamesTheSameDirectory() {
		$this->assertSame(
			['/w/dist/citizen', '/w/dist/history'],
			OutputTree::prunedDirectories('/w/dist/', ['/w/dist/', '/w/dist/citizen/'])
		);
	}

	/** A three-skin export: dist/ is the main skin's, with the other two and history/ inside it. */
	private function makeExport(): string {
		$dist = $this->makeTempDir();
		foreach ([
			'index.html',
			'Installation.html',
			'Deploying/ko.html',
			'citizen/Installation.html',
			'minerva/Deploying/ko.html',
			'history/Installation.html',
			'styles/main.css'
		] as $path) {
			$this->write("$dist/$path");
		}
		return $dist;
	}

	/** @return array<string,string> */
	private function skinDirectories(string $dist): array {
		return ['vector' => $dist, 'citizen' => "$dist/citizen", 'minerva' => "$dist/minerva"];
	}

	private function write(string $path): void {
		$directory = dirname($path);
		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}
		file_put_contents($path, '<html></html>');
	}

	private function makeTempDir(): string {
		$dir = sys_get_temp_dir() . '/wikven-output-tree-' . uniqid();
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
