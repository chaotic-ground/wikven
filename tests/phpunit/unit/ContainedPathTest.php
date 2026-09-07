<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\ContainedPath;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\ContainedPath
 */
class ContainedPathTest extends MediaWikiUnitTestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$base = sys_get_temp_dir() . '/wikven-contained-' . uniqid();
		$this->root = "$base/uploads";
		mkdir("$this->root/sub", 0777, true);
		mkdir("$base/outside", 0777, true);
		touch("$this->root/Logo.png");
		touch("$this->root/sub/Deep.png");
		file_put_contents("$base/outside/secret.txt", 'x');
		symlink("$base/outside", "$this->root/link");
	}

	protected function tearDown(): void {
		$base = dirname($this->root);
		if (is_dir($base)) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($files as $file) {
				$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($base);
		}
		parent::tearDown();
	}

	public function testAPathInsideTheDirectoryIsJoinedOntoIt() {
		$this->assertSame("$this->root/Logo.png", ContainedPath::under($this->root, '/Logo.png'));
		$this->assertSame("$this->root/sub/Deep.png", ContainedPath::under($this->root, '/sub/Deep.png'));
	}

	/**
	 * A path that names nothing still comes back joined: whether the file is there is the caller's
	 * question, and storeImages reports it as missing rather than as refused.
	 */
	public function testAPathThatNamesNoFileIsStillThisDirectoryToAnswerFor() {
		$this->assertSame("$this->root/nosuch.png", ContainedPath::under($this->root, '/nosuch.png'));
	}

	/**
	 * The failure this exists to catch. storeImages matches $wgUploadPath references in rendered
	 * HTML, and "/images/../../../etc/passwd" needs no markup to get there. Left unbounded, the
	 * file is published with the site.
	 */
	public function testAPathThatClimbsOutOfTheDirectoryIsRefused() {
		$this->assertNull(ContainedPath::under($this->root, '/../outside/secret.txt'));
		$this->assertNull(ContainedPath::under($this->root, '/../../../../../../etc/passwd'));
	}

	/** Refused wherever the climb appears, not only at the front. */
	public function testAClimbInTheMiddleIsRefusedEvenWhereItLandsBackInside() {
		$this->assertNull(ContainedPath::under($this->root, '/sub/../Logo.png'));
	}

	/**
	 * A link is followed rather than refused, deliberately. Refusing one here would silently drop
	 * the assets of a MediaWiki whose skins/ is symlinked, which is how people develop against one.
	 */
	public function testALinkIsThisDirectoryToAnswerFor() {
		$this->assertSame("$this->root/link/secret.txt", ContainedPath::under($this->root, '/link/secret.txt'));
	}

	/**
	 * Matched on segments rather than as a substring: this names a directory whose name happens to
	 * contain the characters, which climbs nowhere and is nothing to refuse.
	 */
	public function testAnEncodedClimbIsANameRatherThanAClimb() {
		$this->assertSame(
			"$this->root/..%2Foutside/x.png",
			ContainedPath::under($this->root, '/..%2Foutside/x.png')
		);
	}

	public function testAPathThatIsNotOneIsRefused() {
		$this->assertNull(ContainedPath::under($this->root, 'relative.png'), 'no leading slash');
		$this->assertNull(ContainedPath::under($this->root, ''), 'empty path');
		$this->assertNull(ContainedPath::under($this->root, '/'), 'the directory itself is not a file in it');
		$this->assertNull(ContainedPath::under('', '/Logo.png'), 'no root to be under');
	}

	/** A trailing slash on the root is spelled away rather than doubling the separator. */
	public function testTheRootMayBeSpelledWithATrailingSlash() {
		$this->assertSame("$this->root/Logo.png", ContainedPath::under("$this->root/", '/Logo.png'));
	}
}
