<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a fetched extension or skin was fetched from, written next to it so the next build can tell.
 *
 * A bake fetches what WikvenRepositories names, and a fetch it has already made is one it should
 * not make again: fetchExtensions skips any directory that is already there. That is right where
 * the tree came from the same source, and wrong where it did not -- move a pin to a new tag, build
 * again in a tree that survives (a bind mount, the standalone binary's own install, a container
 * that is not thrown away), and the old checkout stays, silently, with nothing on the build's
 * output to say the new pin was not what got built.
 *
 * So a fetched tree carries a line naming what fetched it, and the next build compares. Same line,
 * nothing to do; different line, the tree goes and the fetch is made again. A tree with no line at
 * all is left exactly as it was found: it is bundled in the image, or somebody put it there, and a
 * build is in no position to decide it knows better.
 *
 * A reference is not a pin, though it is written like one: `reference: main` names whatever the
 * branch points at today, and a tag can be moved. So a git fetch also records the commit it ended
 * up on, and a build that finds the source unchanged asks the remote what that reference points at
 * now -- one round trip, and only where the tree is already there -- and fetches again when the
 * answer has moved. A remote that cannot be reached is not an error: the tree that is there is
 * kept, and the build says why it could not check.
 */
class FetchPin {
	/**
	 * The file a fetched tree carries, inside the tree so it cannot outlive it.
	 *
	 * Named for what it is rather than hidden away in a state directory: someone reading the
	 * extension folder to work out where its code came from should find the answer in it.
	 */
	public const FILE = '.wikven-fetch.json';

	/** The keys that decide what is fetched. Anything else in a spec cannot change the bytes. */
	private const KEYS = ['tarball', 'repository', 'reference', 'commit', 'sha256'];

	/**
	 * What a source spec fetches, as one line: the same source is the same line.
	 *
	 * Keys and values rather than JSON, because the line is written into the tree it fetched and
	 * the first thing anyone does with a file like that is read it.
	 *
	 * The hex pins are lowercased because the validators lowercase them and a spec may not have;
	 * nothing else is touched, since a URL's path is the remote's to be particular about.
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
	 * Null where nothing wikven fetched put the tree there, which includes a file written by a
	 * version of this that has since changed shape: an unreadable stamp is no answer, and the
	 * tree it sits in is somebody else's to keep.
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
	 * An annotated tag is listed twice, as the tag object and again as the commit it wraps with
	 * a ^{} suffix; the commit is the one to keep, since that is what a checkout ends up on.
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
