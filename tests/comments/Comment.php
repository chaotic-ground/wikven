<?php

namespace MediaWiki\Extension\Wikven\Comments;

/** One comment in a source file: where it is, what kind of thing it introduces, how long it ran. */
class Comment {
	/** @var string The first docblock in a file, standing in front of the file rather than a declaration. */
	public const FILE = 'file docblock';

	/** @var string A docblock on a class, interface, trait or enum. */
	public const TYPE = 'class docblock';

	/** @var string A docblock on a method, constant or property. */
	public const MEMBER = 'member docblock';

	/** @var string A run of // lines, counted together because that is how it is read. */
	public const NOTE = 'inline note';

	public string $file;

	public string $kind;

	public int $line;

	/** @var int Words of prose, with @param and its neighbours left out. See Reader::words(). */
	public int $words;

	public function __construct(string $file, string $kind, int $line, int $words) {
		$this->file = $file;
		$this->kind = $kind;
		$this->line = $line;
		$this->words = $words;
	}
}
