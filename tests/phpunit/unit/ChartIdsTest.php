<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Build\ChartIds;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Build\ChartIds
 */
class ChartIdsTest extends MediaWikiUnitTestCase {
	/** The hash Chart derives from a chart's own definition, which identifies it and never moves. */
	private const HASH = 'mw-chart-a38dfead1041cee62cf87dad5c0161eb';

	/**
	 * A chart as the renderer answered, numbered from wherever its counters had got to.
	 *
	 * Shortened from a real one, which carries 126 of these across a clipPath, the shapes that
	 * reference it, and a stylesheet naming each class.
	 */
	private function chart(int $instance, int $class): string {
		$id = self::HASH . '__zr' . $instance;
		return (
			"<svg><defs><clipPath id=\"$id-c0\"><rect width=\"270\"/></clipPath></defs>"
			. "<g clip-path=\"url(#$id-c0)\">"
			. "<path class=\"$id-cls-$class\"/><path class=\"$id-cls-"
			. ( $class + 1 )
			. '"/>'
			. "<path class=\"$id-cls-$class\"/></g>"
			. "<style>.$id-cls-$class{fill:none}.$id-cls-"
			. ( $class + 1 )
			. '{fill:#36c}</style></svg>'
		);
	}

	public function testTwoRendersOfOneChartComeOutTheSame(): void {
		// What the bug is: the same chart, drawn by a renderer that had drawn others first.
		$this->assertNotSame($this->chart(4, 36), $this->chart(5, 45), 'the fixtures differ');
		$this->assertSame(
			ChartIds::renumber($this->chart(4, 36)),
			ChartIds::renumber($this->chart(5, 45))
		);
	}

	public function testTheNumbersStartAtZero(): void {
		$this->assertStringContainsString(self::HASH . '__zr0-cls-0', ChartIds::renumber($this->chart(4, 36)));
		$this->assertStringNotContainsString('__zr4', ChartIds::renumber($this->chart(4, 36)));
	}

	public function testOneChartsReferencesStillPointAtIt(): void {
		// The clip path is named once and used once; renaming both or neither is the whole job.
		$renumbered = ChartIds::renumber($this->chart(7, 63));
		$this->assertSame(1, substr_count($renumbered, 'id="' . self::HASH . '__zr0-c0"'));
		$this->assertSame(1, substr_count($renumbered, 'url(#' . self::HASH . '__zr0-c0)'));
	}

	public function testTwoChartsOnAPageKeepTheirOwnNumbers(): void {
		// Distinct charts have distinct hashes, and each is renumbered without reaching the other.
		$page = $this->chart(4, 36) . str_replace(self::HASH, 'mw-chart-0b57', $this->chart(9, 81));
		$renumbered = ChartIds::renumber($page);
		$this->assertStringContainsString(self::HASH . '__zr0-cls-0', $renumbered);
		$this->assertStringContainsString('mw-chart-0b57__zr1-cls-0', $renumbered);
	}

	public function testAPageWithNoChartIsUntouched(): void {
		$page = "<!DOCTYPE html>\n<html><body><p>No chart here, and a __zr in the prose.</p></body></html>\n";
		$this->assertSame($page, ChartIds::renumber($page));
	}
}
