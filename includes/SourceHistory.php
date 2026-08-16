<?php

namespace MediaWiki\Extension\Wikven;

/**
 * When each source file last changed, and who changed it, as the repository's git history tells it.
 *
 * The wiki a bake fills is thrown away afterwards and has no edit history of its own: every page is
 * written in a single import pass, so whatever that pass stamps on the revision is what the reader
 * is told. The file's mtime used to be that stamp, which on a CI runner is the moment the checkout
 * was made -- the same instant for every page, and a different one for every bake of the same
 * commit, which is also the one date SOURCE_DATE_EPOCH cannot freeze (#406).
 *
 * git holds the real answer, and it is the one the "View history" link already sends the reader to:
 * the top entry of that list is the commit this reads. Two ways in, since the history is not always
 * reachable from where the bake runs:
 *
 * - A dump of `git log`, named by $wgWikvenSourceHistoryFile. actions/bake mounts the source
 *   directory alone into the container, with no .git beside it, so it writes this on the runner.
 * - git itself, when the source directory is inside a checkout the build can reach: a standalone
 *   binary run from a repository, or an image run with the repository itself as the source.
 *
 * Neither being available is not an error. The build then dates every page at the commit it is
 * building (SOURCE_DATE_EPOCH) and names no author, which is true of the whole export rather than
 * of each page in it.
 */
class SourceHistory {
	/**
	 * Marks a commit's header in the log, telling it apart from the file names that follow. Any
	 * byte that cannot begin a path will do; git's own porcelain formats use control characters
	 * the same way.
	 */
	private const HEADER = "\x01";

	/**
	 * The log, newest commit first, with each commit as a header record followed by the files it
	 * touched, all NUL-separated so that no path needs quoting. Paths come out relative to the
	 * source directory, and commits touching nothing under it are left out.
	 */
	private const LOG_ARGUMENTS = [
		// Deters git from refusing to read a checkout owned by another user, which is every
		// bind-mounted one: the container runs as root, the files belong to whoever cloned them.
		'-c',
		'safe.directory=*',
		'-c',
		'core.quotePath=false',
		'log',
		'-z',
		'--relative',
		// A rename is a change to the file under its current name, which is the name being asked
		// about; following one back would date the file at a commit that does not mention it.
		'--no-renames',
		'--format=' . self::HEADER . '%ct%x09%an',
		'--name-only',
		'--',
		'.'
	];

	/** @var array<string,array{timestamp:int,author:string}> Keyed by source-relative path. */
	private array $entries;

	/** @param array<string,array{timestamp:int,author:string}> $entries */
	private function __construct(array $entries) {
		$this->entries = $entries;
	}

	/**
	 * Read the history for a source directory: the dump if one was passed in, else git itself.
	 *
	 * @param string $sourceDirectory Directory the build reads pages from.
	 * @param string $logFile A dump of the log, as written by actions/bake; '' for none.
	 */
	public static function forSource(string $sourceDirectory, string $logFile = ''): self {
		if ($logFile !== '' && is_readable($logFile)) {
			$dump = file_get_contents($logFile);
			return self::fromLog($dump === false ? '' : $dump);
		}
		if ($sourceDirectory === '' || !is_dir($sourceDirectory)) {
			return new self([]);
		}
		return self::fromLog(self::readLog($sourceDirectory) ?? '');
	}

	/** Parse a log written with LOG_ARGUMENTS. */
	public static function fromLog(string $log): self {
		$entries = [];
		$timestamp = null;
		$author = '';
		foreach (explode("\0", $log) as $record) {
			// git ends a commit's header with the newline its format asked for, and -z leaves that
			// newline at the front of the first file name rather than dropping it.
			$record = ltrim($record, "\n");
			if ($record === '') {
				continue;
			}

			if (str_starts_with($record, self::HEADER)) {
				[$time, $author] = array_pad(explode("\t", substr($record, strlen(self::HEADER)), 2), 2, '');
				// A commit whose date git could not print is one to skip, not one to date at zero.
				$timestamp = ctype_digit($time) ? (int)$time : null;
				continue;
			}

			// Newest first, so the first commit naming a file is the one that last changed it; a
			// header with no files after it is a merge, which git lists without a diff.
			if ($timestamp !== null && !isset($entries[$record])) {
				$entries[$record] = ['timestamp' => $timestamp, 'author' => $author];
			}
		}
		return new self($entries);
	}

	/** When the file was last changed, in Unix time, or null if the history does not cover it. */
	public function timestamp(string $relativePath): ?int {
		return $this->entries[$relativePath]['timestamp'] ?? null;
	}

	/** Who last changed the file, as git records the author's name, or null if unknown. */
	public function author(string $relativePath): ?string {
		$author = $this->entries[$relativePath]['author'] ?? '';
		return $author === '' ? null : $author;
	}

	/** Run `git log` in the source directory, or null if git has nothing usable to say. */
	private static function readLog(string $sourceDirectory): ?string {
		// A shallow clone holds one commit, so every file would be dated at whenever the last push
		// happened and attributed to whoever made it. That is the very answer this is replacing.
		$shallow = self::git($sourceDirectory, ['rev-parse', '--is-shallow-repository']);
		if ($shallow === null || trim($shallow) !== 'false') {
			return null;
		}
		return self::git($sourceDirectory, self::LOG_ARGUMENTS);
	}

	/**
	 * Run git in a directory and return its output, or null if it is missing or fails there.
	 *
	 * @param string[] $arguments
	 */
	private static function git(string $directory, array $arguments): ?string {
		// An array argv never reaches a shell, so a directory name needs no quoting. git's own
		// complaints (no repository here, no git at all) are the expected outcome rather than
		// something to print, and the exit status already reports them.
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['file', '/dev/null', 'w']
		];
		$pipes = [];
		$process = @proc_open(array_merge(['git', '-C', $directory], $arguments), $descriptors, $pipes);
		if (!is_resource($process)) {
			return null;
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return proc_close($process) === 0 && $output !== false ? $output : null;
	}
}
