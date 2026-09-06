<?php

namespace MediaWiki\Extension\Wikven\Comments;

/**
 * How much prose each kind of comment is allowed, and how much the tree is allowed altogether.
 *
 * The ceilings are per comment because that is where the drift was: the count was already normal
 * for a MediaWiki tree and only the length was out. Each is about twice core's ninetieth percentile
 * for its kind, so a constraint that needs a paragraph still gets one.
 *
 * The ratio is the second gate: a hundred comments just under a ceiling add up to the same essay.
 */
class Budget {
	/** @var array<string,int> Words of prose allowed in one comment of each kind. */
	private const CEILINGS = [
		Comment::FILE => 150,
		Comment::TYPE => 90,
		Comment::MEMBER => 60,
		Comment::NOTE => 60
	];

	/** @var float Words of prose per line of code, over everything read. Core's includes/ runs 2.1. */
	private const RATIO = 4.5;

	/** @var Comment[] */
	public array $overrun = [];

	public int $words = 0;

	public int $code = 0;

	public int $comments = 0;

	public function add(Reader $reader): void {
		$this->code += $reader->code;
		foreach ($reader->comments as $comment) {
			$this->comments++;
			$this->words += $comment->words;
			if ($comment->words > self::ceiling($comment->kind)) {
				$this->overrun[] = $comment;
			}
		}
	}

	public static function ceiling(string $kind): int {
		return self::CEILINGS[$kind];
	}

	public function ratio(): float {
		return $this->code > 0 ? $this->words / $this->code : 0.0;
	}

	public function ratioOverrun(): bool {
		return $this->ratio() > self::RATIO;
	}

	public static function ratioCeiling(): float {
		return self::RATIO;
	}
}
