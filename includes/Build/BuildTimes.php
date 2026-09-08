<?php

namespace MediaWiki\Extension\Wikven\Build;

/**
 * What each phase of a build took, and the report the build prints of it when it is over.
 *
 * The clock stays with the caller: this is handed durations, so it answers the same in a test as
 * in a bake.
 */
class BuildTimes {
	/** @var array<string,float> Seconds, by phase, in the order the phases first ran. */
	private array $phases = [];

	/** Remember what a phase took. A phase named twice adds up, having run twice. */
	public function add(string $phase, float $seconds): void {
		$this->phases[$phase] = ( $this->phases[$phase] ?? 0.0 ) + $seconds;
	}

	/**
	 * Every phase in the order it ran, under what the whole of $what took.
	 *
	 * In that order rather than longest first: this is read to find where a build went quiet, and
	 * that is a place in the sequence.
	 *
	 * @param string $what What the phases add up to, e.g. "the build" or "the minerva pass".
	 * @return string Empty where no phase ran, so a caller can print it unconditionally.
	 */
	public function report(string $what): string {
		if ($this->phases === []) {
			return '';
		}
		$times = array_map([self::class, 'seconds'], $this->phases);
		$width = max(array_map('strlen', $times));
		$lines = ['Wikven: ' . $what . ' took ' . self::seconds(array_sum($this->phases)) . ', spent as'];
		foreach ($times as $phase => $seconds) {
			$lines[] = '  ' . str_pad($seconds, $width, ' ', STR_PAD_LEFT) . "  $phase";
		}
		return implode("\n", $lines) . "\n";
	}

	/** One duration, to the tenth of a second a phase is worth reading to. */
	public static function seconds(float $seconds): string {
		return number_format($seconds, 1) . 's';
	}
}
