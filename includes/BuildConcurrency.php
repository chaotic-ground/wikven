<?php

namespace MediaWiki\Extension\Wikven;

/**
 * How many skin passes a bake runs beside each other.
 *
 * A pass is a MediaWiki boot rendering every page, so the useful number is one per processor: more
 * than that only has them wait for each other, with a boot's worth of memory each. The processors
 * that count are the ones this process may use, which under a CPU limit are fewer than the ones
 * /proc/cpuinfo lists -- a bake usually runs in a container on a much larger host.
 */
class BuildConcurrency {
	/**
	 * @param int $passes How many passes there are to run.
	 * @param string $configured WIKVEN_BUILD_JOBS, or "" when it is not set. A number here is the
	 *   answer, whatever the machine looks like.
	 * @param string $cpuinfo The contents of /proc/cpuinfo, or "" when it cannot be read.
	 * @param string $cpuMax The contents of the cgroup's cpu.max, or "" when there is none.
	 */
	public static function limit(int $passes, string $configured, string $cpuinfo, string $cpuMax): int {
		if (preg_match('/^[1-9]\d*$/', trim($configured))) {
			return min((int)trim($configured), max($passes, 1));
		}
		return min(self::processors($cpuinfo, $cpuMax), max($passes, 1));
	}

	/** The processors this process may use, by the two accounts of it a container has. */
	public static function processors(string $cpuinfo, string $cpuMax): int {
		$listed = max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));
		$allowed = self::cpuLimit($cpuMax);
		return $allowed > 0 ? min($listed, $allowed) : $listed;
	}

	/**
	 * The CPU allowance of a cgroup v2 file, in whole processors, or 0 when it grants no limit.
	 *
	 * The file reads "<quota> <period>" in microseconds, or "max <period>" for a container that
	 * was given no limit at all. Half a processor is rounded up rather than down to zero: it is
	 * still a processor's worth of passes, run slowly.
	 */
	private static function cpuLimit(string $cpuMax): int {
		$fields = preg_split('/\s+/', trim($cpuMax));
		if (count($fields) !== 2 || !ctype_digit($fields[0]) || !ctype_digit($fields[1]) || (int)$fields[1] === 0) {
			return 0;
		}
		return max(1, (int)ceil((int)$fields[0] / (int)$fields[1]));
	}
}
