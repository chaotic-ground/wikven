<?php

/**
 * Assert that the source tree's comments stay the size of notes.
 *
 *     composer test:comments
 *     php tests/comments/assert-comments.php --github includes maintenance tests
 *
 * Measured against MediaWiki core and its bundled extensions, wikven's comments were never too
 * many -- 7.3 blocks per 100 lines of code, against 5.2 in core's includes/ -- but they were three
 * to five times too long: a class docblock ran to 473 words where core's ninetieth percentile is
 * 46 (#678, #680).
 *
 * So the budget is per comment rather than per file, with a whole-tree ratio behind it. Budget.php
 * holds both and says why they are where they are.
 *
 * --github additionally emits a ::error:: workflow command per problem.
 */

namespace MediaWiki\Extension\Wikven\Comments;

require_once __DIR__ . '/Budget.php';
require_once __DIR__ . '/Comment.php';
require_once __DIR__ . '/Reader.php';
require_once __DIR__ . '/Report.php';

exit(Report::main($argv));
