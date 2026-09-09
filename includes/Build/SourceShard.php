<?php

namespace MediaWiki\Extension\Wikven\Build;

/**
 * Which share of a list of files one of several parallel readers takes.
 *
 * Written "i/n" on a command line, so a shard that cannot be read as one is the whole list rather
 * than none of it: a reader that took nothing would leave the work to be done serially later.
 */
class SourceShard {
	/**
	 * The share "i/n" names, as [index, count].
	 *
	 * @param string $shard "i/n", where 0 <= i < n.
	 * @return array{0:int,1:int} The whole list, [0, 1], where $shard is not a share of one.
	 */
	public static function of(string $shard): array {
		if (!preg_match('~^(\d+)/(\d+)$~', trim($shard), $parts)) {
			return [0, 1];
		}
		$mine = (int)$parts[1];
		$of = (int)$parts[2];
		if ($of < 1 || $mine >= $of) {
			return [0, 1];
		}
		return [$mine, $of];
	}
}
