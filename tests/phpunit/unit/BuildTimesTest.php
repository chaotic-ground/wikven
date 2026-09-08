<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Build\BuildTimes;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Build\BuildTimes
 */
class BuildTimesTest extends MediaWikiUnitTestCase {
	/**
	 * The report is read to find where a build went quiet, so the phases stay in the order they
	 * ran and the numbers line up under each other.
	 */
	public function testThePhasesAreReportedInTheOrderTheyRan() {
		$times = new BuildTimes();
		$times->add('import the pages', 26.04);
		$times->add('write the licenses page', 88.2);
		$times->add('run the jobs', 4.5);

		$this->assertSame(
			"Wikven: the build took 118.7s, spent as\n"
			. "  26.0s  import the pages\n"
			. "  88.2s  write the licenses page\n"
			. "   4.5s  run the jobs\n",
			$times->report('the build')
		);
	}

	/** A phase that ran twice is one line holding both, which is what a reader wants of a loop. */
	public function testAPhaseNamedTwiceAddsUp() {
		$times = new BuildTimes();
		$times->add('render a skin', 1.5);
		$times->add('render a skin', 2.25);

		$this->assertStringContainsString("3.8s  render a skin\n", $times->report('the pass'));
		$this->assertStringContainsString('the pass took 3.8s', $times->report('the pass'));
	}

	/** A build that recorded nothing prints nothing, so the caller needs no test of its own. */
	public function testNoPhasesIsNoReport() {
		$this->assertSame('', ( new BuildTimes() )->report('the build'));
	}
}
