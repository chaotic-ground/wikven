<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Fetching\Git;
use MediaWiki\Extension\Wikven\Source\SourceHistory;
use MediaWikiIntegrationTestCase;

/**
 * How a source directory is resolved to a history: the dumped log actions/bake mounts, and what
 * happens where neither that nor a reachable checkout answers.
 *
 * @covers \MediaWiki\Extension\Wikven\Source\SourceHistory
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

	/**
	 * The other half of forSource(): with no log dumped beside it, a checkout answers for itself.
	 * This is the one place the log arguments and the parser meet what git actually writes.
	 */
	public function testACheckoutAnswersForItself() {
		if (Git::output(['--version']) === null) {
			$this->markTestSkipped('git is not installed here');
		}
		$directory = $this->getNewTempDirectory();
		file_put_contents("$directory/index.wikitext", "Hello\n");
		$this->commit($directory, 'index.wikitext', 'Leslie', 'leslie@example.org', 1_786_288_328);

		$history = SourceHistory::forSource($directory);

		$this->assertSame(1_786_288_328, $history->timestamp('index.wikitext'));
		$this->assertSame('Leslie', $history->authors('index.wikitext')[0]);
	}

	/** Make $directory a checkout holding one commit, written at $at by $author. */
	private function commit(string $directory, string $file, string $author, string $email, int $at): void {
		// git takes the author date from the command line and the committer date, which the log
		// format reads, from the environment alone.
		$stamp = gmdate('Y-m-d\TH:i:sP', $at);
		putenv("GIT_AUTHOR_DATE=$stamp");
		putenv("GIT_COMMITTER_DATE=$stamp");
		try {
			$runs = [
				['init'],
				['add', $file],
				['-c', "user.name=$author", '-c', "user.email=$email", 'commit', '-m', "Write $file"]
			];
			foreach ($runs as $arguments) {
				$this->assertNotNull(
					Git::output(array_merge(['-C', $directory], $arguments)),
					'git ' . implode(' ', $arguments)
				);
			}
		} finally {
			putenv('GIT_AUTHOR_DATE');
			putenv('GIT_COMMITTER_DATE');
		}
	}
}
