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

	/** Somewhere a link out of the source tree can point at, so no test reads the real machine. */
	private string $outside;

	protected function setUp(): void {
		parent::setUp();
		$stem = sys_get_temp_dir() . '/wikven-image-import-' . getmypid() . '-' . uniqid();
		$this->directory = $stem . '/src';
		$this->outside = $stem . '/elsewhere';
		mkdir($this->directory, 0777, true);
		mkdir($this->outside, 0777, true);
	}

	protected function tearDown(): void {
		self::remove(dirname($this->directory));
		parent::tearDown();
	}

	/**
	 * Delete a temporary tree without walking through a link out of it.
	 *
	 * A link to a directory answers is_dir(), so descending on that alone would empty whatever the
	 * test pointed it at; it is unlinked as the name it is.
	 */
	private static function remove(string $directory): void {
		foreach (scandir($directory) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $directory . '/' . $entry;
			is_dir($path) && !is_link($path) ? self::remove($path) : unlink($path);
		}
		rmdir($directory);
	}

	/** A file outside the source tree, returned by the path a link in the tree can name. */
	private function outsideFile(string $name): string {
		touch($this->outside . '/' . $name);
		return $this->outside . '/' . $name;
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

	/**
	 * is_file() follows a link, so this one would have had the build upload whatever it points at
	 * -- a file outside the tree, on the machine doing the building -- and publish it with the site.
	 */
	public function testAnImageThatIsALinkIsFoundAndRefused() {
		mkdir($this->directory . '/Guide');
		touch($this->directory . '/real.png');
		symlink($this->outsideFile('secret.png'), $this->directory . '/Guide/picture.png');

		$sources = ImageImport::sources($this->directory, self::EXTENSIONS);

		$this->assertSame(['picture.png', 'real.png'], $this->basenames($sources));
		$this->assertSame(
			[$this->directory . '/Guide/picture.png'],
			ImageImport::outside($this->directory, $sources)
		);
	}

	/** A tree of real files has none, so nothing is refused. */
	public function testFilesThatAreFilesAreNotOutside() {
		mkdir($this->directory . '/Guide');
		touch($this->directory . '/real.png');
		touch($this->directory . '/Guide/nested.png');

		$this->assertSame(
			[],
			ImageImport::outside($this->directory, ImageImport::sources($this->directory, self::EXTENSIONS))
		);
	}

	/**
	 * The defect a walk that stopped at linked directories had: core's findFiles tests is_dir, which
	 * a link to a directory satisfies, so core imported every file under one while this list saw
	 * none of them -- and the refusal, the collision check and the failure count all looked past a
	 * whole directory of files that were about to be published.
	 */
	public function testFilesUnderALinkedDirectoryAreFoundAndRefused() {
		touch($this->directory . '/inside.png');
		symlink(dirname($this->outsideFile('secret.png')), $this->directory . '/pics');

		$sources = ImageImport::sources($this->directory, self::EXTENSIONS);

		$this->assertSame(['inside.png', 'secret.png'], $this->basenames($sources));
		$this->assertSame(
			[$this->directory . '/pics/secret.png'],
			ImageImport::outside($this->directory, $sources)
		);
	}

	/** A link with nothing on the other side cannot be shown to be in the tree, so it is refused. */
	public function testALinkToNothingIsRefused() {
		symlink($this->outside . '/gone.png', $this->directory . '/broken.png');

		$this->assertSame(
			[$this->directory . '/broken.png'],
			ImageImport::outside($this->directory, [$this->directory . '/broken.png'])
		);
	}

	/**
	 * A title collapses runs of space and underscore to one underscore, so these three are one
	 * File: page; comparing the names as written would have called them three and imported one.
	 */
	public function testNamesThatDifferOnlyInWhitespaceAreOnePage() {
		mkdir($this->directory . '/Guide');
		mkdir($this->directory . '/Setup');
		touch($this->directory . '/Bakery oven.png');
		touch($this->directory . '/Guide/Bakery_oven.png');
		touch($this->directory . '/Setup/Bakery  oven.png');

		$collisions = ImageImport::collisions(ImageImport::sources($this->directory, self::EXTENSIONS));

		$this->assertSame(['Bakery_oven.png'], array_keys($collisions));
		$this->assertCount(3, $collisions['Bakery_oven.png']);
	}

	/** A non-breaking space is one of the characters core collapses, and it is not a plain space. */
	public function testANonBreakingSpaceCollapsesLikeASpace() {
		mkdir($this->directory . '/Guide');
		touch($this->directory . "/Bakery\u{00A0}oven.png");
		touch($this->directory . '/Guide/Bakery oven.png');

		$this->assertSame(
			['Bakery_oven.png'],
			array_keys(ImageImport::collisions(ImageImport::sources($this->directory, self::EXTENSIONS)))
		);
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
