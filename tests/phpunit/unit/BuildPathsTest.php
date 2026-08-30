<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\BuildPaths;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\BuildPaths
 */
class BuildPathsTest extends MediaWikiUnitTestCase {
	private string $workdir;

	protected function setUp(): void {
		parent::setUp();
		$this->workdir = sys_get_temp_dir() . '/wikven-build-paths-' . bin2hex(random_bytes(6));
		mkdir($this->workdir);
	}

	protected function tearDown(): void {
		foreach (glob($this->workdir . '/*') ?: [] as $leftover) {
			unlink($leftover);
		}
		rmdir($this->workdir);
		parent::tearDown();
	}

	public function testEveryPathHangsOffTheOneWorkdir() {
		$paths = BuildPaths::fromWorkdir('/w');
		$this->assertSame('/w/src', $paths['source']);
		$this->assertSame('/w/dist', $paths['dist']);
		$this->assertSame('/w/.cache', $paths['cache']);
	}

	public function testATrailingSlashOnTheWorkdirDoesNotDoubleUp() {
		$this->assertSame('/w/src', BuildPaths::fromWorkdir('/w/')['source']);
	}

	/** The bake action dumps the git log here; the build reads it in place of asking git. */
	public function testTheHistoryLogIsNamedOnceItHasBeenDumped() {
		file_put_contents($this->workdir . '/source-history', "log\n");
		$this->assertSame($this->workdir . '/source-history', BuildPaths::fromWorkdir($this->workdir)['history']);
	}

	/** Empty, not the path, so SourceHistory knows to ask git rather than read nothing. */
	public function testNoHistoryLogLeavesTheHistoryEmpty() {
		$this->assertSame('', BuildPaths::fromWorkdir($this->workdir)['history']);
	}

	/** A directory of that name is not a dumped log. */
	public function testADirectoryNamedLikeTheLogIsNotOne() {
		mkdir($this->workdir . '/source-history');
		$this->assertSame('', BuildPaths::fromWorkdir($this->workdir)['history']);
		rmdir($this->workdir . '/source-history');
	}
}
