<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\ImageImport;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\ImageImport
 */
class ImageImportTest extends MediaWikiUnitTestCase {
	/** The file types a wikven build offers the importer, as $wgFileExtensions holds them. */
	private const EXTENSIONS = ['png', 'gif', 'jpg', 'jpeg', 'webp', 'svg'];

	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-image-import-' . getmypid() . '-' . uniqid();
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*/*') ?: [] as $path) {
			unlink($path);
		}
		foreach (glob($this->directory . '/*') ?: [] as $path) {
			if (is_dir($path)) {
				rmdir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($this->directory);
		parent::tearDown();
	}

	/** The defect this class exists for: a truncated image aborts the build instead of red-linking. */
	public function testAnImageTheWikiRejectedFailsTheBuild() {
		touch($this->directory . '/diagram.png');
		$sources = ImageImport::sources($this->directory, self::EXTENSIONS);

		$this->assertTrue(ImageImport::failed(false, $sources));
	}

	/** The trap: core answers a source holding no image with the same false a failed import gets. */
	public function testASourceWithoutImagesStillBuilds() {
		touch($this->directory . '/Main Page.wikitext');
		$sources = ImageImport::sources($this->directory, self::EXTENSIONS);

		$this->assertSame([], $sources);
		$this->assertFalse(ImageImport::failed(false, $sources));
	}

	/** Anything but false is the importer saying every image it was given went in. */
	public function testAnImportThatWentThroughIsNotAFailure() {
		touch($this->directory . '/diagram.png');
		$sources = ImageImport::sources($this->directory, self::EXTENSIONS);

		$this->assertFalse(ImageImport::failed(true, $sources));
		$this->assertFalse(ImageImport::failed(null, $sources));
	}

	public function testItFindsTheImagesTheImporterWould() {
		touch($this->directory . '/Bakery oven.jpg');
		touch($this->directory . '/diagram.PNG');
		touch($this->directory . '/Main Page.wikitext');
		touch($this->directory . '/LICENSE');

		$this->assertSame(
			['Bakery oven.jpg', 'diagram.PNG'],
			$this->basenames(ImageImport::sources($this->directory, self::EXTENSIONS))
		);
	}

	/** The build does not pass --search-recursively, so a subdirectory holds nothing to import. */
	public function testItLooksNoDeeperThanTheImporterDoes() {
		mkdir($this->directory . '/Subpage');
		touch($this->directory . '/Subpage/nested.png');

		$this->assertSame([], ImageImport::sources($this->directory, self::EXTENSIONS));
	}

	/** A source directory that is not there yet offers nothing, rather than warning about it. */
	public function testAMissingDirectoryHoldsNoImages() {
		$this->assertSame([], ImageImport::sources($this->directory . '/gone', self::EXTENSIONS));
	}

	/**
	 * @param string[] $paths
	 * @return string[]
	 */
	private function basenames(array $paths): array {
		$names = array_map('basename', $paths);
		sort($names);
		return $names;
	}
}
