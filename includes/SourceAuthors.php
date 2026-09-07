<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;

/**
 * The accounts a build writes pages under: one per author the source history names.
 *
 * The point of reading the history is that the footer shows the name the "View history" commit
 * list shows. Only when no spelling of a name is one MediaWiki will take does this fall back to
 * the account the build writes under.
 */
class SourceAuthors {
	private UserFactory $factory;

	/** The build's own account, used where the history names nobody usable. */
	private User $unattributed;

	/** @var array<string,?User> Accounts by author name; null for a name MediaWiki refused. */
	private array $accounts = [];

	/** @var string[] Author names already reported as unusable, so each is said once. */
	private array $reported = [];

	public function __construct(UserFactory $factory, User $unattributed) {
		$this->factory = $factory;
		$this->unattributed = $unattributed;
	}

	/**
	 * The account to write a page under.
	 *
	 * The first name MediaWiki will take wins, and the list is one person: the name on the commit,
	 * then the others they have committed under.
	 *
	 * @param string[] $names
	 */
	public function accountFor(array $names): User {
		foreach ($names as $name) {
			if (!array_key_exists($name, $this->accounts)) {
				$this->accounts[$name] = $this->create($name);
			}
			$account = $this->accounts[$name];
			if ($account !== null) {
				return $account;
			}
		}

		if ($names !== [] && !in_array($names[0], $this->reported, true)) {
			$this->reported[] = $names[0];
			// error_log rather than a maintenance script's output(): this is shared by two of them,
			// and stderr is where the build's other configuration complaints go.
			error_log(
				"Wikven: no account name MediaWiki will take among '"
				. implode("', '", $names)
				. "'; those pages are left unattributed."
			);
		}
		return $this->unattributed;
	}

	/** Find or create the account for one author name, or null if MediaWiki will not have it. */
	private function create(string $author): ?User {
		// A git author name is free text, so it can be one MediaWiki refuses: too long, or holding
		// a character a title cannot carry, such as the slash in "Lens0021 / Leslie".
		$user = $this->factory->newFromName($author, UserRigorOptions::RIGOR_CREATABLE);
		if (!$user) {
			return null;
		}
		if (!$user->isRegistered()) {
			$status = $user->addToDatabase();
			if (!$status->isOK()) {
				error_log("Wikven: could not create an account for '$author'.");
				return null;
			}
		}
		return $user;
	}
}
