<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Git;
use MediaWikiIntegrationTestCase;

/**
 * What git says, and the three ways of getting no answer.
 *
 * @covers \MediaWiki\Extension\Wikven\Git
 */
class GitTest extends MediaWikiIntegrationTestCase {
	public function testWhatGitPrintsComesBack() {
		$output = Git::output(['--version']);
		if ($output === null) {
			// The host has no git, which is one of the answers this gives; the tests below are it.
			$this->markTestSkipped('git is not installed here');
		}

		$this->assertStringStartsWith('git version', $output);
	}

	public function testADirectoryThatIsNotACheckoutIsNoAnswer() {
		// git exits non-zero and says so on the stderr this discards.
		$this->assertNull(Git::output(['-C', $this->getNewTempDirectory(), 'rev-parse', 'HEAD']));
	}

	public function testASubcommandGitDoesNotHaveIsNoAnswer() {
		$this->assertNull(Git::output(['wikven-no-such-subcommand']));
	}
}
