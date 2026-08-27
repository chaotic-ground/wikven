<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\FetchPin;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\FetchPin
 */
class FetchPinTest extends MediaWikiUnitTestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-fetch-pin-' . getmypid() . '-' . uniqid();
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void {
		$file = $this->directory . '/' . FetchPin::FILE;
		if (is_file($file)) {
			unlink($file);
		}
		if (is_dir($this->directory)) {
			rmdir($this->directory);
		}
		parent::tearDown();
	}

	/** The whole point: a pin that moved is a different line, and the build sees it. */
	public function testAMovedPinIsADifferentSource() {
		$repository = 'https://example.org/thing.git';
		$this->assertSame(
			FetchPin::of(['repository' => $repository, 'reference' => 'v1.0.0']),
			FetchPin::of(['repository' => $repository, 'reference' => 'v1.0.0'])
		);
		$this->assertNotSame(
			FetchPin::of(['repository' => $repository, 'reference' => 'v1.0.0']),
			FetchPin::of(['repository' => $repository, 'reference' => 'v1.0.1'])
		);
		$this->assertNotSame(
			FetchPin::of(['tarball' => 'https://example.org/thing-1.tar.gz']),
			FetchPin::of(['tarball' => 'https://example.org/thing-2.tar.gz'])
		);
	}

	/**
	 * A spec is a mapping, so the order it was written in is not something the build should read
	 * a moved source into; and what is not a source cannot move one either.
	 */
	public function testOnlyTheSourceCounts() {
		$this->assertSame(
			FetchPin::of(['repository' => 'https://example.org/a.git', 'commit' => 'abc123']),
			FetchPin::of(['commit' => 'abc123', 'repository' => 'https://example.org/a.git'])
		);
		$this->assertSame(
			FetchPin::of(['tarball' => 'https://example.org/a.tar.gz']),
			FetchPin::of(['tarball' => 'https://example.org/a.tar.gz', 'note' => 'why we pin it'])
		);
	}

	/** A clone and a clone with its dependencies installed are not the same tree on disk. */
	public function testComposerIsPartOfWhatWasFetched() {
		$this->assertNotSame(
			FetchPin::of(['repository' => 'https://example.org/a.git']),
			FetchPin::of(['repository' => 'https://example.org/a.git', 'composer' => true])
		);
	}

	/** The validators lowercase a hex pin; a spec written by hand may not have. */
	public function testAHexPinIsTheSamePinInEitherCase() {
		$this->assertSame(
			FetchPin::of(['repository' => 'https://example.org/a.git', 'commit' => 'ABC123']),
			FetchPin::of(['repository' => 'https://example.org/a.git', 'commit' => 'abc123'])
		);
	}

	/** A tree nothing wikven fetched put there says nothing, and is left alone on that account. */
	public function testATreeWithNoStampInItAnswersNothing() {
		$this->assertNull(FetchPin::inside($this->directory));
		$this->assertNull(FetchPin::inside($this->directory . '/not-here'));
	}

	/** Nor does one written by a version of this that has since changed shape. */
	public function testAStampThisCannotReadAnswersNothing() {
		file_put_contents($this->directory . '/' . FetchPin::FILE, "an older line\n");

		$this->assertNull(FetchPin::inside($this->directory));
	}

	public function testAStampedTreeAnswersWithWhatFetchedIt() {
		$source = FetchPin::of(['repository' => 'https://example.org/a.git', 'reference' => 'v1.0.0']);
		$commit = str_repeat('a1b2c3d4', 5);

		$this->assertTrue(FetchPin::stamp($this->directory, $source, $commit));
		$this->assertSame(
			['source' => $source, 'commit' => $commit],
			FetchPin::inside($this->directory)
		);
	}

	/** A tarball has no commit to record, and the tree still says where it came from. */
	public function testATreeFetchedWithoutGitRecordsNoCommit() {
		$source = FetchPin::of(['tarball' => 'https://example.org/a.tar.gz']);

		FetchPin::stamp($this->directory, $source);

		$this->assertSame(['source' => $source, 'commit' => null], FetchPin::inside($this->directory));
	}

	/**
	 * An annotated tag is listed twice: as the tag object, and as the commit it wraps. The
	 * commit is the one a checkout lands on, so reading the first line instead would make every
	 * build think the tag had moved.
	 */
	public function testAnAnnotatedTagAnswersWithTheCommitItWraps() {
		$tag = str_repeat('f', 40);
		$commit = str_repeat('4', 40);

		$this->assertSame(
			$commit,
			FetchPin::pointedAt("$tag\trefs/tags/v2\n$commit\trefs/tags/v2^{}\n")
		);
	}

	public function testABranchAnswersWithWhatItPointsAt() {
		$commit = str_repeat('b', 40);

		$this->assertSame($commit, FetchPin::pointedAt("$commit\trefs/heads/main\n"));
	}

	/** A remote that would not answer, or answered with something else, is not a moved pin. */
	public function testAnAnswerWithNoCommitInItIsNoAnswer() {
		$this->assertNull(FetchPin::pointedAt(''));
		$this->assertNull(FetchPin::pointedAt('fatal: repository not found'));
	}
}
