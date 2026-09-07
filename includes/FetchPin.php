<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a fetched extension or skin was fetched from, written next to it so the next build can tell.
 *
 * fetchExtensions skips any directory already there, which is wrong where the tree came from
 * somewhere else. So a fetched tree carries a line naming what fetched it, and the next build
 * compares; a tree with no line is left as found.
 */
class FetchPin {
	/**
	 * The file a fetched tree carries, inside the tree so it cannot outlive it.
	 *
	 * Named for what it is rather than hidden in a state directory: someone reading the extension
	 * folder should find the answer in it.
	 */
	public const FILE = '.wikven-fetch.json';

	/** The keys that decide what is fetched. Anything else in a spec cannot change the bytes. */
	private const KEYS = ['tarball', 'repository', 'reference', 'commit', 'sha256'];

	/**
	 * What a source spec fetches, as one line: the same source is the same line.
	 *
	 * Keys and values rather than JSON, because the first thing anyone does with such a file is
	 * read it.
	 *
	 * @param array $spec One WikvenRepositories entry.
	 */
	public static function of(array $spec): string {
		$pin = [];
		foreach (self::KEYS as $key) {
			$value = trim((string)( $spec[$key] ?? '' ));
			if ($value === '') {
				continue;
			}
			$pin[$key] = in_array($key, ['commit', 'sha256'], true) ? strtolower($value) : $value;
		}
		// Not a source, but it is the difference between a clone and a clone with its dependencies
		// installed, which is a different tree on disk.
		$pin['composer'] = empty($spec['composer']) ? '0' : '1';
		ksort($pin);
		$line = [];
		foreach ($pin as $key => $value) {
			$line[] = "$key=$value";
		}
		return implode(' ', $line);
	}

	/**
	 * What fetched $directory: the source, and the commit it landed on where there was one.
	 *
	 * Null where nothing wikven fetched put the tree there, an unreadable stamp included.
	 *
	 * @return array{source:string,commit:?string}|null
	 */
	public static function inside(string $directory): ?array {
		$file = $directory . '/' . self::FILE;
		if (!is_file($file) || !is_readable($file)) {
			return null;
		}
		$stamp = json_decode(trim((string)file_get_contents($file)), true);
		if (!is_array($stamp) || !isset($stamp['source']) || !is_string($stamp['source'])) {
			return null;
		}
		$commit = isset($stamp['commit']) && is_string($stamp['commit']) ? $stamp['commit'] : '';
		return ['source' => $stamp['source'], 'commit' => $commit === '' ? null : $commit];
	}

	/** Write what fetched $directory into it, for the next build to compare against. */
	public static function stamp(string $directory, string $source, ?string $commit = null): bool {
		$stamp = json_encode(
			['source' => $source, 'commit' => $commit],
			JSON_UNESCAPED_SLASHES
		);
		return file_put_contents($directory . '/' . self::FILE, $stamp . "\n") !== false;
	}

	/**
	 * The commit a `git ls-remote` answer points $reference at, or null if it said nothing.
	 *
	 * An annotated tag is listed twice, the second with a ^{} suffix carrying the commit a checkout
	 * ends up on.
	 */
	public static function pointedAt(string $lsRemote): ?string {
		$found = null;
		foreach (explode("\n", $lsRemote) as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if ($parts === false || count($parts) < 2 || !preg_match('/^[0-9a-f]{40,64}$/i', $parts[0])) {
				continue;
			}
			if (str_ends_with($parts[1], '^{}')) {
				return strtolower($parts[0]);
			}
			$found ??= strtolower($parts[0]);
		}
		return $found;
	}
}
