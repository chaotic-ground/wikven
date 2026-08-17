<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\SkinOutput;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SkinOutput
 */
class SkinOutputTest extends MediaWikiIntegrationTestCase {
	private const SKINS = ['vector-2022', 'citizen', 'minerva'];

	public function testMainSkinLeavesTheOtherSkinsAlone() {
		$dist = $this->getNewTempDirectory();
		$this->write("$dist/index.html");
		$this->write("$dist/Manual/ko.html");
		$this->write("$dist/history/index.html");
		$this->write("$dist/citizen/index.html");
		$this->write("$dist/citizen/Manual/ko.html");
		$this->write("$dist/minerva/index.html");

		$this->assertSame(
			["$dist/Manual/ko.html", "$dist/index.html"],
			$this->pages($dist, 'vector-2022')
		);
	}

	public function testAnotherSkinWalksItsOwnDirectory() {
		$dist = $this->getNewTempDirectory();
		$this->write("$dist/citizen/index.html");
		$this->write("$dist/citizen/Manual/ko.html");
		$this->write("$dist/citizen/history/index.html");

		$this->assertSame(
			["$dist/citizen/Manual/ko.html", "$dist/citizen/index.html"],
			$this->pages("$dist/citizen", 'citizen')
		);
	}

	public function testOnlyHtmlIsWalked() {
		$dist = $this->getNewTempDirectory();
		$this->write("$dist/index.html");
		$this->write("$dist/img-0123456789ab.png");
		$this->write("$dist/styles/vector.css");

		$this->assertSame(["$dist/index.html"], $this->pages($dist, 'vector-2022'));
	}

	public function testATrailingSlashNamesTheSameDirectories() {
		$this->assertSame(
			SkinOutput::foreignDirectories('/dist', self::SKINS, 'vector-2022', 'vector-2022'),
			SkinOutput::foreignDirectories('/dist/', self::SKINS, 'vector-2022', 'vector-2022')
		);
	}

	public function testTheMainSkinExcludesEveryOtherSkinAndTheHistoryTree() {
		$this->assertSame(
			['/dist/history', '/dist/citizen', '/dist/minerva'],
			SkinOutput::foreignDirectories('/dist', self::SKINS, 'vector-2022', 'vector-2022')
		);
	}

	public function testAnotherSkinExcludesOnlyTheHistoryTree() {
		$this->assertSame(
			['/dist/citizen/history'],
			SkinOutput::foreignDirectories('/dist/citizen', self::SKINS, 'vector-2022', 'citizen')
		);
	}

	/** The walk's paths, sorted: the filesystem's own order is not a promise. */
	private function pages(string $htmlDir, string $skin): array {
		$pages = iterator_to_array(SkinOutput::pages($htmlDir, self::SKINS, 'vector-2022', $skin), false);
		sort($pages);
		return $pages;
	}

	private function write(string $path): void {
		$this->assertTrue(wfMkdirParents(dirname($path)));
		file_put_contents($path, 'x');
	}
}
