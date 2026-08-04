<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\RetryingForeignRepo;
use MediaWiki\FileRepo\ForeignAPIRepo;

class Retrier implements \MediaWiki\Hook\SetupAfterCacheHook {
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
			if (is_array($repo) && ( $repo['class'] ?? null ) === ForeignAPIRepo::class) {
				$repo['class'] = RetryingForeignRepo::class;
			}
		}
		unset($repo);
		return $repos;
	}
}
