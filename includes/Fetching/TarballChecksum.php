<?php

namespace MediaWiki\Extension\Wikven\Fetching;

/**
 * The sha256 a WikvenRepositories tarball entry pins, and whether a download is the file it names.
 *
 * A tarball is the one fetch method whose source can change under a site without its configuration
 * changing, so the pin is the only thing between a site and running code nobody chose.
 */
class TarballChecksum {
	/**
	 * The checksum a spec pins, or null where it pins none.
	 *
	 * Null is a pin, not the absence of one: "sha256:" with nothing after it is an unfilled key,
	 * and reading it as "pins nothing" fetched the tarball unverified.
	 *
	 * @param array $spec One WikvenRepositories entry.
	 */
	public static function wanted(array $spec): ?string {
		if (!array_key_exists('sha256', $spec)) {
			return null;
		}
		if ($spec['sha256'] === null) {
			return '';
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
	 * Compared with hash_equals: a comparison that leaks where it stopped is not worth keeping.
	 *
	 * @param string $wanted A checksum isValid() accepts.
	 * @param string $path The downloaded file.
	 */
	public static function matches(string $wanted, string $path): bool {
		$actual = is_file($path) ? hash_file('sha256', $path) : false;
		return $actual !== false && hash_equals($wanted, $actual);
	}
}
