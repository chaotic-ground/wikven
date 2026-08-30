<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration\Webfonts;

use MediaWiki\Extension\Wikven\Webfonts\FontCopier;
use MediaWikiIntegrationTestCase;

/**
 * FontCopier makes the output directories with wfMkdirParents, so this is an integration test.
 *
 * @covers \MediaWiki\Extension\Wikven\Webfonts\FontCopier
 */
class FontCopierTest extends MediaWikiIntegrationTestCase {
	/** The two woff2 paths a repository entry with a bold variant asks for. */
	private const FILES = ['Alef/Alef-Bold.woff2', 'Alef/Alef-Regular.woff2'];

	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-font-copier-' . getmypid() . '-' . uniqid();
		mkdir("$this->directory/uls/Alef", 0777, true);
	}

	protected function tearDown(): void {
		$this->remove($this->directory);
		parent::tearDown();
	}

	private function source(): string {
		return "$this->directory/uls";
	}

	private function destination(): string {
		return "$this->directory/dist/fonts/uls";
	}

	/** Put a font in the repository, with bytes of its own so a copy can be told from an empty file. */
	private function repositoryHolds(string $relative): void {
		file_put_contents("{$this->source()}/$relative", "woff2 of $relative");
	}

	private function remove(string $path): void {
		if (is_dir($path)) {
			foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
				$this->remove("$path/$entry");
			}
			rmdir($path);
			return;
		}
		if (is_file($path)) {
			unlink($path);
		}
	}

	/** Nothing missing: every file arrives, under a tree the copy makes for itself. */
	public function testAFontRepositoryWithEveryFileDeliversThemAll() {
		$this->repositoryHolds('Alef/Alef-Bold.woff2');
		$this->repositoryHolds('Alef/Alef-Regular.woff2');

		$missing = FontCopier::copy($this->source(), $this->destination(), self::FILES);

		$this->assertSame([], $missing);
		foreach (self::FILES as $relative) {
			$this->assertFileExists("{$this->destination()}/$relative");
			$this->assertSame("woff2 of $relative", file_get_contents("{$this->destination()}/$relative"));
		}
	}

	/**
	 * The regression this class exists for: a bake that delivers some of the fonts its stylesheet
	 * names is not a bake that delivered them. Counting the copies made hid this, since one copy
	 * out of two counted as success and the build went on to write @font-face rules pointing at a
	 * file no reader can fetch.
	 */
	public function testAFontTheRepositoryDoesNotHaveIsReportedEvenWhenOthersCopy() {
		$this->repositoryHolds('Alef/Alef-Regular.woff2');

		$missing = FontCopier::copy($this->source(), $this->destination(), self::FILES);

		$this->assertSame(['Alef/Alef-Bold.woff2'], $missing);
		// The one that was there still arrived; the report is about what did not.
		$this->assertFileExists("{$this->destination()}/Alef/Alef-Regular.woff2");
	}

	/** A repository with none of them names all of them, rather than saying only that it failed. */
	public function testARepositoryWithNoneOfTheFilesReportsEveryOne() {
		$missing = FontCopier::copy($this->source(), $this->destination(), self::FILES);

		$this->assertSame(self::FILES, $missing);
	}

	/** A stylesheet naming no files asks for nothing, and nothing is what is missing. */
	public function testAskingForNoFilesIsNotAFailure() {
		$this->assertSame([], FontCopier::copy($this->source(), $this->destination(), []));
	}
}
