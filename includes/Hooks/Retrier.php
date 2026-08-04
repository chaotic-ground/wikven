<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\RetryingForeignRepo;
use MediaWiki\FileRepo\ForeignAPIRepo;

class Retrier implements \MediaWiki\Hook\SetupAfterCacheHook {
	/**
	 * The classes a retrying repository stands in for. Core always registers the qualified name,
	 * but it keeps a class_alias for the unqualified one, so a hand-written repository may still
	 * name that.
	 */
	private const PLAIN_FOREIGN_API_REPOS = [ForeignAPIRepo::class, 'ForeignAPIRepo'];

	/**
	 * @inheritDoc
	 *
	 * Let a remote file repository retry a request instead of treating the first failure as final;
	 * see RetryingForeignRepo for why one failed request is otherwise fatal. Core fills in the rest
	 * of each repository's settings (its directory and backend, and the InstantCommons entry
	 * itself) in SetupDynamicConfig.php, which runs after LocalSettings.php, so the class is
	 * swapped here rather than the repository being declared with it in the first place.
	 */
	public function onSetupAfterCache(): void {
		$GLOBALS['wgForeignFileRepos'] = self::retrying($GLOBALS['wgForeignFileRepos']);
	}

	/**
	 * The same repositories, with every plain ForeignAPIRepo among them made a retrying one.
	 *
	 * A repository already configured with its own class is left alone: it may well be a subclass
	 * that overrides the very methods RetryingForeignRepo does.
	 *
	 * @param array[] $repos $wgForeignFileRepos, as core leaves it after SetupDynamicConfig.php.
	 * @return array[]
	 */
	public static function retrying(array $repos): array {
		foreach ($repos as &$repo) {
			if (!is_array($repo)) {
				continue;
			}
			$class = ltrim((string)( $repo['class'] ?? '' ), '\\');
			if (in_array($class, self::PLAIN_FOREIGN_API_REPOS, true)) {
				$repo['class'] = RetryingForeignRepo::class;
			}
		}
		unset($repo);
		return $repos;
	}
}
