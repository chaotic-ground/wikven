<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\SourceAuthors;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SourceAuthors
 * @group Database
 */
class SourceAuthorsTest extends MediaWikiIntegrationTestCase {
	private function authors(): array {
		// Stands in for the account the build itself writes under.
		$build = $this->getTestUser()->getUser();
		return [new SourceAuthors($this->getServiceContainer()->getUserFactory(), $build), $build];
	}

	public function testAnAuthorNameGetsAnAccountOfItsOwn() {
		[$authors] = $this->authors();

		$account = $authors->accountFor('Leslie');

		$this->assertSame('Leslie', $account->getName());
		$this->assertTrue($account->isRegistered(), 'the account is saved, so a revision can belong to it');
	}

	public function testTheSameNameIsAskedOfMediaWikiOnce() {
		[$authors] = $this->authors();

		$this->assertSame($authors->accountFor('Leslie'), $authors->accountFor('Leslie'));
	}

	public function testANameMediaWikiWillNotTakeLeavesThePageUnattributed() {
		[$authors, $build] = $this->authors();

		// A pipe cannot appear in a title, so no account can carry this name.
		$this->assertSame($build, $authors->accountFor('Ada|Lovelace'));
	}

	public function testAPageTheHistorySaysNothingAboutIsUnattributed() {
		[$authors, $build] = $this->authors();

		$this->assertSame($build, $authors->accountFor(null));
		$this->assertSame($build, $authors->accountFor(''));
	}
}
