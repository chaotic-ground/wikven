<?php

namespace MediaWiki\Extension\Wikven\Bake;

/**
 * A baked site and the expectations it is being read against.
 *
 * Nothing here knows it is running on a CI runner, and nothing here loads MediaWiki: a finished
 * bake is a directory of files, and reading one needs no wiki behind it. That is what lets a
 * contributor run the same checks over a bake on their own machine.
 */
class Site {
	/** The directory the bake wrote, without a trailing separator. */
	public string $dist;

	/**
	 * What is this site's rather than wikven's: where it is published, which pages it keeps out of
	 * the index, which skins it builds. Read from a file so the checks hold for any site.
	 *
	 * @var array<string,mixed>
	 */
	public array $expect;

	/**
	 * The source tree the site was baked from. Three checks derive what to expect from it rather
	 * than restating it, so that adding a page or a translation moves the target with it.
	 */
	public ?string $source;

	/**
	 * What the bake said while it ran.
	 *
	 * @var string[]
	 */
	public array $logs;

	/** A second bake of the same source, for the checks that compare the two. */
	public ?string $other;

	/**
	 * Anything a check wants to say about what it found, whether or not it found a problem.
	 *
	 * @var string[]
	 */
	public array $notes = [];

	/**
	 * @param string $dist
	 * @param array<string,mixed> $expect
	 * @param ?string $source
	 * @param string[] $logs
	 * @param ?string $other
	 */
	public function __construct(
		string $dist,
		array $expect,
		?string $source = null,
		array $logs = [],
		?string $other = null
	) {
		$this->dist = rtrim($dist, '/') === '' ? $dist : rtrim($dist, '/');
		$this->expect = $expect;
		$this->source = $source;
		$this->logs = $logs;
		$this->other = $other;
	}

	/** The published base URL, with the single trailing slash the checks all assume. */
	public function base(): string {
		return rtrim((string)$this->expect['site_url'], '/') . '/';
	}

	public function path(string ...$parts): string {
		return implode('/', [$this->dist, ...array_filter($parts, static fn ($part) => $part !== '')]);
	}

	/**
	 * Every file under the bake whose name ends in $suffix, at any depth, in a stable order.
	 *
	 * @return string[]
	 */
	public function filesEndingIn(string $suffix): array {
		$found = [];
		if (!is_dir($this->dist)) {
			return $found;
		}
		$walk = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->dist, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($walk as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			if ($file->isFile() && ( $suffix === '' || str_ends_with($path, $suffix) )) {
				$found[] = $path;
			}
		}
		sort($found, SORT_STRING);
		return $found;
	}

	/** @return string[] */
	public function htmlFiles(): array {
		return $this->filesEndingIn('.html');
	}

	/**
	 * The paths a shell glob would name: one level per "*", so a pattern says how deep it looks.
	 *
	 * @return string[]
	 */
	public function glob(string $pattern): array {
		$found = glob($pattern) ?: [];
		sort($found, SORT_STRING);
		return $found;
	}

	public function read(string $path): string {
		$text = @file_get_contents($path);
		return $text === false ? '' : $text;
	}

	public function note(string $line): void {
		$this->notes[] = $line;
	}

	/** Where a path sits inside the bake, which is how a page is named to a reader. */
	public function relative(string $path): string {
		return substr($path, strlen($this->dist) + 1);
	}
}
