<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;

/**
 * The accounts a build writes pages under: one per author the source history names.
 *
 * A revision has to belong to someone, and the point of reading the history (see SourceHistory) is
 * that the name the footer shows is the name the "View history" link's commit list shows. These
 * accounts are as throwaway as the wiki holding them: the export carries no user pages and no
 * contributions, so nothing is claimed of a name beyond having written the page.
 *
 * A name MediaWiki will not take is retried as the other names that author has committed under.
 * Only when none of them is usable, and for a page the history says nothing about, does this fall
 * back to the account the build itself writes under, which build.php's hideBuildAuthors() then
 * hides rather than offer to the reader as an author.
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
	 * The account to write a page under, given the names the history has for its author.
	 *
	 * The first name MediaWiki will take wins. The list is one person -- the name on the commit,
	 * then the others they have committed under (see SourceHistory::authors()) -- so a name a
	 * username cannot carry costs the attribution only when every spelling of it is refused.
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
