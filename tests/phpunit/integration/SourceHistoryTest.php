<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\SourceHistory;
use MediaWikiIntegrationTestCase;

/**
 * How a source directory is resolved to a history: the dumped log actions/bake mounts, and what
 * happens where neither that nor a reachable checkout answers.
 *
 * @covers \MediaWiki\Extension\Wikven\SourceHistory
 */
class SourceHistoryTest extends MediaWikiIntegrationTestCase {
	/** A page name no checkout this ever runs in can hold, so git cannot answer for it either. */
	private const ABSENT = 'wikven-no-such-source-file.wikitext';

	public function testTheDumpedLogIsReadInPlaceOfGit() {
		$directory = $this->getNewTempDirectory();
		$log = "$directory/source-history";
		file_put_contents($log, "\x011786893214\tLeslie\tleslie@example.org\0\nindex.wikitext\0");

		$history = SourceHistory::forSource($directory, $log);

		$this->assertSame(1_786_893_214, $history->timestamp('index.wikitext'));
		$this->assertSame('Leslie', $history->authors('index.wikitext')[0]);
	}

	public function testADirectoryOutsideACheckoutHasNoHistory() {
		// Nothing to read and nothing for git to answer from: every page falls back to the build
		// clock, and none of them is attributed.
		$history = SourceHistory::forSource($this->getNewTempDirectory());

		$this->assertNull($history->timestamp(self::ABSENT));
		$this->assertSame([], $history->authors(self::ABSENT));
	}

	public function testALogFileThatIsNotThereIsNotFatal() {
		$directory = $this->getNewTempDirectory();

		$history = SourceHistory::forSource($directory, "$directory/never-written");

		$this->assertNull($history->timestamp(self::ABSENT));
	}

	public function testNoSourceDirectoryAtAll() {
		// What a wiki running the extension outside a build has.
		$this->assertNull(SourceHistory::forSource('')->timestamp(self::ABSENT));
		$this->assertNull(SourceHistory::forSource('/nonexistent/wikven-source')->timestamp(self::ABSENT));
	}
}
