<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;

/**
 * The accounts a build writes pages under: one per author name the source history names.
 *
 * A revision has to belong to someone, and the point of reading the history (see SourceHistory) is
 * that the name the footer shows is the name the "View history" link's commit list shows. These
 * accounts are as throwaway as the wiki holding them: the export carries no user pages and no
 * contributions, so nothing is claimed of a name beyond having written the page.
 *
 * A name MediaWiki will not take, and a page the history says nothing about, both fall back to the
 * account the build itself writes under, which build.php's hideBuildAuthors() then hides rather
 * than offer to the reader as an author.
 */
class SourceAuthors {
	private UserFactory $factory;

	/** The build's own account, used where the history names nobody usable. */
	private User $unattributed;

	/** @var array<string,?User> Accounts by author name; null for a name MediaWiki refused. */
	private array $accounts = [];

	public function __construct(UserFactory $factory, User $unattributed) {
		$this->factory = $factory;
		$this->unattributed = $unattributed;
	}

	/** The account to write a page under, given the author name the history has for it (or none). */
	public function accountFor(?string $author): User {
		if ($author === null || $author === '') {
			return $this->unattributed;
		}
		if (!array_key_exists($author, $this->accounts)) {
			$this->accounts[$author] = $this->create($author);
		}
		return $this->accounts[$author] ?? $this->unattributed;
	}

	/** Find or create the account for one author name, or null if MediaWiki will not have it. */
	private function create(string $author): ?User {
		// A git author name is free text, so it can be one MediaWiki refuses: too long, or holding
		// a character a title cannot carry.
		$user = $this->factory->newFromName($author, UserRigorOptions::RIGOR_CREATABLE);
		if (!$user) {
			// error_log rather than a maintenance script's output(): this is shared by two of them,
			// and stderr is where the build's other configuration complaints go.
			error_log("Wikven: '$author' is not a usable account name; that page is left unattributed.");
			return null;
		}
		if (!$user->isRegistered()) {
			$status = $user->addToDatabase();
			if (!$status->isOK()) {
				error_log("Wikven: could not create an account for '$author'; that page is left unattributed.");
				return null;
			}
		}
		return $user;
	}
}
