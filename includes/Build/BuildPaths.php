<?php

namespace MediaWiki\Extension\Wikven\Build;

/**
 * Where a build reads and writes, worked out from the one directory it was pointed at.
 *
 * A bake is handed a single workdir and everything else hangs off it. Stated here once because
 * WikvenSettings.php needs it twice, before a site's configuration is applied and again after.
 */
class BuildPaths {
	/**
	 * @param string $workdir WIKVEN_WORKDIR, or the default the settings file falls back to.
	 * @return array{source: string, dist: string, cache: string, history: string} Absolute paths;
	 *   history is the empty string when no log was dumped, which is how the build knows to ask git
	 *   itself instead.
	 */
	public static function fromWorkdir(string $workdir): array {
		$workdir = rtrim($workdir, '/');
		$history = "$workdir/source-history";
		return [
			'source' => "$workdir/src",
			'dist' => "$workdir/dist",
			'cache' => "$workdir/.cache",
			'history' => is_file($history) ? $history : ''
		];
	}
}
