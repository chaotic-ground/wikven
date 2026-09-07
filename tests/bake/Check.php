<?php

namespace MediaWiki\Extension\Wikven\Bake;

/** One fact a finished bake has to be true of, and what it needs in hand to say so. */
class Check {
	/** @var string A short name, which is what an annotation is filed under. */
	public string $name;

	/** @var string What holding is being asserted, written as the report line it becomes. */
	public string $summary;

	/** @var callable(Site):string[] Returns the problems found, as sentences a reader can act on. */
	public $run;

	/**
	 * Input beyond the site itself: the source tree, the bake's logs, a second bake. Given none of
	 * it, the runner reports the check skipped rather than passed.
	 *
	 * @var string[]
	 */
	public array $needs;

	/**
	 * @param string $name
	 * @param string $summary
	 * @param callable(Site):string[] $run
	 * @param string[] $needs
	 */
	public function __construct(string $name, string $summary, callable $run, array $needs = []) {
		$this->name = $name;
		$this->summary = $summary;
		$this->run = $run;
		$this->needs = $needs;
	}
}
