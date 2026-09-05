<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a skin pass says when it has finished, and how the build reads that back.
 *
 * The build renders each skin in a process of its own and asks it the usual question -- what did
 * you exit with. That answer cannot be trusted here. An uncaught PHP Error goes past
 * MaintenanceRunner's catch, which takes Exception rather than Throwable, and reaches MediaWiki's
 * handler; its guard against claiming success on the way out is a shutdown function calling
 * exit(255), and the standalone binary drops an exit code set from a shutdown function. So a pass
 * that died halfway through rendering returned 0, and a build reported success over an export
 * holding 15 of its 222 pages -- every page in it perfectly good, which is why nothing downstream
 * had anything to say.
 *
 * A line a pass can only write by reaching the end of its own work does not have that problem,
 * whatever killed the pass and whatever it managed to return. Both sides of it are here, so the
 * sentence a pass writes and the sentence the build looks for cannot drift apart.
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
}
