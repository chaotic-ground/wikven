<?php

namespace MediaWiki\Extension\Wikven\Build;

/**
 * What a skin pass says when it has finished, and how the build reads that back.
 *
 * An uncaught Error reaches MediaWiki's handler, whose guard is a shutdown function calling
 * exit(255), and the standalone binary drops a code set from one. So a pass that died halfway
 * returned 0, over an export of 15 pages out of 222.
 */
class SkinPass {
	private const WROTE = 'Wikven: this pass wrote';

	/** A pass's last line: it finished, and this is how much of the site it produced. */
	public static function wrote(int $pages): string {
		return self::WROTE . " $pages page(s)";
	}

	/** What a pass said it wrote, or null where it never reached the end to say. */
	public static function pagesWritten(string $output): ?int {
		$pattern = '/^' . preg_quote(self::WROTE, '/') . ' (\d+) page\(s\)$/m';
		return preg_match($pattern, $output, $matched) === 1 ? (int)$matched[1] : null;
	}

	/**
	 * What is wrong with a set of finished passes.
	 *
	 * Three questions, and the second is why this class exists: did the pass return success, did it
	 * say it finished, and do the passes agree on how much there is.
	 *
	 * @param array<string,array{exit:int,output:string}> $passes Skin name to what it returned and said.
	 * @return string[] Empty where every pass finished and they agree.
	 */
	public static function failures(array $passes): array {
		$failed = [];
		$written = [];
		foreach ($passes as $skin => $pass) {
			$wrote = self::pagesWritten($pass['output']);
			if ($pass['exit'] !== 0) {
				$failed[] = "$skin (exit {$pass['exit']})";
			} elseif ($wrote === null) {
				// It returned success and did not finish; the class comment says how both.
				$failed[] = "$skin (stopped before the end of its work)";
			} else {
				$written[$skin] = $wrote;
			}
		}
		if ($failed) {
			return ['Wikven: build failed for skin ' . implode(', ', $failed)];
		}
		if (count(array_unique($written)) < 2) {
			return [];
		}
		$parts = [];
		foreach ($written as $skin => $count) {
			$parts[] = "$skin wrote $count";
		}
		return ['Wikven: the skin passes disagree on how much of the site there is: ' . implode(', ', $parts)];
	}
}
