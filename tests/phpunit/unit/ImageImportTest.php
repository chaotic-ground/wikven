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
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($entries as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
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

	/**
	 * The failure this exists to catch. Pages are read from subdirectories -- "Guide/Setup.wikitext"
	 * is the page "Guide/Setup" -- so an image beside one used to be the half of that directory the
	 * build could not see: the page imported, the image did not, and the reader got a red link with
	 * nothing in the log about it.
	 */
	public function testItReadsTheSubdirectoriesPagesAreReadFrom() {
		mkdir($this->directory . '/Guide/Deep', 0777, true);
		touch($this->directory . '/Guide/diagram.png');
		touch($this->directory . '/Guide/Deep/nested.svg');
		touch($this->directory . '/Guide/Setup.wikitext');
		touch($this->directory . '/top.png');

		$this->assertSame(
			['diagram.png', 'nested.svg', 'top.png'],
			$this->basenames(ImageImport::sources($this->directory, self::EXTENSIONS))
		);
	}

	/**
	 * What reading subdirectories makes reachable: core names a File: page after the file alone
	 * (wfBaseName), so two directories holding one name are one page, and --skip-dupes would take
	 * the first and drop the second with a line among thousands.
	 */
	public function testTwoDirectoriesCannotShareAnImageName() {
		mkdir($this->directory . '/Guide');
		mkdir($this->directory . '/Setup');
		touch($this->directory . '/Guide/diagram.png');
		touch($this->directory . '/Setup/diagram.png');

		$collisions = ImageImport::collisions(ImageImport::sources($this->directory, self::EXTENSIONS));

		$this->assertSame(['diagram.png'], array_keys($collisions));
		$this->assertCount(2, $collisions['diagram.png']);
	}

	/** Every name its own: nothing to report. */
	public function testNamesThatDifferAreNotACollision() {
		mkdir($this->directory . '/Guide');
		touch($this->directory . '/Guide/diagram.png');
		touch($this->directory . '/overview.png');

		$this->assertSame([], ImageImport::collisions(ImageImport::sources($this->directory, self::EXTENSIONS)));
	}

	/**
	 * default.yml sets CapitalLinks to false, so these are two titles rather than one and calling
	 * them a collision would fail a build that is fine.
	 */
	public function testNamesDifferingOnlyInCaseAreTwoPagesHere() {
		mkdir($this->directory . '/Guide');
		touch($this->directory . '/Guide/diagram.png');
		touch($this->directory . '/Diagram.png');

		$this->assertSame([], ImageImport::collisions(ImageImport::sources($this->directory, self::EXTENSIONS)));
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
