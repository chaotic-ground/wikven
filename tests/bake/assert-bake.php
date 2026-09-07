<?php

/**
 * Assert that a directory holds a complete, self-contained wikven site.
 *
 *     composer test:bake                                          # ./dist, this repository's expectations
 *     php tests/bake/assert-bake.php --expect tests/bake/docs.php some/other/dist
 *
 * The checks themselves live in Checks.php and know nothing about where they are run. This file
 * reads the site's expectations, decides which checks have the input they asked for, runs them
 * all, and exits 1 if any found a problem.
 *
 * Every check runs even after one fails, which the shell step this replaces could not do: it ran
 * under `set -e`, so the first failure hid the rest.
 *
 * --github additionally emits a ::error:: workflow command per problem.
 */

namespace MediaWiki\Extension\Wikven\Bake;

require_once __DIR__ . '/Check.php';
require_once __DIR__ . '/Checks.php';
require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Site.php';

exit(Runner::main($argv));
