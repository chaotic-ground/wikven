<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SourceHistory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SourceHistory
 */
class SourceHistoryTest extends MediaWikiUnitTestCase {
	/** Build a log the way `git log -z --name-only` writes one: NUL after every record. */
	private static function log(array $commits): string {
		$log = '';
		foreach ($commits as [$timestamp, $author, $files]) {
			$email = $author === '' ? '' : strtolower($author) . '@example.org';
			$log .= "\x01$timestamp\t$author\t$email\0";
			// git ends the header with the newline the format asked for, and -z leaves it at the
			// front of the first file name.
			$log .= $files ? "\n" . implode("\0", $files) . "\0" : '';
		}
		return $log;
	}

	public function testTheNewestCommitTouchingAFileWins() {
		$history = SourceHistory::fromLog(self::log([
			['1786893214', 'Leslie', ['Skins.wikitext', 'Skins/ko.wikitext']],
			['1786892364', 'Someone else', ['Skins.wikitext', 'index.wikitext']]
		]));

		$this->assertSame(1_786_893_214, $history->timestamp('Skins.wikitext'));
		$this->assertSame(['Leslie'], $history->authors('Skins.wikitext'));
		$this->assertSame(1_786_892_364, $history->timestamp('index.wikitext'));
		$this->assertSame(['Someone else'], $history->authors('index.wikitext'));
	}

	public function testAFileTheHistoryDoesNotCoverHasNoDateOrAuthor() {
		$history = SourceHistory::fromLog(self::log([['1786893214', 'Leslie', ['index.wikitext']]]));

		$this->assertNull($history->timestamp('Untracked.wikitext'));
		$this->assertSame([], $history->authors('Untracked.wikitext'));
	}

	public function testAnEmptyLogCoversNothing() {
		$history = SourceHistory::fromLog('');

		$this->assertNull($history->timestamp('index.wikitext'));
	}

	public function testACommitListingNoFilesLeavesTheOnesBeforeItAlone() {
		// git lists a merge without a diff, so its header is followed by the next commit's.
		$history = SourceHistory::fromLog(self::log([
			['1786893214', 'Merger', []],
			['1786892364', 'Leslie', ['index.wikitext']]
		]));

		$this->assertSame(1_786_892_364, $history->timestamp('index.wikitext'));
		$this->assertSame(['Leslie'], $history->authors('index.wikitext'));
	}

	public function testAnAuthorlessCommitStillDatesItsFiles() {
		$history = SourceHistory::fromLog(self::log([['1786893214', '', ['index.wikitext']]]));

		$this->assertSame(1_786_893_214, $history->timestamp('index.wikitext'));
		$this->assertSame([], $history->authors('index.wikitext'));
	}

	public function testAHeaderWithoutAUsableDateIsSkipped() {
		$history = SourceHistory::fromLog(self::log([
			['not a date', 'Leslie', ['index.wikitext']],
			['1786892364', 'Leslie', ['index.wikitext']]
		]));

		$this->assertSame(1_786_892_364, $history->timestamp('index.wikitext'));
	}

	public function testTheOtherNamesAnAuthorUsedFollowTheOneOnTheCommit() {
		// One person, three spellings of their name, newest first, and someone else on the same day.
		$log =
			"\x011786893214\tLens0021 / Leslie\tone@example.org\0\nindex.wikitext\0"
			. "\x011786892364\tlens0021\tone@example.org\0\nSkins.wikitext\0"
			. "\x011786891000\tLens0021\tone@example.org\0\nPages.wikitext\0"
			. "\x011786890000\tLeslie\ttwo@example.org\0\nImages.wikitext\0";

		$history = SourceHistory::fromLog($log);

		// The name on the commit comes first; the rest are the way out when MediaWiki refuses it.
		$this->assertSame(['Lens0021 / Leslie', 'lens0021', 'Lens0021'], $history->authors('index.wikitext'));
		$this->assertSame(['lens0021', 'Lens0021 / Leslie', 'Lens0021'], $history->authors('Skins.wikitext'));
		// Another email is another person, however alike the names look.
		$this->assertSame(['Leslie'], $history->authors('Images.wikitext'));
	}

	public function testPathsArriveVerbatim() {
		// -z means no quoting, so a name with a tab, a quote or a non-ASCII letter arrives whole.
		$names = ['설치.wikitext', 'Say "hi".wikitext', "Tab\there.wikitext"];
		$history = SourceHistory::fromLog(self::log([['1786893214', 'Leslie', $names]]));

		foreach ($names as $name) {
			$this->assertSame(1_786_893_214, $history->timestamp($name), $name);
		}
	}
}
