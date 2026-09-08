<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Source\SourceAuthors;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Source\SourceAuthors
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

		$account = $authors->accountFor(['Leslie']);

		$this->assertSame('Leslie', $account->getName());
		$this->assertTrue($account->isRegistered(), 'the account is saved, so a revision can belong to it');
	}

	public function testTheSameNameIsAskedOfMediaWikiOnce() {
		[$authors] = $this->authors();

		$this->assertSame($authors->accountFor(['Leslie']), $authors->accountFor(['Leslie']));
	}

	public function testANameMediaWikiWillNotTakeFallsBackToTheAuthorsOtherName() {
		[$authors] = $this->authors();

		// A slash cannot appear in a username, which is what the name on this repository's own
		// commits ("Lens0021 / Leslie") runs into; the same author's other spelling is taken.
		$account = $authors->accountFor(['Ada / Lovelace', 'Ada Lovelace']);

		$this->assertSame('Ada Lovelace', $account->getName());
	}

	public function testAnAuthorWithNoUsableNameAtAllLeavesThePageUnattributed() {
		[$authors, $build] = $this->authors();

		// A pipe cannot appear in a title, so no account can carry either of these.
		$this->assertSame($build, $authors->accountFor(['Ada|Lovelace', 'Ada|L']));
	}

	public function testAPageTheHistorySaysNothingAboutIsUnattributed() {
		[$authors, $build] = $this->authors();

		$this->assertSame($build, $authors->accountFor([]));
	}

	/**
	 * A name MediaWiki will take can still fail to save, and a build that wrote revisions against
	 * the account it did not get would attribute them to a user id that is not there.
	 */
	public function testAnAccountThatCannotBeSavedLeavesThePageUnattributed() {
		$build = $this->getTestUser()->getUser();
		$refused = $this->createMock(User::class);
		$refused->method('isRegistered')->willReturn(false);
		$refused->method('addToDatabase')->willReturn(Status::newFatal('userexists'));
		$factory = $this->createMock(UserFactory::class);
		$factory->method('newFromName')->willReturn($refused);

		$authors = new SourceAuthors($factory, $build);

		$this->assertSame($build, $authors->accountFor(['Leslie']));
	}
}
