<?php

namespace MediaWiki\Extension\Wikven\Comments;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Reads the directories it was given, spends the budget over them, and says what went over. */
class Report {
	/** @param string[] $argv */
	public static function main(array $argv): int {
		$github = false;
		$roots = [];
		foreach (array_slice($argv, 1) as $argument) {
			if ($argument === '--github') {
				$github = true;
			} else {
				$roots[] = $argument;
			}
		}
		if ($roots === []) {
			fwrite(STDERR, "usage: assert-comments.php [--github] <directory>...\n");

			return 2;
		}

		$budget = new Budget();
		foreach (self::sources($roots) as $file) {
			$budget->add(Reader::of($file));
		}

		return self::say($budget, $github);
	}

	/**
	 * @param string[] $roots
	 * @return string[]
	 */
	private static function sources(array $roots): array {
		$files = [];
		foreach ($roots as $root) {
			if (is_file($root)) {
				$files[] = $root;
				continue;
			}
			$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
			foreach ($walk as $entry) {
				if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
					$files[] = $entry->getPathname();
				}
			}
		}
		sort($files);

		return $files;
	}

	private static function say(Budget $budget, bool $github): int {
		foreach ($budget->overrun as $comment) {
			$ceiling = Budget::ceiling($comment->kind);
			$problem =
				"this $comment->kind runs $comment->words words of prose, and $ceiling is the "
				. 'ceiling. Say the part a reader cannot work out from the code, and leave the rest '
				. 'to the issue or pull request that decided it.';
			printf("%s:%d  %s\n", $comment->file, $comment->line, $problem);
			if ($github) {
				printf("::error file=%s,line=%d::%s\n", $comment->file, $comment->line, $problem);
			}
		}

		printf(
			"\n%d comments, %d words of prose over %d lines of code: %.2f words a line, ceiling %.2f."
			. " %d over a per-comment ceiling.\n",
			$budget->comments,
			$budget->words,
			$budget->code,
			$budget->ratio(),
			Budget::ratioCeiling(),
			count($budget->overrun)
		);

		if ($budget->ratioOverrun()) {
			$problem = sprintf(
				'the tree carries %.2f words of comment per line of code, and %.2f is the ceiling. '
				. 'MediaWiki core runs 2.1 and its bundled extensions 1.1.',
				$budget->ratio(),
				Budget::ratioCeiling()
			);
			printf("%s\n", $problem);
			if ($github) {
				printf("::error::%s\n", $problem);
			}
		}

		return $budget->overrun === [] && !$budget->ratioOverrun() ? 0 : 1;
	}
}
