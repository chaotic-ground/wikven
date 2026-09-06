<?php

/**
 * Assert that a directory holds a complete, self-contained wikven site.
 *
 *     composer test:bake                                          # ./dist, this repository's expectations
 *     php tests/bake/assert-bake.php --expect tests/bake/docs.php some/other/dist
 *
 * The checks themselves live in Checks.php and know nothing about where they are run. This file
 * reads the site's expectations, decides which checks have the input they asked for, runs them all,
 * and prints one line per check plus whatever each had to say. It exits 1 if any of them found a
 * problem.
 *
 * Every check runs even after one fails, which the shell step this replaces could not do: it ran
 * under `set -e`, so the first failure hid the rest and a broken bake took as many pushes to
 * understand as it had problems.
 *
 * --github additionally emits a ::error:: workflow command per problem, so the annotations that
 * used to be written into the assertions are now the runner's business rather than theirs.
 *
 * Plain PHP, and no MediaWiki: a finished bake is a directory of files, so reading one needs
 * neither a wiki nor a second language in the tree to run it.
 */

namespace MediaWiki\Extension\Wikven\Bake;

require_once __DIR__ . '/Check.php';
require_once __DIR__ . '/Checks.php';
require_once __DIR__ . '/Site.php';

/** What each optional input is called on the command line, for the line a skipped check prints. */
const FLAGS = ['source' => '--source', 'logs' => '--log', 'other' => '--compare-with'];

const USAGE = <<<'TEXT'
usage: php tests/bake/assert-bake.php --expect FILE [options] DIST

  DIST                  the baked site to read
  --expect FILE         PHP file returning what is this site's rather than wikven's
  --source DIR          the source tree the site was baked from; three checks derive their target from it
  --log FILE            a log the bake wrote; repeatable
  --compare-with DIR    a second bake of the same source, for the checks that compare the two
  --github              also emit ::error:: workflow commands, for a GitHub Actions runner
TEXT;

/**
 * @param string[] $argv
 * @return array<string,mixed>|null The options given, or null if the command line does not parse.
 */
function parseArguments(array $argv): ?array {
	$options = ['dist' => null, 'expect' => null, 'source' => null, 'logs' => [], 'other' => null, 'github' => false];
	$takes = ['--expect' => 'expect', '--source' => 'source', '--compare-with' => 'other'];
	for ($i = 1; $i < count($argv); $i++) {
		$argument = $argv[$i];
		if ($argument === '--github') {
			$options['github'] = true;
		} elseif ($argument === '--log') {
			if (!isset($argv[++$i])) {
				return null;
			}
			$options['logs'][] = $argv[$i];
		} elseif (isset($takes[$argument])) {
			if (!isset($argv[++$i])) {
				return null;
			}
			$options[$takes[$argument]] = $argv[$i];
		} elseif (str_starts_with($argument, '-') || $options['dist'] !== null) {
			return null;
		} else {
			$options['dist'] = $argument;
		}
	}
	return $options['dist'] === null || $options['expect'] === null ? null : $options;
}

/** Which of a check's declared inputs was not supplied, if any. */
function missingInput(Site $site, Check $check): ?string {
	foreach ($check->needs as $need) {
		if (!$site->$need) {
			return $need;
		}
	}
	return null;
}

/**
 * @param string[] $argv
 * @return int The exit status: 0 all checks passed, 1 one found a problem, 2 nothing was read.
 */
function main(array $argv): int {
	$options = parseArguments($argv);
	if ($options === null) {
		fwrite(STDERR, USAGE . "\n");
		return 2;
	}
	if (!is_dir($options['dist'])) {
		fwrite(STDERR, "{$options['dist']} is not a directory, so there is no bake to read\n");
		return 2;
	}
	if (!is_file($options['expect'])) {
		fwrite(STDERR, "{$options['expect']} is not a file, so there is nothing to read this bake against\n");
		return 2;
	}
	$expect = require $options['expect'];
	if (!is_array($expect)) {
		fwrite(STDERR, "{$options['expect']} did not return an array of expectations\n");
		return 2;
	}

	$site = new Site($options['dist'], $expect, $options['source'], $options['logs'], $options['other']);

	$checks = Checks::all();
	$failed = 0;
	$skipped = 0;
	$problems = [];
	foreach ($checks as $check) {
		$site->notes = [];
		$absent = missingInput($site, $check);
		if ($absent !== null) {
			$skipped++;
			echo "skip  {$check->summary}\n";
			echo '        no ' . FLAGS[$absent] . " given, so this check has nothing to read\n";
			continue;
		}
		try {
			$found = ( $check->run )($site);
		} catch (\Throwable $error) {
			// A check that raises is a check that failed, and the rest still run.
			$found = ['the check itself raised:', ...explode("\n", (string)$error)];
		}
		if ($found) {
			$failed++;
			echo "FAIL  {$check->summary}\n";
			foreach ($found as $line) {
				$problems[] = [$check->name, $line];
			}
		} else {
			echo "ok    {$check->summary}\n";
			$found = $site->notes;
		}
		foreach ($found as $line) {
			echo "        $line\n";
		}
	}

	$total = count($checks);
	$passed = $total - $failed - $skipped;
	echo "\n$passed of $total checks passed, $failed failed, $skipped skipped\n";
	if ($options['github']) {
		foreach ($problems as [$name, $line]) {
			echo "::error::$name: $line\n";
		}
	}
	return $failed ? 1 : 0;
}

exit(main($argv));
