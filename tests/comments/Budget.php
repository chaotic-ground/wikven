<?php

namespace MediaWiki\Extension\Wikven\Comments;

/**
 * How much prose each kind of comment is allowed, and how much the tree is.
 *
 * The ceilings are per comment because that is where the drift was: the count was normal and only
 * the length was out. Each sits a third above core's ninetieth percentile, and the ratio behind
 * them is what comments just under a ceiling would slip.
 */
class Budget {
	/** @var array<string,int> Words of prose allowed in one comment of each kind. */
	private const CEILINGS = [
		Comment::FILE => 120,
		Comment::TYPE => 60,
		Comment::MEMBER => 40,
		Comment::NOTE => 40
	];

	/** @var float Words of prose per line of code, over everything read. Core's includes/ runs 2.1. */
	private const RATIO = 4.0;

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
