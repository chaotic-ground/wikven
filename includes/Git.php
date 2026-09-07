<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Utils\ExecutableFinder;

/**
 * What git said, for a build that carries on without it.
 *
 * Located rather than spawned by name: proc_open() warns of its own accord when the command is
 * missing, and a host with no git is an answer here rather than an error. Loadable by path,
 * because fetchExtensions runs before wikven's autoloader.
 */
class Git {
	/**
	 * Run git and return what it printed, or null.
	 *
	 * Null covers every way of not getting an answer, git's own refusals among them, so stderr is
	 * discarded and the exit status is what reports those.
	 *
	 * @param string[] $arguments git's own, "-C <dir>" included where a directory is wanted.
	 * @return string|null Standard output as it came, trailing newline and all.
	 */
	public static function output(array $arguments): ?string {
		$binary = ExecutableFinder::findInDefaultPaths(['git']) ?: null;
		if ($binary === null) {
			return null;
		}

		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['file', '/dev/null', 'w']
		];
		$pipes = [];
		// An array argv never reaches a shell, so no argument needs quoting.
		$process = proc_open(array_merge([$binary], $arguments), $descriptors, $pipes);
		if ($process === false) {
			return null;
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		return proc_close($process) === 0 && $output !== false ? $output : null;
	}
}
