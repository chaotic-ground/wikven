<?php

namespace MediaWiki\Extension\Wikven;

/**
 * The sha256 a WikvenRepositories tarball entry pins, and whether a download is the file it names.
 *
 * Configuration promises this: "64 hex characters; the build aborts on a mismatch", and tells a
 * site to pin one "for a tamper-evident, reproducible build". A tarball is the one fetch method
 * whose source can change under a site without its configuration changing -- a URL serves whatever
 * it serves today -- so the pin is the only thing between a site and running code nobody chose.
 *
 * Kept here rather than inside the maintenance script so the promise can be held to a test: what a
 * spec pins, what counts as a sha256, and whether a file answers to one are three questions with
 * answers that do not need a wiki.
 */
class TarballChecksum {
	/**
	 * The checksum a spec pins, lowercased and trimmed, or null where it pins none.
	 *
	 * Says nothing about whether the value is a checksum; that is isValid()'s question, asked
	 * separately so a spec that pins nonsense is told apart from one that pins nothing.
	 *
	 * @param array $spec One WikvenRepositories entry.
	 */
	public static function wanted(array $spec): ?string {
		if (!isset($spec['sha256'])) {
			return null;
		}
		if (is_array($spec['sha256']) || is_object($spec['sha256'])) {
			return '';
		}
		return strtolower(trim((string)$spec['sha256']));
	}

	/** Whether a value is the 64 hex characters a sha256 is written as. */
	public static function isValid(string $checksum): bool {
		return preg_match('/^[0-9a-f]{64}$/', $checksum) === 1;
	}

	/**
	 * Whether the file at $path is the one $wanted names.
	 *
	 * Compared with hash_equals, which takes the same time whichever character differs. A build
	 * host is not a place anyone is timing, but a comparison that leaks where it stopped is not
	 * worth keeping for the nanoseconds it saves.
	 *
	 * @param string $wanted A checksum isValid() accepts.
	 * @param string $path The downloaded file.
	 */
	public static function matches(string $wanted, string $path): bool {
		$actual = is_file($path) ? hash_file('sha256', $path) : false;
		return $actual !== false && hash_equals($wanted, $actual);
	}
}
